<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Mail\Feedback\Dmarc\DmarcRecord;
use Core\Mail\Feedback\Dmarc\DmarcReport;
use Core\Mail\Feedback\Dmarc\DmarcReportRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[Group('database')]
class DmarcReportRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private DmarcReportRepository $reports;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo->prepare('PRAGMA foreign_keys = ON')->execute();
        $this->reports = new DmarcReportRepository($this->pdo);
    }

    private function report(string $reportId = 'r-1', string $org = 'google.com'): DmarcReport
    {
        return new DmarcReport(
            organisation: $org,
            reportId: $reportId,
            domain: 'unite.be',
            begin: new \DateTimeImmutable('-2 days'),
            end: new \DateTimeImmutable('-1 day'),
            policy: 'none',
            records: [
                new DmarcRecord('185.12.80.100', 42, 'none', true, true, 'unite.be'),
                new DmarcRecord('203.0.113.77', 3, 'quarantine', false, false, 'unite.be'),
            ]
        );
    }

    public function testAReportIsWrittenWithItsLines(): void
    {
        $this->assertTrue($this->reports->record($this->report(), new \DateTimeImmutable()));

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_sources')->fetchColumn());
    }

    /**
     * **The same report arriving twice is routine**, not exceptional: a
     * UIDVALIDITY reset has a folder re-read, and one message can sit in
     * two watched folders at once. The sync runs its analysis pass BEFORE
     * the Message-ID dedup, so the consumer is the one that has to shrug.
     */
    public function testTheSameReportReadTwiceIsWrittenOnce(): void
    {
        $now = new \DateTimeImmutable();

        $this->assertTrue($this->reports->record($this->report(), $now));
        $this->assertFalse($this->reports->record($this->report(), $now));

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_sources')->fetchColumn());
    }

    /** Two reporters may use the same id without colliding. */
    public function testTwoReportersMayShareAReportId(): void
    {
        $now = new \DateTimeImmutable();

        $this->assertTrue($this->reports->record($this->report('same', 'google.com'), $now));
        $this->assertTrue($this->reports->record($this->report('same', 'Enterprise Outlook'), $now));

        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
    }

    /**
     * Grouped on the address, because the question is « who sends in my
     * name » and one sender appears in as many reports as there are
     * providers receiving from it.
     */
    public function testSourcesAreSummedAcrossReporters(): void
    {
        $now = new \DateTimeImmutable();
        $this->reports->record($this->report('r-1', 'google.com'), $now);
        $this->reports->record($this->report('r-2', 'Enterprise Outlook'), $now);

        $sources = $this->reports->sourcesSince(new \DateTimeImmutable('-30 days'));

        $this->assertCount(2, $sources);
        $this->assertSame('185.12.80.100', $sources[0]['source_ip'], 'most messages first.');
        $this->assertSame(84, $sources[0]['messages']);
        $this->assertSame(84, $sources[0]['authenticated']);
        $this->assertSame(2, $sources[0]['reporters']);
        $this->assertSame(6, $sources[1]['messages']);
        $this->assertSame(0, $sources[1]['authenticated'], 'neither SPF nor DKIM passed for that one.');
    }

    public function testTheReportListCarriesThePolicyEachReporterSaw(): void
    {
        $this->reports->record($this->report(), new \DateTimeImmutable());

        $listed = $this->reports->reportsSince(new \DateTimeImmutable('-30 days'));

        $this->assertCount(1, $listed);
        $this->assertSame('google.com', $listed[0]['organisation']);
        $this->assertSame('none', $listed[0]['policy']);
        $this->assertSame(45, $listed[0]['messages']);
    }

    /** Outside the window is outside the answer. */
    public function testAReportOlderThanTheWindowIsNotListed(): void
    {
        $old = new DmarcReport(
            organisation: 'google.com',
            reportId: 'ancient',
            domain: 'unite.be',
            begin: new \DateTimeImmutable('-400 days'),
            end: new \DateTimeImmutable('-399 days'),
            policy: 'none',
            records: [new DmarcRecord('185.12.80.100', 5, 'none', true, true, 'unite.be')]
        );
        $this->reports->record($old, new \DateTimeImmutable());

        $this->assertSame([], $this->reports->sourcesSince(new \DateTimeImmutable('-30 days')));
        $this->assertSame([], $this->reports->reportsSince(new \DateTimeImmutable('-30 days')));
    }

    /**
     * **Purged on the period's end, not on arrival.** A report can turn up
     * days after the window it describes, and purging on arrival would
     * remove it for being old the moment it landed.
     */
    public function testThePurgeRemovesReportsAndTheirLines(): void
    {
        $now = new \DateTimeImmutable();
        $this->reports->record($this->report('recent'), $now);

        $old = new DmarcReport(
            organisation: 'google.com',
            reportId: 'ancient',
            domain: 'unite.be',
            begin: new \DateTimeImmutable('-400 days'),
            end: new \DateTimeImmutable('-399 days'),
            policy: 'none',
            records: [new DmarcRecord('185.12.80.100', 5, 'none', true, true, 'unite.be')]
        );
        $this->reports->record($old, $now);

        // **A report whose window STRADDLES the cut stays.** Without this
        // one the test cannot tell `period_end` from `period_begin`: the
        // ancient report above has both bounds before the cut, so either
        // column purges it and the assertion passes either way. Verified
        // by swapping the column — which is how this case came to be here.
        $straddling = new DmarcReport(
            organisation: 'Enterprise Outlook',
            reportId: 'straddling',
            domain: 'unite.be',
            begin: new \DateTimeImmutable('-366 days'),
            end: new \DateTimeImmutable('-364 days'),
            policy: 'none',
            records: [new DmarcRecord('185.12.80.100', 1, 'none', true, true, 'unite.be')]
        );
        $this->reports->record($straddling, $now);

        $removed = $this->reports->purgeBefore(new \DateTimeImmutable('-365 days'));

        $this->assertSame(1, $removed, 'only the report whose window ENDED before the cut.');
        $this->assertSame(
            1,
            (int) $this->pdo
                ->query("SELECT COUNT(*) FROM mail_dmarc_reports WHERE report_id = 'straddling'")
                ->fetchColumn(),
            'a window still open at the cut is not over, whatever its beginning.'
        );
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_reports')->fetchColumn());
        $this->assertSame(
            3,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mail_dmarc_sources')->fetchColumn(),
            'the purged report took its own lines and left the others alone.'
        );
    }

    // ── ce que la relecture a demandé (IT-06) ─────────────────────────

    /**
     * **A failure that is not the race must not answer « déjà là ».**
     *
     * Both outcomes used to be `false`, so a database refusing writes was
     * indistinguishable from an ordinary re-read: the consumer skipped its
     * journal line and the registry saw no exception to record, and the
     * whole thing looked like a quiet, successful sync. That is this
     * chantier's own recurring defect — a failure wearing the shape of a
     * success — and it is the one the review caught here.
     *
     * The source table is dropped rather than mocked, because a PDO double
     * agreeing to throw proves only that the catch re-throws what a test
     * handed it. This makes the database genuinely refuse a write that the
     * report insert has already succeeded past.
     */
    public function testAWriteFailureThatIsNotADuplicateReachesTheCaller(): void
    {
        $this->pdo->prepare('DROP TABLE mail_dmarc_sources')->execute();

        $this->expectException(\PDOException::class);

        $this->reports->record($this->report(), new \DateTimeImmutable());
    }

    /**
     * **The premise the race branch rests on, asserted rather than
     * assumed.** `isDuplicateKey()` reads `errorInfo` and accepts one of
     * three driver codes; if this engine reported a fourth, a losing race
     * would throw instead of shrugging, and nothing else in this file
     * would notice — `alreadyHave()` short-circuits every ordinary
     * duplicate before the index ever sees it. So the index is made to
     * refuse a write directly, and what it says is checked against what
     * the repository looks for. A premise nobody checked is how the last
     * engine-specific defect got in (`docs/quality-pipeline.md`).
     */
    public function testThisEngineReportsADuplicateTheWayTheRepositoryReadsIt(): void
    {
        $this->assertTrue($this->reports->record($this->report(), new \DateTimeImmutable()));

        $statement = $this->pdo->prepare(
            'INSERT INTO mail_dmarc_reports
                (organisation, report_id, domain, period_begin, period_end, policy, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute([
                'google.com',
                'r-1',
                'unite.be',
                '2026-09-01 00:00:00',
                '2026-09-02 00:00:00',
                'none',
                '2026-09-02 00:00:00',
            ]);
            $this->fail('The unique index must refuse this.');
        } catch (\PDOException $duplicate) {
            $this->assertSame('23000', $duplicate->errorInfo[0] ?? null);
            $this->assertContains(
                $duplicate->errorInfo[1] ?? null,
                [1062, 19, 7],
                'A code outside this list would make a losing race throw instead of shrug.'
            );
        }
    }

    /** And the race still answers false, which is the whole point of telling them apart. */
    public function testTheSameReportTwiceStillAnswersFalseWithoutThrowing(): void
    {
        $this->assertTrue($this->reports->record($this->report(), new \DateTimeImmutable()));
        $this->assertFalse($this->reports->record($this->report(), new \DateTimeImmutable()));
    }

    /**
     * The totals come from the database over every row, never from the
     * capped lists a screen draws.
     */
    public function testTheTotalsCountEverythingAndNotWhatFitsOnAScreen(): void
    {
        $now = new \DateTimeImmutable();
        $this->reports->record($this->report('r-1', 'google.com'), $now);
        $this->reports->record($this->report('r-2', 'Yahoo'), $now);

        $since = $now->modify('-30 days');
        $totals = $this->reports->totalsSince($since);

        $this->assertSame(2, $totals['reports']);
        $this->assertSame(2, $totals['reporters']);
        $this->assertSame(2, $totals['sources'], 'Two distinct addresses, each seen in both reports.');
        $this->assertSame(90, $totals['messages'], '(42 + 3) twice.');
        $this->assertSame(84, $totals['authenticated'], '42 twice; the other line authenticates neither way.');

        // The discriminating half: one row of display is not the truth.
        $shown = $this->reports->sourcesSince($since, 1);
        $this->assertCount(1, $shown);
        $this->assertNotSame($shown[0]['messages'], $totals['messages']);
    }

    /**
     * **Only the sources that got something through**, which is what the
     * page's one warning is computed over. Reading it off the displayed
     * table instead would miss the quiet forgotten tool — the very thing
     * the warning exists to name.
     */
    public function testOnlyTheAuthenticatingSourcesAreOfferedForTheWarning(): void
    {
        $now = new \DateTimeImmutable();
        $this->reports->record($this->report(), $now);

        $authenticating = $this->reports->authenticatingSourcesSince($now->modify('-30 days'));

        $this->assertSame(['185.12.80.100'], $authenticating);
    }

    /** A limit is bound, not concatenated — and it still limits. */
    public function testTheLimitIsAppliedThroughABoundParameter(): void
    {
        $now = new \DateTimeImmutable();
        $this->reports->record($this->report('r-1', 'google.com'), $now);
        $this->reports->record($this->report('r-2', 'Yahoo'), $now);

        $since = $now->modify('-30 days');

        $this->assertCount(1, $this->reports->sourcesSince($since, 1));
        $this->assertCount(1, $this->reports->reportsSince($since, 1));
        $this->assertCount(2, $this->reports->reportsSince($since, 50));
    }

    public function testThePoliciesSeenOverTheWindowAreListedOnce(): void
    {
        $now = new \DateTimeImmutable();
        $this->reports->record($this->report('r-1', 'google.com'), $now);
        $this->reports->record($this->report('r-2', 'Yahoo'), $now);

        $this->assertSame(['none'], $this->reports->policiesSince($now->modify('-30 days')));
    }
}
