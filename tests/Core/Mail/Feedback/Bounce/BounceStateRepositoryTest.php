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

    public function testAFirstPermanentBounceCountsOnce(): void
    {
        $state = $this->states->record(
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
        foreach (range(1, 5) as $ignored) {
            $state = $this->states->record(
                'parent@exemple.be',
                BounceCategory::MailboxFull,
                BounceSeverity::Transient,
                '4.2.2',
                $this->now()
            );
        }

        $this->assertSame(0, $state->failures);
        $this->assertSame(BounceCategory::MailboxFull, $state->category);
        $this->assertSame(1, $this->countRows(), 'still one row: a bounce is a fact about the address.');
    }

    public function testPermanentBouncesAccumulateOnTheSameRow(): void
    {
        foreach (range(1, 3) as $ignored) {
            $state = $this->states->record(
                'parent@exemple.be',
                BounceCategory::NoSuchAddress,
                BounceSeverity::Permanent,
                '5.1.1',
                $this->now()
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
        $this->states->record('parent@exemple.be', BounceCategory::Refused, BounceSeverity::Permanent, '5.7.1', $this->now());
        $this->states->record('PARENT@Exemple.BE', BounceCategory::Refused, BounceSeverity::Permanent, '5.7.1', $this->now());

        $this->assertSame(1, $this->countRows(), 'the address is matched case-insensitively.');
        $this->assertSame(2, $this->states->find('parent@exemple.be')?->failures);
    }

    public function testBlockingIsRecordedWithItsDate(): void
    {
        $state = $this->states->record('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
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
        $state = $this->states->record('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
        $this->states->record('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
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
        $state = $this->states->record('parent@exemple.be', BounceCategory::MailboxFull, BounceSeverity::Transient, '4.2.2', $this->now());
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
        $state = $this->states->record('parent@exemple.be', BounceCategory::MailboxFull, BounceSeverity::Permanent, '5.2.2', $this->now());
        $this->states->block($state->id, $this->now());

        $this->states->forget('parent@exemple.be');

        $this->assertNull($this->states->find('parent@exemple.be'));
        $this->assertSame(0, $this->countRows(), 'a row recording nothing is a row nobody needs.');
    }

    public function testForgettingAnAddressThatNeverBouncedIsHarmless(): void
    {
        $this->states->forget('jamais@exemple.be');

        $this->assertSame(0, $this->countRows());
    }

    public function testOnlyBlockedAddressesAreListedForTheSuperAdmin(): void
    {
        $blocked = $this->states->record('bloquee@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());
        $this->states->record('surveillee@exemple.be', BounceCategory::MailboxFull, BounceSeverity::Transient, '4.2.2', $this->now());
        $this->states->block($blocked->id, $this->now());

        $listed = $this->states->blocked();

        $this->assertCount(1, $listed);
        $this->assertSame('bloquee@exemple.be', $listed[0]->email);
    }

    public function testTheStoredAddressIsNotReadableInTheTable(): void
    {
        $this->states->record('parent@exemple.be', BounceCategory::NoSuchAddress, BounceSeverity::Permanent, '5.1.1', $this->now());

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
