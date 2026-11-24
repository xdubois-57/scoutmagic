<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Attention\AttentionPoint;
use Core\Journal\JournalRepository;
use Core\Security\AuthService;
use Core\Security\LoginMailAttentionProvider;
use Core\Security\PasswordResetService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The administrators learn that sign-in e-mails are failing from the journal
 * lines the two services write — the visitor never does (issue #1267).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class LoginMailAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private LoginMailAttentionProvider $provider;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->provider = new LoginMailAttentionProvider(new JournalRepository($this->pdo));
    }

    private function logEvent(string $type, string $loggedAt): void
    {
        $this->pdo->prepare(
            "INSERT INTO event_log (logged_at, category, event_type, level, description) VALUES (?, 'core', ?, 'info', 'x')"
        )->execute([$loggedAt, $type]);
    }

    public function testNothingIsShownWhileNothingFailed(): void
    {
        $this->logEvent('magic_link_email_sent', date('Y-m-d H:i:s'));
        $this->logEvent('magic_link_requested', date('Y-m-d H:i:s'));

        $this->assertSame([], $this->provider->collect(1));
    }

    public function testAFailedSignInLinkIsShown(): void
    {
        $this->logEvent(AuthService::EVENT_SEND_FAILED, date('Y-m-d H:i:s'));

        $points = $this->provider->collect(1);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('1 échec', $points[0]->title);
        $this->assertStringNotContainsString('échecs', $points[0]->title);
        $this->assertSame('/config/courrier-sortant', $points[0]->actionUrl);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    public function testBothKindsOfMailAreCountedTogether(): void
    {
        $this->logEvent(AuthService::EVENT_SEND_FAILED, date('Y-m-d H:i:s'));
        $this->logEvent(PasswordResetService::EVENT_SEND_FAILED, date('Y-m-d H:i:s'));
        $this->logEvent(PasswordResetService::EVENT_SEND_FAILED, date('Y-m-d H:i:s'));

        $points = $this->provider->collect(1);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('3 échecs', $points[0]->title);
    }

    public function testAFailureOlderThanADayNoLongerCounts(): void
    {
        $this->logEvent(AuthService::EVENT_SEND_FAILED, date('Y-m-d H:i:s', time() - 25 * 3600));

        $this->assertSame([], $this->provider->collect(1));
    }

    public function testTheScoutYearMakesNoDifference(): void
    {
        $this->logEvent(AuthService::EVENT_SEND_FAILED, date('Y-m-d H:i:s'));

        $this->assertCount(1, $this->provider->collect(0));
        $this->assertCount(1, $this->provider->collect(99));
    }

    public function testNeitherTheAddressNorTheReasonIsShown(): void
    {
        $this->pdo->prepare(
            "INSERT INTO event_log (logged_at, category, event_type, level, description, context)
             VALUES (?, 'core', ?, 'info', 'x', ?)"
        )->execute([date('Y-m-d H:i:s'), AuthService::EVENT_SEND_FAILED, '{"error":"SMTP 550 relay denied"}']);

        $point = $this->provider->collect(1)[0];

        $this->assertStringNotContainsString('SMTP', $point->title . $point->why);
        $this->assertStringNotContainsString('@', $point->title . $point->why);
    }
}
