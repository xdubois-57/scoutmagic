<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Config\ScoutYearService;
use Core\Member\SectionMembershipRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * findAllForMember() answers « where has this person been, most recent
 * first », and four consumers rely on the promise: the member page's
 * « Parcours dans l'unité » (which truncates the list, so a wrong order
 * keeps the wrong rows), section documents, camp stays and the discussion
 * group list.
 *
 * The order used to be ORDER BY scout_year_id — the order the rows were
 * CREATED, which on a real install is not the calendar:
 * ScoutYearService::ensureYear() makes a year the first time something
 * needs it, and the registration module makes NEXT year's the first time
 * a request is accepted or a passage decided. So the fixture here builds
 * the ids deliberately out of chronological order, which is the only
 * shape in which the defect is visible at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionMembershipRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SectionMembershipRepository $repository;
    private int $memberId;
    private int $sectionA;
    private int $sectionB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new SectionMembershipRepository($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->sectionA = $this->createSection('SEC_A', $branchId);
        $this->sectionB = $this->createSection('SEC_B', $branchId);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The years are created 2027-2028, then 2025-2026, then 2026-2027 —
     * so no reading of `scout_year_id` in any direction produces the
     * calendar order, and a test that happened to pass on the old query
     * is impossible.
     */
    public function testPeriodsComeBackNewestScoutYearFirstWhateverTheYearIdsAre(): void
    {
        $years = new ScoutYearService($this->pdo);
        $future = $years->ensureYear('2027-2028');
        $oldest = $years->ensureYear('2025-2026');
        $middle = $years->ensureYear('2026-2027');

        self::assertLessThan($oldest, $future, 'the fixture must build ids out of chronological order');
        self::assertLessThan($middle, $oldest, 'the fixture must build ids out of chronological order');

        $this->insertPeriod($oldest, $this->sectionA, '2025-09-01');
        $this->insertPeriod($middle, $this->sectionB, '2026-09-01');
        $this->insertPeriod($future, $this->sectionA, '2027-09-01');

        $periods = $this->repository->findAllForMember($this->memberId);

        self::assertSame(
            [$future, $middle, $oldest],
            array_map(static fn($period) => $period->scoutYearId, $periods)
        );
    }

    /**
     * The secondary sort survives: a member who changed section inside one
     * scout year has two periods that year, and the later one comes first.
     * Their `start_date` is the only thing that separates them, so sorting
     * on the year alone would leave their relative order to the storage
     * engine.
     */
    public function testTwoPeriodsOfTheSameYearStayInReverseChronologicalOrder(): void
    {
        $years = new ScoutYearService($this->pdo);
        $year = $years->ensureYear('2025-2026');

        $this->insertPeriod($year, $this->sectionA, '2025-09-01', '2026-01-04');
        $this->insertPeriod($year, $this->sectionB, '2026-01-05');

        $periods = $this->repository->findAllForMember($this->memberId);

        self::assertSame(
            [$this->sectionB, $this->sectionA],
            array_map(static fn($period) => $period->sectionId, $periods)
        );
    }

    public function testAMemberWithNoPeriodsGetsAnEmptyList(): void
    {
        self::assertSame([], $this->repository->findAllForMember($this->memberId));
    }

    /**
     * The join must not widen the answer to somebody else's periods — the
     * kind of thing an added JOIN quietly gets wrong.
     */
    public function testAnotherMembersPeriodsAreNeverReturned(): void
    {
        $years = new ScoutYearService($this->pdo);
        $year = $years->ensureYear('2025-2026');

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK2')");
        $otherMemberId = (int) $this->pdo->lastInsertId();

        $this->insertPeriod($year, $this->sectionA, '2025-09-01');
        $this->insertPeriod($year, $this->sectionB, '2025-09-01', null, $otherMemberId);

        $periods = $this->repository->findAllForMember($this->memberId);

        self::assertCount(1, $periods);
        self::assertSame($this->sectionA, $periods[0]->sectionId);
    }

    private function createSection(string $deskCode, int $branchId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id) VALUES (?, ?)');
        $stmt->execute([$deskCode, $branchId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertPeriod(
        int $scoutYearId,
        int $sectionId,
        string $start,
        ?string $end = null,
        ?int $memberId = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId ?? $this->memberId, $sectionId, $scoutYearId, $start, $end]);
    }
}
