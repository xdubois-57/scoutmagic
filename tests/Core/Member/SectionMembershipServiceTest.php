<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionMembershipService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionMembershipServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionMembershipRepository $repository;
    private SectionMembershipService $service;
    private int $memberId;
    private int $memberYearId;
    private int $scoutYearId;
    private int $sectionA;
    private int $sectionB;
    private int $functionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SectionMembershipRepository($this->pdo);
        $this->service = new SectionMembershipService($this->repository, new ScoutYearService($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionA = $this->createSection('SEC_A', $branchId);
        $this->sectionB = $this->createSection('SEC_B', $branchId);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $this->scoutYearId, 'John', 'Doe']);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id) VALUES (?, ?)');
        $stmt->execute([$deskCode, $branchId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reuses setUp()'s own $this->memberYearId when $scoutYearId happens
     * to coincide with $this->scoutYearId (which can genuinely happen —
     * ScoutYearService::getCurrentYear() is wall-clock-driven and setUp()'s
     * fixture year isn't fixed relative to "today"), since member_years
     * has a UNIQUE(member_id, scout_year_id) constraint.
     */
    private function ensureMemberYear(int $scoutYearId): int
    {
        if ($scoutYearId === $this->scoutYearId) {
            return $this->memberYearId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $scoutYearId, 'John', 'Doe']);

        return (int) $this->pdo->lastInsertId();
    }

    private function setMemberFunctionSection(?int $sectionId): void
    {
        $this->pdo->exec('DELETE FROM member_functions WHERE member_year_id = ' . $this->memberYearId);
        if ($sectionId !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$this->memberYearId, $this->functionId, $sectionId]);
        }
    }

    public function testFirstSyncOpensAPeriodForTheMembersSection(): void
    {
        $this->setMemberFunctionSection($this->sectionA);

        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2025-10-01'));
    }

    public function testReRunningTheSameImportCreatesNoDuplicatePeriod(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->assertCount(1, $this->repository->findAllForMember($this->memberId));
    }

    /**
     * The core scenario the module was built for: a member moves from
     * section A to section B mid-year, across two Desk imports within
     * the SAME scout year.
     */
    public function testMidYearSectionChangeClosesOldPeriodAndOpensNew(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->setMemberFunctionSection($this->sectionB);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2026-03-15'));

        // Section A was active only until the change date.
        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2025-12-01'));
        $this->assertFalse($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2026-04-01'));

        // Section B only from the change date onward.
        $this->assertFalse($this->repository->hasPeriodCovering($this->memberId, $this->sectionB, $this->scoutYearId, '2025-12-01'));
        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionB, $this->scoutYearId, '2026-04-01'));
    }

    public function testRolloverClosesAPeriodLeftOpenFromAnEarlierScoutYearAtThatYearsEndDate(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $nextYearId, 'John', 'Doe']);
        $nextMemberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$nextMemberYearId, $this->functionId, $this->sectionA]);

        $this->service->syncForMember($this->memberId, $nextMemberYearId, $nextYearId, new \DateTimeImmutable('2026-10-01'));

        $periods = $this->repository->findAllForMember($this->memberId);
        $this->assertCount(2, $periods);

        $closed = array_values(array_filter($periods, fn($p) => $p->scoutYearId === $this->scoutYearId));
        $this->assertCount(1, $closed);
        $this->assertSame('2026-08-31', $closed[0]->endDate);
    }

    private function createNextYear(): int
    {
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function setFunctionSection(int $memberYearId, ?int $sectionId): void
    {
        $this->pdo->exec('DELETE FROM member_functions WHERE member_year_id = ' . $memberYearId);
        if ($sectionId !== null) {
            $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
                 VALUES (?, ?, ?, 1)'
            )->execute([$memberYearId, $this->functionId, $sectionId]);
        }
    }

    private function sync(int $memberYearId, int $scoutYearId, string $date): int
    {
        $asOf = new \DateTimeImmutable($date);

        return $this->service->syncForMember($this->memberId, $memberYearId, $scoutYearId, $asOf);
    }

    /**
     * @return array<int, array{int, string, ?string}> [section_id, start_date, end_date], in order
     */
    private function periodsOf(int $scoutYearId): array
    {
        return array_map(
            static fn($p): array => [$p->sectionId, $p->startDate, $p->endDate],
            $this->repository->findAllForMemberAndYear($this->memberId, $scoutYearId)
        );
    }

    private function insertPeriod(int $sectionId, int $scoutYearId, string $start, ?string $end): void
    {
        $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->memberId, $sectionId, $scoutYearId, $start, $end]);
    }

    /**
     * Issue #1461: July, the next year is imported; August, the current
     * one is re-imported; September, the next one again. The re-import
     * of the current year closes nothing of the next one, and the next
     * year ends with one period per section.
     */
    public function testAlternatingImportsLeaveTheLaterYearAloneAndCreateNoDuplicate(): void
    {
        $nextYearId = $this->createNextYear();
        $nextMemberYearId = $this->ensureMemberYear($nextYearId);
        $this->setFunctionSection($nextMemberYearId, $this->sectionA);
        $this->setFunctionSection($this->memberYearId, $this->sectionA);

        $this->sync($nextMemberYearId, $nextYearId, '2026-07-01');
        $closed = $this->sync($this->memberYearId, $this->scoutYearId, '2026-08-01');

        $this->assertSame(0, $closed);
        $this->assertSame([[$this->sectionA, '2026-07-01', null]], $this->periodsOf($nextYearId));

        $this->sync($nextMemberYearId, $nextYearId, '2026-09-15');

        $this->assertSame([[$this->sectionA, '2026-07-01', null]], $this->periodsOf($nextYearId));
    }

    /**
     * Issue #1461: after the same alternation, a member who changed
     * section has the old period closed on the day of the import, and is
     * no longer in it at the reference date.
     */
    public function testASectionChangeAfterTheAlternationClosesTheOldPeriodThatDay(): void
    {
        $nextYearId = $this->createNextYear();
        $nextMemberYearId = $this->ensureMemberYear($nextYearId);
        $this->setFunctionSection($nextMemberYearId, $this->sectionA);
        $this->setFunctionSection($this->memberYearId, $this->sectionA);

        $this->sync($nextMemberYearId, $nextYearId, '2026-07-01');
        $this->sync($this->memberYearId, $this->scoutYearId, '2026-08-01');
        $this->setFunctionSection($nextMemberYearId, $this->sectionB);
        $this->sync($nextMemberYearId, $nextYearId, '2026-09-15');

        $this->assertSame(
            [[$this->sectionA, '2026-07-01', '2026-09-15'], [$this->sectionB, '2026-09-15', null]],
            $this->periodsOf($nextYearId)
        );
        $covers = fn(int $sectionId): bool
            => $this->repository->hasPeriodCovering($this->memberId, $sectionId, $nextYearId, '2026-10-01');
        $this->assertFalse($covers($this->sectionA));
        $this->assertTrue($covers($this->sectionB));
    }

    /**
     * The safety net closes an earlier year's period at that year's own
     * end: re-importing that year afterwards recognises it as the year's
     * period instead of opening a second one.
     */
    public function testAPeriodClosedAtItsOwnYearsEndIsNotRecreated(): void
    {
        $nextYearId = $this->createNextYear();
        $nextMemberYearId = $this->ensureMemberYear($nextYearId);
        $this->setFunctionSection($this->memberYearId, $this->sectionA);
        $this->setFunctionSection($nextMemberYearId, $this->sectionA);

        $this->sync($this->memberYearId, $this->scoutYearId, '2025-10-01');
        $this->assertSame(1, $this->sync($nextMemberYearId, $nextYearId, '2026-07-01'));
        $this->sync($this->memberYearId, $this->scoutYearId, '2026-08-01');

        $this->assertSame([[$this->sectionA, '2025-10-01', '2026-08-31']], $this->periodsOf($this->scoutYearId));
    }

    /**
     * A year re-imported after its end never has a period stretched past
     * that end by a section change.
     */
    public function testLeavingASectionOnAReImportAfterTheYearsEndClosesItAtThatEnd(): void
    {
        $this->setFunctionSection($this->memberYearId, $this->sectionA);
        $this->sync($this->memberYearId, $this->scoutYearId, '2025-10-01');

        $this->setFunctionSection($this->memberYearId, null);
        $this->sync($this->memberYearId, $this->scoutYearId, '2026-09-20');

        $this->assertSame([[$this->sectionA, '2025-10-01', '2026-08-31']], $this->periodsOf($this->scoutYearId));
    }

    /**
     * An ended year imported again in configuration mode opens a period
     * after the year's end; leaving the section on the next such import
     * closes it on the day it started, never before, and coming back
     * opens a new one.
     */
    public function testAPeriodOpenedAfterTheYearsEndIsNeverClosedBeforeItStarts(): void
    {
        $this->setFunctionSection($this->memberYearId, $this->sectionA);
        $this->sync($this->memberYearId, $this->scoutYearId, '2026-09-20');

        $this->setFunctionSection($this->memberYearId, null);
        $this->sync($this->memberYearId, $this->scoutYearId, '2026-09-25');

        $this->assertSame([[$this->sectionA, '2026-09-20', '2026-09-20']], $this->periodsOf($this->scoutYearId));

        $this->setFunctionSection($this->memberYearId, $this->sectionA);
        $this->sync($this->memberYearId, $this->scoutYearId, '2026-09-30');

        $this->assertSame(
            [[$this->sectionA, '2026-09-20', '2026-09-20'], [$this->sectionA, '2026-09-30', null]],
            $this->periodsOf($this->scoutYearId)
        );
    }

    /**
     * What the old safety net left on an install: the later year's period
     * closed at its own end, a duplicate opened next to it, and the old
     * section of a member who moved still valid all year. The next import
     * of the year folds the duplicate and closes the old section.
     */
    public function testTheNextImportRepairsWhatTheOldSafetyNetLeft(): void
    {
        $nextYearId = $this->createNextYear();
        $nextMemberYearId = $this->ensureMemberYear($nextYearId);
        $this->insertPeriod($this->sectionA, $nextYearId, '2026-07-01', '2027-08-31');
        $this->insertPeriod($this->sectionA, $nextYearId, '2026-09-15', null);
        $this->insertPeriod($this->sectionB, $nextYearId, '2026-07-01', '2027-08-31');
        $this->setFunctionSection($nextMemberYearId, $this->sectionA);

        $this->sync($nextMemberYearId, $nextYearId, '2026-10-01');

        $this->assertSame(
            [[$this->sectionA, '2026-07-01', null], [$this->sectionB, '2026-07-01', '2026-10-01']],
            $this->periodsOf($nextYearId)
        );
    }

    /**
     * Leaving a section and coming back in the same year is two periods
     * that do not overlap: they are history, not a duplicate.
     */
    public function testLeavingAndComingBackInTheSameYearKeepsBothPeriods(): void
    {
        $this->insertPeriod($this->sectionA, $this->scoutYearId, '2025-10-01', '2026-01-10');
        $this->insertPeriod($this->sectionB, $this->scoutYearId, '2026-01-10', '2026-03-01');
        $this->insertPeriod($this->sectionA, $this->scoutYearId, '2026-03-01', null);
        $this->setFunctionSection($this->memberYearId, $this->sectionA);

        $this->sync($this->memberYearId, $this->scoutYearId, '2026-04-01');

        $this->assertSame(
            [
                [$this->sectionA, '2025-10-01', '2026-01-10'],
                [$this->sectionA, '2026-03-01', null],
                [$this->sectionB, '2026-01-10', '2026-03-01'],
            ],
            $this->periodsOf($this->scoutYearId)
        );
    }

    public function testLeavingASectionEntirelyClosesThePeriodWithNoNewOne(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->setMemberFunctionSection(null);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2026-01-01'));

        $this->assertFalse($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $this->scoutYearId, '2026-02-01'));
        $this->assertCount(1, $this->repository->findAllForMember($this->memberId));
    }

    /**
     * The one-time seed (public/index.php, gated by
     * 'member_section_periods_backfilled') for installs where this table
     * was added after member_functions data already existed — the exact
     * scenario reported: a document uploaded and visible on the Staffs
     * page, but never shown on the member page because no period existed
     * yet for that member's current section.
     */
    public function testBackfillOpensAnOpenEndedPeriodForTheCurrentScoutYear(): void
    {
        // ScoutYearService::getCurrentYear() is wall-clock-driven (never
        // the is_current column), so the member's function must be set up
        // against whatever it actually resolves to right now rather than
        // setUp()'s fixed 2025-2026 fixture, or this test would only pass
        // when run before September 1st.
        $scoutYearService = new ScoutYearService($this->pdo);
        $current = $scoutYearService->getCurrentYear();

        $currentMemberYearId = $this->ensureMemberYear($current['id']);
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        )->execute([$currentMemberYearId, $this->functionId, $this->sectionA]);

        $this->service->backfillFromFunctions();

        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $current['id'], $current['start_date']));
        $this->assertTrue($this->repository->hasPeriodCovering($this->memberId, $this->sectionA, $current['id'], $current['end_date']));

        $periods = $this->repository->findAllForMember($this->memberId);
        $this->assertCount(1, $periods);
        $this->assertNull($periods[0]->endDate);
    }

    /**
     * A past scout year is backfilled as CLOSED (start/end = that year's
     * own dates) — it must never be left open, or a member who has since
     * moved sections would show as still "active" in their old one.
     */
    public function testBackfillClosesAPastScoutYearAtItsOwnEndDate(): void
    {
        // ScoutYearService::getCurrentYear() is purely wall-clock-driven
        // (never the is_current column — see its own docblock), so "past"
        // here must be computed relative to it rather than hardcoded, or
        // this test would be date-dependent on when the suite happens to
        // run. setUp()'s own fixture scout year is deliberately left out
        // of this test (no function set on it) to avoid colliding with
        // whatever the real current year turns out to be.
        $scoutYearService = new ScoutYearService($this->pdo);
        $current = $scoutYearService->getCurrentYear();
        $currentYearId = $current['id'];

        $pastStartYear = ((int) substr($current['start_date'], 0, 4)) - 1;
        $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)')
            ->execute([
                $pastStartYear . '-' . ($pastStartYear + 1),
                $pastStartYear . '-09-01',
                ($pastStartYear + 1) . '-08-31',
            ]);
        $pastYearId = (int) $this->pdo->lastInsertId();

        $pastMemberYearId = $this->ensureMemberYear($pastYearId);
        $currentMemberYearId = $this->ensureMemberYear($currentYearId);

        $insertFunction = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $insertFunction->execute([$pastMemberYearId, $this->functionId, $this->sectionA]);
        $insertFunction->execute([$currentMemberYearId, $this->functionId, $this->sectionB]);

        $this->service->backfillFromFunctions();

        $periods = $this->repository->findAllForMember($this->memberId);
        $this->assertCount(2, $periods);

        $pastPeriod = array_values(array_filter($periods, fn($p) => $p->scoutYearId === $pastYearId))[0];
        $this->assertSame(($pastStartYear + 1) . '-08-31', $pastPeriod->endDate);

        $currentPeriod = array_values(array_filter($periods, fn($p) => $p->scoutYearId === $currentYearId))[0];
        $this->assertNull($currentPeriod->endDate);
    }

    public function testBackfillSkipsATripleThatAlreadyHasAPeriod(): void
    {
        $this->setMemberFunctionSection($this->sectionA);
        $this->service->syncForMember($this->memberId, $this->memberYearId, $this->scoutYearId, new \DateTimeImmutable('2025-10-01'));

        $this->service->backfillFromFunctions();

        // Still just the one real period from syncForMember — backfill
        // must not create a duplicate for a triple that's already covered.
        $this->assertCount(1, $this->repository->findAllForMember($this->memberId));
    }

    public function testBackfillDoesNothingWhenNoMemberHasAFunctionWithASection(): void
    {
        $this->setMemberFunctionSection(null);

        $this->service->backfillFromFunctions();

        $this->assertCount(0, $this->repository->findAllForMember($this->memberId));
    }
}
