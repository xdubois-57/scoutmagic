<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\MailFailure;
use Core\Mail\Transport\ProviderHealth;
use Core\Mail\Transport\ProviderHealthRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The circuit breaker's own arithmetic (D15). What it does to a LANE is
 * held by MailTransportChainTest; this is the counting and the clock.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ProviderHealthTest extends TestCase
{
    private ProviderHealthRepository $health;

    protected function setUp(): void
    {
        $this->health = new ProviderHealthRepository(DatabaseTestHelper::createTestDatabase());
    }

    /** A provider nobody has had trouble with has no row and no memory. */
    public function testAProviderThatNeverFailedIsClosed(): void
    {
        $health = $this->health->forProvider(7);

        $this->assertSame(0, $health->consecutiveFailures);
        $this->assertFalse($health->isOpen());
    }

    /**
     * One refusal is ordinary. Shutting a provider out on it would make
     * the chain flap on every hiccup.
     */
    public function testOneFailureDoesNotOpenTheCircuit(): void
    {
        $health = $this->health->recordFailure(1, 'Connection refused', '2026-09-13 10:00:00');

        $this->assertSame(1, $health->consecutiveFailures);
        $this->assertFalse($health->isOpen('2026-09-13 10:00:00'));
    }

    public function testTheThresholdOpensItForTheFirstLockout(): void
    {
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $health = $this->health->recordFailure(1, 'SMTP connect() failed', '2026-09-13 10:00:00');
        }

        $this->assertTrue($health->isOpen('2026-09-13 10:00:00'));
        $this->assertSame('2026-09-13 10:05:00', $health->openedUntil);
        $this->assertFalse(
            $health->isOpen('2026-09-13 10:06:00'),
            'A lockout that has run out is closed, with nothing having to write to the row.'
        );
    }

    /**
     * The penalty grows for a relay that keeps coming back broken, and
     * stops growing somewhere a super-admin can live with.
     */
    public function testTheLockoutDoublesAndIsCapped(): void
    {
        $minutes = [];
        $now = strtotime('2026-09-13 10:00:00');

        for ($round = 0; $round < 8; $round++) {
            $at = date('Y-m-d H:i:s', $now);
            for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
                $health = $this->health->recordFailure(1, 'Could not authenticate', $at);
            }
            $minutes[] = (int) round((strtotime((string) $health->openedUntil) - $now) / 60);
            // Past the lockout, and failing again.
            $now = strtotime((string) $health->openedUntil) + 60;
            $this->health->recordSuccess(1, date('Y-m-d H:i:s', $now));
        }

        $this->assertSame([5, 10, 20, 40, 80, 160, 240, 240], $minutes);
        $this->assertSame(ProviderHealth::MAX_MINUTES, $minutes[7]);
    }

    /** A relay that answers is a relay that works, whatever it did before. */
    public function testTheFirstSuccessClosesItAndForgetsTheCount(): void
    {
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->health->recordFailure(1, 'Connection timed out', '2026-09-13 10:00:00');
        }

        $closed = $this->health->recordSuccess(1, '2026-09-13 10:10:00');

        $this->assertTrue($closed, 'Closing an OPEN circuit is the half worth a journal line.');
        $health = $this->health->forProvider(1);
        $this->assertSame(0, $health->consecutiveFailures);
        $this->assertNull($health->openedUntil);
    }

    /**
     * The ordinary success of a healthy relay writes nothing — otherwise
     * every message that leaves would be an UPDATE saying what the row
     * already said.
     */
    public function testASuccessOnAHealthyProviderIsNotNews(): void
    {
        $this->assertFalse($this->health->recordSuccess(1, '2026-09-13 10:00:00'));
    }

    /**
     * `open_count` survives a success on purpose: it is the memory of how
     * often this relay has come back broken. Zeroing it would give a
     * provider that fails every ten minutes the same five-minute penalty
     * for ever.
     */
    public function testTheNextLockoutRemembersHowOftenItHasReopened(): void
    {
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->health->recordFailure(1, 'TLS handshake failed', '2026-09-13 10:00:00');
        }
        $this->health->recordSuccess(1, '2026-09-13 10:06:00');

        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $health = $this->health->recordFailure(1, 'TLS handshake failed', '2026-09-13 11:00:00');
        }

        $this->assertSame(
            '2026-09-13 11:10:00',
            $health->openedUntil,
            'The second lockout is ten minutes, not five.'
        );
    }

    /**
     * Failing again during a lockout — on the one attempt the « never
     * empty a lane » rule allows — must not extend it.
     */
    public function testFailingWhileOpenDoesNotStackAnotherLockout(): void
    {
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $opened = $this->health->recordFailure(1, 'Connection refused', '2026-09-13 10:00:00');
        }

        $again = $this->health->recordFailure(1, 'Connection refused', '2026-09-13 10:02:00');

        $this->assertSame($opened->openedUntil, $again->openedUntil);
        $this->assertSame(1, $again->openCount);
    }

    /**
     * The distinction the whole breaker rests on. A recipient rejection
     * never reaches `recordFailure()` — the chain filters it — and the
     * classifier is what makes that filtering right.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAFailureIsClassifiedByWhoseFaultItWas(string $reason, MailFailure $expected): void
    {
        $this->assertSame($expected, MailFailure::classify($reason));
    }

    /**
     * @return array<string, array{string, MailFailure}>
     */
    public static function failures(): array
    {
        return [
            'a rejected recipient' => ['SMTP Error: 550 5.1.1 Recipient address rejected', MailFailure::Recipient],
            'an unknown user' => ['553 User unknown', MailFailure::Recipient],
            'a mailbox that is gone' => ['550 Mailbox unavailable', MailFailure::Recipient],
            'PHPMailer refusing an empty list' => [
                'You must provide at least one recipient',
                MailFailure::Recipient,
            ],
            'a relay asking us to slow down' => ['421 4.7.0 Too many messages', MailFailure::Provider],
            'a refused connection' => ['SMTP connect() failed', MailFailure::Provider],
            'refused credentials' => ['SMTP Error: Could not authenticate', MailFailure::Provider],
            'a TLS failure' => ['Could not start TLS', MailFailure::Provider],
            // The direction that matters: an unrecognised reason is the
            // provider's, because the other way round retries a dead relay
            // without end.
            'something nobody has seen before' => ['kaboom', MailFailure::Provider],
        ];
    }
}
