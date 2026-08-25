<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Repository;

use Modules\Fees\Repository\RosterSnapshotRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RosterSnapshotRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RosterSnapshotRepository $repository;
    private int $scoutYearId;
    private int $otherYearId;
    private int $sectionId;
    private int $otherSectionId;
    private int $normalFeeId;
    private int $familyFeeId;
    private int $animeFunctionId;
    private int $chiefFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->repository = new RosterSnapshotRepository($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025L1', 'Meute Akela')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025L2', 'Meute Bagheera')");
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO fee_categories (desk_code, label) VALUES ('N_N_COTISATION NORMALE', 'Normale')");
        $this->normalFeeId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO fee_categories (desk_code, label) VALUES ('N_F_COTISATION FAMILLE', 'Famille')");
        $this->familyFeeId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Animé', 'Animé', 'identified')");
        $this->animeFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Animateur', 'Animateur', 'chief')");
        $this->chiefFunctionId = (int) $this->pdo->lastInsertId();
    }

    /** @return int member_year id */
    private function createMemberYear(
        ?int $feeCategoryId = null,
        ?string $formationLevel = null,
        bool $leaving = false,
        bool $active = true,
        ?int $scoutYearId = null
    ): int {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       fee_category_id, formation_level, leaving, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $scoutYearId ?? $this->scoutYearId, 'enc', 'enc',
            $feeCategoryId, $formationLevel, $leaving ? 1 : 0, $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function addFunction(int $memberYearId, int $functionId, ?int $sectionId, bool $main): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $main ? 1 : 0]);
    }

    private function memberIdOf(int $memberYearId): int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);

        return (int) $stmt->fetchColumn();
    }

    public function testCaptureRecordsOneRowPerActiveMemberWithItsCodes(): void
    {
        $anime = $this->createMemberYear($this->normalFeeId, null);
        $this->addFunction($anime, $this->animeFunctionId, $this->sectionId, true);
        $chief = $this->createMemberYear($this->familyFeeId, 'Animateur breveté');
        $this->addFunction($chief, $this->chiefFunctionId, $this->sectionId, true);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-15 09:00:00'));

        $this->assertSame(2, $snapshot->memberCount);
        $this->assertSame($this->scoutYearId, $snapshot->scoutYearId);
        $this->assertSame('2026-01-15 09:00', $snapshot->takenAt->format('Y-m-d H:i'));

        $rows = $this->repository->findMembers($snapshot->id);
        $this->assertCount(2, $rows);

        $byMember = [];
        foreach ($rows as $row) {
            $byMember[$row->memberId] = $row;
        }

        $animeRow = $byMember[$this->memberIdOf($anime)];
        $this->assertSame($this->normalFeeId, $animeRow->feeCategoryId);
        $this->assertSame($this->sectionId, $animeRow->sectionId);
        $this->assertSame('identified', $animeRow->functionRole);
        $this->assertNull($animeRow->formationLevel);
        $this->assertFalse($animeRow->leaving);

        $chiefRow = $byMember[$this->memberIdOf($chief)];
        $this->assertSame($this->familyFeeId, $chiefRow->feeCategoryId);
        $this->assertSame('chief', $chiefRow->functionRole);
        $this->assertSame('Animateur breveté', $chiefRow->formationLevel);
    }

    /**
     * The whole point of the snapshot: Desk still holds a member whose
     * departure has been announced, and the federation still bills them.
     * Filtering here would make the snapshot unable to answer "what did
     * Desk contain".
     */
    public function testAMemberMarkedLeavingIsRecordedWithTheFlagNotFilteredOut(): void
    {
        $going = $this->createMemberYear($this->normalFeeId, null, leaving: true);
        $this->addFunction($going, $this->animeFunctionId, $this->sectionId, true);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $rows = $this->repository->findMembers($snapshot->id);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]->leaving);
        $this->assertSame(1, $snapshot->memberCount);
    }

    public function testAnInactiveMemberIsNotInTheSnapshot(): void
    {
        $this->createMemberYear($this->normalFeeId, null, active: false);
        $kept = $this->createMemberYear($this->normalFeeId, null);
        $this->addFunction($kept, $this->animeFunctionId, $this->sectionId, true);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $this->assertSame(1, $snapshot->memberCount);
        $this->assertSame($this->memberIdOf($kept), $this->repository->findMembers($snapshot->id)[0]->memberId);
    }

    public function testAnotherScoutYearIsNotInTheSnapshot(): void
    {
        $this->createMemberYear($this->normalFeeId, null, scoutYearId: $this->otherYearId);
        $this->createMemberYear($this->normalFeeId, null);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $this->assertSame(1, $snapshot->memberCount);
    }

    /**
     * An invoice bills a person once, under one section — two rows for
     * somebody holding two functions would report an expected quantity
     * nobody could reconcile.
     */
    public function testTwoFunctionsProduceOneRowTakenFromTheMainOne(): void
    {
        $memberYearId = $this->createMemberYear($this->normalFeeId, null);
        $this->addFunction($memberYearId, $this->animeFunctionId, $this->otherSectionId, false);
        $this->addFunction($memberYearId, $this->chiefFunctionId, $this->sectionId, true);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $rows = $this->repository->findMembers($snapshot->id);
        $this->assertCount(1, $rows);
        $this->assertSame($this->sectionId, $rows[0]->sectionId);
        $this->assertSame('chief', $rows[0]->functionRole);
    }

    public function testWithNoMainFunctionFlaggedTheFirstOneIsUsed(): void
    {
        $memberYearId = $this->createMemberYear($this->normalFeeId, null);
        $this->addFunction($memberYearId, $this->animeFunctionId, $this->sectionId, false);
        $this->addFunction($memberYearId, $this->chiefFunctionId, $this->otherSectionId, false);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $rows = $this->repository->findMembers($snapshot->id);
        $this->assertCount(1, $rows);
        $this->assertSame($this->sectionId, $rows[0]->sectionId);
    }

    public function testAMemberWithNoFunctionStillAppearsWithNoSection(): void
    {
        $this->createMemberYear($this->normalFeeId, null);

        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $rows = $this->repository->findMembers($snapshot->id);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->sectionId);
        $this->assertNull($rows[0]->functionRole);
    }

    public function testEachCaptureIsItsOwnSnapshotAndTheLatestIsTheMostRecent(): void
    {
        $first = $this->createMemberYear($this->normalFeeId, null);
        $this->addFunction($first, $this->animeFunctionId, $this->sectionId, true);
        $november = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable('2025-11-02 10:00:00'));

        $second = $this->createMemberYear($this->normalFeeId, null);
        $this->addFunction($second, $this->animeFunctionId, $this->sectionId, true);
        $january = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-08 10:00:00'));

        $this->assertSame(1, $november->memberCount);
        $this->assertSame(2, $january->memberCount);
        $this->assertSame(2, $this->repository->countForYear($this->scoutYearId));

        $latest = $this->repository->findLatestForYear($this->scoutYearId);
        $this->assertNotNull($latest);
        $this->assertSame($january->id, $latest->id);

        // The November one is untouched: an old snapshot is what makes an
        // old invoice checkable, so a later import must never rewrite it.
        $this->assertCount(1, $this->repository->findMembers($november->id));

        $all = $this->repository->findAllForYear($this->scoutYearId);
        $this->assertSame([$january->id, $november->id], array_map(static fn($s): int => $s->id, $all));
    }

    public function testAYearWithNoSnapshotAnswersNullRatherThanAnEmptyOne(): void
    {
        $this->assertNull($this->repository->findLatestForYear($this->scoutYearId));
        $this->assertSame(0, $this->repository->countForYear($this->scoutYearId));
        $this->assertSame([], $this->repository->findAllForYear($this->scoutYearId));
    }

    public function testCaptureOnAnEmptyRosterStoresAnEmptySnapshotRatherThanNothing(): void
    {
        $snapshot = $this->repository->capture($this->scoutYearId, new \DateTimeImmutable());

        $this->assertSame(0, $snapshot->memberCount);
        $this->assertSame(1, $this->repository->countForYear($this->scoutYearId));
        $this->assertSame([], $this->repository->findMembers($snapshot->id));
    }
}
