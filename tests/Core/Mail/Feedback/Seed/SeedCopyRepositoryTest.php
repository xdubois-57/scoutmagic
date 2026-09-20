<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Mail\Feedback\Seed\SeedCopy;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Feedback\Seed\SeedVerdict;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One row per (mailing run × seed mailbox), and what that pair buys
 * (roadmap IT-07).
 *
 * @group database
 */
#[Group('database')]
class SeedCopyRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SeedCopyRepository $copies;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->copies = new SeedCopyRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testAClaimedCopyIsWrittenDownWithItsProvider(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');

        $this->assertTrue($this->copies->claim('envoi-1', 'temoin@gmail.com', $now));

        $forRun = $this->copies->forRun('envoi-1');
        $this->assertCount(1, $forRun);
        $this->assertSame('gmail.com', $forRun[0]->provider);
        $this->assertSame('temoin@gmail.com', $forRun[0]->address);
        $this->assertTrue($forRun[0]->isPending());
    }

    /**
     * **The claim is what stops a second copy**, and it is the unique
     * index that does it rather than a lookup. A mailing goes out in
     * batches over hours, a batch can be retried, and a run the scheduler
     * picks up twice must not put a second message in the same box: that
     * would double the measurement's denominator without saying so.
     */
    public function testTheSameBoxIsNotWrittenToTwiceForOneRun(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');

        $this->assertTrue($this->copies->claim('envoi-1', 'temoin@gmail.com', $now));
        $this->assertFalse($this->copies->claim('envoi-1', 'temoin@gmail.com', $now->modify('+2 hours')));

        $this->assertCount(1, $this->copies->forRun('envoi-1'));
    }

    /** But the next run writes to it again — that is a new measurement. */
    public function testAnotherRunWritesToTheSameBoxAgain(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');

        $this->assertTrue($this->copies->claim('envoi-1', 'temoin@gmail.com', $now));
        $this->assertTrue($this->copies->claim('envoi-2', 'temoin@gmail.com', $now->modify('+1 day')));

        $this->assertCount(1, $this->copies->forRun('envoi-1'));
        $this->assertCount(1, $this->copies->forRun('envoi-2'));
    }

    /**
     * **A failure that is not the duplicate must reach the caller.**
     *
     * IT-06 shipped the opposite twice — a database refusing to write
     * answering « we already had it » — and a seed copy claimed but never
     * recorded would leave the measurement silently short of one box.
     */
    public function testAWriteFailureThatIsNotADuplicateIsNotSwallowed(): void
    {
        $this->pdo->prepare('DROP TABLE mail_seed_copies')->execute();

        $this->expectException(\PDOException::class);

        $this->copies->claim('envoi-1', 'temoin@gmail.com', new \DateTimeImmutable());
    }

    public function testALandingInTheInboxIsRecordedAsSuch(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('envoi-1', 'temoin@gmail.com', $now);

        $this->assertTrue(
            $this->copies->recordLanding('envoi-1', 'temoin@gmail.com', 'INBOX', $now->modify('+5 minutes'))
        );

        $copy = $this->copies->forRun('envoi-1')[0];
        $this->assertSame(SeedVerdict::Inbox, $copy->verdict);
        $this->assertSame('INBOX', $copy->landedFolder);
    }

    /**
     * And the folder name is kept beside the verdict rather than instead
     * of it: « Junk » and « Indésirables » are one verdict and two names,
     * and the name is what an operator recognises when they go and look.
     */
    public function testALandingInTheJunkFolderKeepsTheProvidersOwnName(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('envoi-1', 'temoin@orange.fr', $now);

        $this->copies->recordLanding('envoi-1', 'temoin@orange.fr', 'Indésirables', $now);

        $copy = $this->copies->forRun('envoi-1')[0];
        $this->assertSame(SeedVerdict::Spam, $copy->verdict);
        $this->assertSame('Indésirables', $copy->landedFolder);
    }

    /**
     * A mailbox re-read is routine — a UIDVALIDITY reset re-reads a whole
     * folder — so a second arrival must not overwrite the first answer.
     */
    public function testASecondArrivalDoesNotRewriteTheFirstAnswer(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('envoi-1', 'temoin@gmail.com', $now);
        $this->copies->recordLanding('envoi-1', 'temoin@gmail.com', 'INBOX', $now);

        $this->assertFalse($this->copies->recordLanding('envoi-1', 'temoin@gmail.com', 'Junk', $now));
        $this->assertSame(SeedVerdict::Inbox, $this->copies->forRun('envoi-1')[0]->verdict);
    }

    /**
     * **« Pas encore » et « jamais » sont deux réponses.** A copy sent
     * five minutes ago and not yet seen is the ordinary state of any run
     * still going out; only elapsed time turns it into a refusal, and the
     * sweep is what says so — once, rather than a screen recomputing it
     * and flipping its own verdict while somebody watches.
     */
    public function testOnlyTheOlderPendingCopiesAreGivenUpOn(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('vieux', 'temoin@gmail.com', $now->modify('-2 days'));
        $this->copies->claim('recent', 'temoin@gmail.com', $now->modify('-5 minutes'));

        $this->assertSame(1, $this->copies->markMissingBefore($now->modify('-1 day')));

        $this->assertSame(SeedVerdict::Missing, $this->copies->forRun('vieux')[0]->verdict);
        $this->assertSame(SeedVerdict::Pending, $this->copies->forRun('recent')[0]->verdict);
    }

    /** A copy already answered is never given up on. */
    public function testASweepNeverOverwritesAnAnswerAlreadyGiven(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('envoi-1', 'temoin@gmail.com', $now->modify('-2 days'));
        $this->copies->recordLanding('envoi-1', 'temoin@gmail.com', 'Junk', $now->modify('-2 days'));

        $this->assertSame(0, $this->copies->markMissingBefore($now->modify('-1 day')));
        $this->assertSame(SeedVerdict::Spam, $this->copies->forRun('envoi-1')[0]->verdict);
    }

    /** The shape the screen draws: one row per run, one column per provider. */
    public function testTheRunsOfTheWindowComeBackGroupedByRun(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('envoi-1', 'temoin@gmail.com', $now->modify('-2 hours'));
        $this->copies->claim('envoi-1', 'temoin@outlook.com', $now->modify('-2 hours'));
        $this->copies->claim('envoi-2', 'temoin@gmail.com', $now->modify('-1 hour'));

        $runs = $this->copies->runsSince($now->modify('-1 day'));

        $this->assertCount(2, $runs);
        $this->assertCount(2, $runs['envoi-1']);
        $this->assertCount(1, $runs['envoi-2']);
    }

    /**
     * The tally is uncapped and computed by the database — never summed
     * from the capped listing above, which is the defect IT-06 shipped
     * and had to correct twice.
     */
    public function testTheTallyCountsEveryCopyPerProvider(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('envoi-1', 'temoin@gmail.com', $now);
        $this->copies->recordLanding('envoi-1', 'temoin@gmail.com', 'INBOX', $now);
        $this->copies->claim('envoi-2', 'temoin@gmail.com', $now);
        $this->copies->recordLanding('envoi-2', 'temoin@gmail.com', 'Junk', $now);
        $this->copies->claim('envoi-3', 'temoin@outlook.com', $now);

        $tally = $this->copies->tallyByProviderSince($now->modify('-1 day'));

        $this->assertSame('gmail.com', $tally[0]['provider']);
        $this->assertSame(1, $tally[0]['inbox']);
        $this->assertSame(1, $tally[0]['spam']);
        $this->assertSame('outlook.com', $tally[1]['provider']);
        $this->assertSame(1, $tally[1]['pending']);
    }

    public function testCopiesPurgeOnTheirSendDate(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 10:00:00');
        $this->copies->claim('vieux', 'temoin@gmail.com', $now->modify('-100 days'));
        $this->copies->claim('recent', 'temoin@gmail.com', $now->modify('-1 day'));

        $this->assertSame(1, $this->copies->purgeBefore($now->modify('-90 days')));
        $this->assertCount(0, $this->copies->forRun('vieux'));
        $this->assertCount(1, $this->copies->forRun('recent'));
    }

    /** The provider is the domain, lower-cased, and nothing cleverer. */
    public function testTheProviderIsTheDomain(): void
    {
        $this->assertSame('gmail.com', SeedCopy::providerOf('Temoin@Gmail.COM'));
        $this->assertSame('inconnu', SeedCopy::providerOf('pas-une-adresse'));
    }

    /**
     * **A stored moment is read through `DateInput`, never through the
     * raw constructor.**
     *
     * The constructor has two failure modes and the second is the one
     * that matters: it throws on a malformed string — one bad row and the
     * page is a 500 — and it answers *now* for an empty one. A copy that
     * silently claimed to have been sent today would re-enter every
     * thirty-day window for ever and never be purged, so a corrupt row
     * would quietly become a permanent one.
     *
     * `Tests\Security\StoredDateReadingRatchetTest` holds the rule across
     * the whole site; this holds what the rule buys here.
     */
    public function testACopyWhoseStoredMomentIsEmptyIsRefusedRatherThanDatedToday(): void
    {
        $this->copies->claim('envoi', 'temoin@gmail.com', new \DateTimeImmutable('-40 days'));
        $statement = $this->pdo->prepare("UPDATE mail_seed_copies SET sent_at = '' WHERE run_reference = ?");
        $statement->execute(['envoi']);

        $this->expectException(\RuntimeException::class);

        $this->copies->forRun('envoi');
    }

    /** And a landing nobody has recorded stays null rather than becoming now. */
    public function testACopyWithNoLandingYetHasNoRecordedMoment(): void
    {
        $this->copies->claim('envoi', 'temoin@gmail.com', new \DateTimeImmutable('-1 hour'));

        $this->assertNull($this->copies->forRun('envoi')[0]->recordedAt);
    }

    // ── the header is checked, never believed ─────────────────────────

    /**
     * **A stamp this installation made is the only one it accepts.**
     *
     * A run reference is `mass_mail:<id>`, a plain auto-increment, and a
     * seed box is an ordinary mailbox whose address anyone may learn — so
     * a bare reference on the header is something a stranger can guess
     * and send. They would land in whichever folder they chose and have
     * the site record that as a real mailing's verdict, first-writer-wins
     * against the copy still in flight.
     */
    public function testAStampRoundTripsAndAForgedOneIsRefused(): void
    {
        $stamp = $this->copies->stamp('mass_mail:42');

        $this->assertSame('mass_mail:42', $this->copies->referenceFromStamp($stamp));

        foreach (
            [
                'mass_mail:42',
                'mass_mail:42.',
                'mass_mail:42.' . str_repeat('0', 32),
                'mass_mail:43.' . substr($stamp, strrpos($stamp, '.') + 1),
                '.' . substr($stamp, strrpos($stamp, '.') + 1),
                '',
            ] as $forged
        ) {
            $this->assertNull(
                $this->copies->referenceFromStamp($forged),
                var_export($forged, true) . ' is not a stamp this installation produced.'
            );
        }
    }

    /** Two installations do not accept each other's stamps. */
    public function testAStampFromAnotherInstallationIsRefused(): void
    {
        $elsewhere = new SeedCopyRepository(
            $this->pdo,
            new EncryptionService(str_repeat('c', 32), str_repeat('d', 32))
        );

        $this->assertNull($this->copies->referenceFromStamp($elsewhere->stamp('mass_mail:42')));
    }

    /** A reference containing a dot survives the round trip. */
    public function testAReferenceWithADotIsReadBackWhole(): void
    {
        $stamp = $this->copies->stamp('mass_mail:v1.2:7');

        $this->assertSame('mass_mail:v1.2:7', $this->copies->referenceFromStamp($stamp));
    }

    // ── a copy that arrived is never « jamais arrivé » ────────────────

    /**
     * **The sweep may not overwrite a landing it can see.**
     *
     * Belt to the braces of `SeedVerdict::fromFolder()` no longer
     * answering `Pending` for a named folder: a row whose `recorded_at`
     * is set is a copy that demonstrably arrived, and the sweep has no
     * business calling it « jamais arrivé » — the gravest badge the
     * screen has — however its verdict column happens to read.
     */
    public function testASweepNeverGivesUpOnACopyWhoseLandingWasRecorded(): void
    {
        $sent = new \DateTimeImmutable('-10 days');
        $this->copies->claim('envoi', 'temoin@gmail.com', $sent);
        // A row that landed and, for whatever reason, still reads
        // `pending` — the exact state the old `fromFolder()` produced.
        $statement = $this->pdo->prepare(
            "UPDATE mail_seed_copies SET landed_folder = 'Quarantaine', recorded_at = ? WHERE run_reference = ?"
        );
        $statement->execute([$sent->format('Y-m-d H:i:s'), 'envoi']);

        $this->copies->markMissingBefore(new \DateTimeImmutable('-2 days'));

        $this->assertNotSame(
            SeedVerdict::Missing,
            $this->copies->forRun('envoi')[0]->verdict,
            'a copy that was found is not a copy that never came.'
        );
    }

    /** And one nobody ever saw still is given up on. */
    public function testASweepStillGivesUpOnACopyNobodyEverSaw(): void
    {
        $this->copies->claim('envoi', 'temoin@gmail.com', new \DateTimeImmutable('-10 days'));

        $this->copies->markMissingBefore(new \DateTimeImmutable('-2 days'));

        $this->assertSame(SeedVerdict::Missing, $this->copies->forRun('envoi')[0]->verdict);
    }
}
