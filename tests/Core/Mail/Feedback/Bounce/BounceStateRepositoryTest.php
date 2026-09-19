<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Feedback\Bounce\BounceSeverity;
use Core\Mail\Feedback\Bounce\BounceStateRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One row per address, and the counting rules around it (roadmap IT-05).
 *
 * @group database
 */
#[Group('database')]
class BounceStateRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BounceStateRepository $states;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->states = new BounceStateRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    private function now(string $when = '2026-09-14 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    /**
     * **One message out, then one bounce back in** — the send belongs to
     * the fixture, not beside it.
     *
     * A report counts only when a message has gone out since the last
     * report that counted, so stamping the receipt once and then
     * recording two bounces would model a sequence the site cannot
     * produce. It is also the shape that let one forged message be
     * counted as two strikes: two blank-line-separated groups naming the
     * same address, or the same message read twice, which
     * `MailboxSyncService` expects and hands to `analyze()` before its
     * Message-ID check.
     */
    private function bounce(
        string $email,
        BounceCategory $category,
        BounceSeverity $severity,
        string $code,
        \DateTimeImmutable $now
    ): \Core\Mail\Feedback\Bounce\BounceState {
        $this->states->recordSend($email, $now->modify('-1 minute'));

        $state = $this->states->record($email, $category, $severity, $code, $now);
        self::assertNotNull($state, 'the fixture must produce a recorded bounce.');

        return $state;
    }

    public function testAFirstPermanentBounceCountsOnce(): void
    {
        $state = $this->bounce(
            'parent@exemple.be',
            BounceCategory::NoSuchAddress,
            BounceSeverity::Permanent,
            '5.1.1',
            $this->now()
        );

        $this->assertSame(1, $state->failures);
        $this->assertSame('parent@exemple.be', $state->email);
        $this->assertFalse($state->isBlocked());
        $this->assertSame(1, $this->countRows());
    }

    /**
     * **The asymmetry that is the whole of « un temporaire est
     * réessayé ».** A transient bounce updates what is known and moves the
     * address no closer to being blocked, however often it repeats.
     */
    public function testATransientBounceNeverCountsTowardTheBlock(): void
    {
        foreach (range(1, 5) as $round) {
            $state = $this->bounce(
                'parent@exemple.be',
                BounceCategory::MailboxFull,
                BounceSeverity::Transient,
                '4.2.2',
                $this->now()->modify("+{$round} hours")
            );
        }

        $this->assertSame(0, $state->failures);
        $this->assertSame(BounceCategory::MailboxFull, $state->category);
        $this->assertSame(1, $this->countRows(), 'still one row: a bounce is a fact about the address.');
    }

    public function testPermanentBouncesAccumulateOnTheSameRow(): void
    {
        foreach (range(1, 3) as $round) {
            $state = $this->bounce(
                'parent@exemple.be',
                BounceCategory::NoSuchAddress,
                BounceSeverity::Permanent,
                '5.1.1',
                $this->now()->modify("+{$round} hours")
            );
        }

        $this->assertSame(3, $state->failures);
        $this->assertSame(1, $this->countRows());
    }

    /**
     * The same mailbox reached through two children's profiles is one
     * mailbox. Recording per profile would let « bloquée » be true on one
     * screen and false on another, for one address.
     */
    public function testOneMailboxIsOneRowHoweverManyProfilesShareIt(): void
    {
        $this->bounce('parent@exemple.be', BounceCategory::Refused, BounceSeverity::Permanent, '5.7.1', $this->now());
        // An hour later, and after a second message went out to the same
        // mailbox: two strikes are two answers to two questions.
        $this->bounce(
            'PARENT@Exemple.BE',
            BounceCategory::Refused,
            BounceSeverity::Permanent,
            '5.7.1',
            $this->now()->modify('+1 hour')
        );

        $this->assertSame(1, $this->countRows(), 'the address is matched case-insensitively.');
        $this->assertSame(2, $this->states->find('parent@exemple.be')?->failures);
    }

    public function testBlockingIsRecordedWithItsDate(): void
    {
        $state = $this->bounce('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
        $this->states->block($state->id, $this->now('2026-09-14 11:00:00'));

        $blocked = $this->states->find('parent@exemple.be');
        $this->assertTrue($blocked?->isBlocked());
        $this->assertSame('2026-09-14 11:00', $blocked?->blockedAt?->format('Y-m-d H:i'));
        $this->assertSame(1, $this->states->countBlocked());
    }

    /**
     * **Unblocking without resetting would buy the member one message.**
     * The very next bounce would re-block, because the counter would still
     * be at the threshold. Both happen together or the gesture is a lie.
     */
    public function testUnblockingAlsoPutsTheCounterBackToZero(): void
    {
        $state = $this->bounce('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
        $this->bounce(
            'parent@exemple.be',
            BounceCategory::NoSuchAddress,
            BounceSeverity::Permanent,
            '5.1.1',
            $this->now()->modify('+1 hour')
        );
        $this->states->markNotified($state->id, '5.1.1');
        $this->states->block($state->id, $this->now());

        $this->states->unblock($state->id);

        $after = $this->states->find('parent@exemple.be');
        $this->assertFalse($after?->isBlocked());
        $this->assertSame(0, $after?->failures);
        $this->assertNull($after?->notifiedCode, 'an error just acted on is news again if it returns.');
    }

    /**
     * « Première fois pour cette erreur », which is what makes the
     * transient notification bearable: a full mailbox bounces at every
     * single mailing.
     */
    public function testAnErrorIsOnlyNewsOnce(): void
    {
        $state = $this->bounce('parent@exemple.be', BounceCategory::MailboxFull, BounceSeverity::Transient, '4.2.2', $this->now());
        $this->assertTrue($state->isNewError('4.2.2'));

        $this->states->markNotified($state->id, '4.2.2');
        $again = $this->states->find('parent@exemple.be');

        $this->assertFalse($again?->isNewError('4.2.2'));
        $this->assertTrue($again?->isNewError('5.1.1'), 'a different failure is a different thing to tell somebody.');
    }

    /**
     * A message got through. Not a timer — a mailbox is emptied when its
     * owner gets round to it, and any delay short enough to be useful
     * would unblock addresses that are still broken.
     */
    public function testASuccessfulSendForgetsTheAddressEntirely(): void
    {
        $state = $this->bounce('parent@exemple.be', BounceCategory::MailboxFull, BounceSeverity::Permanent, '5.2.2', $this->now());
        $this->states->block($state->id, $this->now());

        $this->states->forget('parent@exemple.be');

        $this->assertNull($this->states->find('parent@exemple.be'));
        $this->assertSame(0, $this->countRows(), 'a row recording nothing is a row nobody needs.');
    }

    /**
     * **A send is clean only if it is more recent than the last bounce**,
     * and this is the case that separates that from « there was a send at
     * some point ». Here a bounce lands AFTER a stamped send, so the next
     * send must settle nothing: the address is still failing, and the
     * count it has built up is the whole basis for blocking it.
     *
     * Without the comparison, this send would wipe a count of two and the
     * address would start again from nothing at every mailing.
     */
    public function testASendThatArrivedBeforeTheLastBounceSettlesNothing(): void
    {
        $t = $this->now('2026-09-15 09:00:00');

        // Deliberately NOT through bounce(): this test drives the
        // timeline itself, and the helper's own send would be an extra
        // event in the middle of the sequence being measured.
        $this->states->recordSend('parent@exemple.be', $t);
        $this->states->record(
            'parent@exemple.be',
            BounceCategory::NoSuchAddress,
            BounceSeverity::Permanent,
            '5.1.1',
            $t->modify('+1 minute')
        );

        $this->states->recordSend('parent@exemple.be', $t->modify('+1 day'));
        $this->states->record(
            'parent@exemple.be',
            BounceCategory::NoSuchAddress,
            BounceSeverity::Permanent,
            '5.1.1',
            $t->modify('+1 day +1 minute')
        );

        $this->states->recordSend('parent@exemple.be', $t->modify('+3 days'));

        $state = $this->states->find('parent@exemple.be');
        $this->assertNotNull($state, 'an address that is still failing must not be forgotten.');
        $this->assertSame(2, $state->failures);
    }

    /**
     * **A send never lifts a block.** A blocked address should not be
     * written to at all, but the module's own list-address path can still
     * reach one — and a clean-looking send deleting the row would be an
     * automatic unblock nobody asked for. Lifting a block belongs to the
     * member or the super-admin (D19), and to nobody else.
     */
    public function testACleanSendNeverLiftsABlock(): void
    {
        $t = $this->now('2026-09-19 09:00:00');

        $state = $this->bounce('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $t);
        $this->states->block($state->id, $t);

        // Two clean-looking sends: the second would settle the first.
        $this->states->recordSend('parent@exemple.be', $t->modify('+1 day'));
        $this->states->recordSend('parent@exemple.be', $t->modify('+2 days'));

        $after = $this->states->find('parent@exemple.be');
        $this->assertNotNull($after, 'the block must survive a send.');
        $this->assertTrue($after->isBlocked());
    }

    /**
     * A report about a message this site cannot show it sent records
     * nothing at all — the boundary that stops a forged bounce from
     * suspending anybody's address.
     */
    public function testABounceForAnAddressWeNeverWroteToIsRefused(): void
    {
        $refused = $this->states->record(
            'jamais-ecrit@exemple.be',
            BounceCategory::NoSuchAddress,
            BounceSeverity::Permanent,
            '5.1.1',
            $this->now()
        );

        $this->assertNull($refused);
        $this->assertSame(0, $this->countRows());
    }

    public function testASendIsRememberedEvenWhenNothingBounces(): void
    {
        $this->states->recordSend('bonne@exemple.be', $this->now());

        $this->assertNotNull($this->states->lastSendAt('bonne@exemple.be'));
        $this->assertSame(0, $this->countRows(), 'a receipt is not a bounce.');
    }

    public function testForgettingAnAddressThatNeverBouncedIsHarmless(): void
    {
        $this->states->forget('jamais@exemple.be');

        $this->assertSame(0, $this->countRows());
    }

    public function testOnlyBlockedAddressesAreListedForTheSuperAdmin(): void
    {
        $blocked = $this->bounce('bloquee@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
        $this->bounce('surveillee@exemple.be', BounceCategory::MailboxFull, BounceSeverity::Transient, '4.2.2', $this->now());
        $this->states->block($blocked->id, $this->now());

        $listed = $this->states->blocked();

        $this->assertCount(1, $listed);
        $this->assertSame('bloquee@exemple.be', $listed[0]->email);
    }

    public function testTheStoredAddressIsNotReadableInTheTable(): void
    {
        $this->bounce('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());

        $statement = $this->pdo->query('SELECT email_encrypted FROM mail_bounce_states');
        $this->assertNotFalse($statement);

        $this->assertStringNotContainsString(
            'parent@exemple.be',
            (string) $statement->fetchColumn(),
            'the address is personal data and is stored encrypted.'
        );
    }

    private function countRows(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_bounce_states');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
