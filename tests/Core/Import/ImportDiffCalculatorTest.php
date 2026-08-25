<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\ImportDiff;
use Core\Import\ImportDiffCalculator;
use Core\Import\NewMappings;
use Core\Import\RosterSnapshot;
use Core\Import\RosterSnapshotRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The diff between two consecutive roster snapshots of one scout year.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ImportDiffCalculatorTest extends TestCase
{
    private \PDO $pdo;
    private ImportDiffCalculator $calculator;
    private RosterSnapshotRepository $snapshots;
    private int $scoutYearId;
    private int $animateurId;
    private int $chiefId;
    private int $sectionA;
    private int $sectionB;
    private int $feeNormal;
    private int $feeFamily;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->snapshots = new RosterSnapshotRepository($this->pdo);
        $this->calculator = new ImportDiffCalculator($this->snapshots);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->animateurId = $this->createFunction('ANIM', 'identified');
        $this->chiefId = $this->createFunction('CU', 'admin');
        $this->sectionA = $this->createSection('BAL1');
        $this->sectionB = $this->createSection('LOUV1');
        $this->feeNormal = $this->createFeeCategory('NORMAL');
        $this->feeFamily = $this->createFeeCategory('FAMILLE');
    }

    /* ------------------------------------------------------------------ */
    /* No point of comparison                                              */
    /* ------------------------------------------------------------------ */

    public function testTheFirstImportOfASeasonHasNoPointOfComparison(): void
    {
        $current = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA]]);

        $diff = $this->calculator->calculate($current, null, null, new NewMappings());

        $this->assertFalse($diff->available);
        $this->assertSame(ImportDiff::UNAVAILABLE_FIRST_OF_SEASON, $diff->unavailableReason);
        // Not an empty diff: 255 members are the starting point, never 255 arrivals.
        $this->assertSame([], $diff->arrivedMemberIds);
        $this->assertFalse($diff->isEmpty());
    }

    public function testAPurgedPredecessorIsUnavailableAndSaysSo(): void
    {
        $current = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA]]);

        // There WAS an import before this one; its snapshot has been
        // taken by the retention purge.
        $diff = $this->calculator->calculate($current, null, 42, new NewMappings());

        $this->assertFalse($diff->available);
        $this->assertSame(ImportDiff::UNAVAILABLE_PREDECESSOR_PURGED, $diff->unavailableReason);
    }

    /* ------------------------------------------------------------------ */
    /* Movements                                                           */
    /* ------------------------------------------------------------------ */

    public function testArrivalsAndDepartures(): void
    {
        $before = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionA],
            ['member' => 2, 'section' => $this->sectionA],
        ]);
        $after = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionA],
            ['member' => 3, 'section' => $this->sectionA],
        ]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertTrue($diff->available);
        $this->assertSame([$this->memberId(3)], $diff->arrivedMemberIds);
        $this->assertSame([$this->memberId(2)], $diff->departedMemberIds);
    }

    public function testAnArrivalIsNotAlsoFourChanges(): void
    {
        $before = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA]]);
        $after = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionA],
            ['member' => 2, 'section' => $this->sectionB, 'fee' => $this->feeFamily],
        ]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertSame([$this->memberId(2)], $diff->arrivedMemberIds);
        $this->assertSame([], $diff->sectionChanges);
        $this->assertSame([], $diff->feeCategoryChanges);
        $this->assertSame([], $diff->functionChanges);
    }

    public function testSectionFunctionRoleAndFeeChanges(): void
    {
        $before = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionA, 'function' => $this->animateurId, 'role' => 'identified', 'fee' => $this->feeNormal],
        ]);
        $after = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionB, 'function' => $this->chiefId, 'role' => 'admin', 'fee' => $this->feeFamily],
        ]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());
        $memberId = $this->memberId(1);

        $this->assertSame(['from' => $this->sectionA, 'to' => $this->sectionB], $diff->sectionChanges[$memberId]);
        $this->assertSame(['from' => $this->animateurId, 'to' => $this->chiefId], $diff->functionChanges[$memberId]);
        $this->assertSame(['from' => 'identified', 'to' => 'admin'], $diff->roleChanges[$memberId]);
        $this->assertSame(['from' => $this->feeNormal, 'to' => $this->feeFamily], $diff->feeCategoryChanges[$memberId]);
    }

    public function testAFunctionChangeWithoutARoleChangeIsStillReported(): void
    {
        $intendant = $this->createFunction('INT', 'identified');
        $before = $this->snapshotOf([['member' => 1, 'function' => $this->animateurId, 'role' => 'identified']]);
        $after = $this->snapshotOf([['member' => 1, 'function' => $intendant, 'role' => 'identified']]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertArrayHasKey($this->memberId(1), $diff->functionChanges);
        $this->assertSame([], $diff->roleChanges);
    }

    public function testReimportingTheSameRosterChangesNothing(): void
    {
        $rows = [
            ['member' => 1, 'section' => $this->sectionA, 'function' => $this->animateurId, 'role' => 'identified'],
            ['member' => 2, 'section' => $this->sectionB, 'function' => $this->chiefId, 'role' => 'admin'],
        ];

        $diff = $this->calculator->calculate($this->snapshotOf($rows), $this->snapshotOf($rows), 1, new NewMappings());

        $this->assertTrue($diff->available);
        $this->assertTrue($diff->isEmpty());
    }

    /* ------------------------------------------------------------------ */
    /* Access impact                                                       */
    /* ------------------------------------------------------------------ */

    public function testAdminsGainedAndLostAreIsolated(): void
    {
        $before = $this->snapshotOf([
            ['member' => 1, 'function' => $this->chiefId, 'role' => 'admin'],
            ['member' => 2, 'function' => $this->animateurId, 'role' => 'identified'],
        ]);
        $after = $this->snapshotOf([
            ['member' => 1, 'function' => $this->animateurId, 'role' => 'identified'],
            ['member' => 2, 'function' => $this->chiefId, 'role' => 'admin'],
        ]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertSame([$this->memberId(2)], $diff->adminGainedMemberIds);
        $this->assertSame([$this->memberId(1)], $diff->adminLostMemberIds);
    }

    public function testADepartingChefDUniteCountsAsAnAdminLost(): void
    {
        // They lose the Configuration by disappearing, which the
        // role-change loop never sees.
        $before = $this->snapshotOf([
            ['member' => 1, 'function' => $this->chiefId, 'role' => 'admin'],
            ['member' => 2, 'function' => $this->chiefId, 'role' => 'admin'],
        ]);
        $after = $this->snapshotOf([['member' => 2, 'function' => $this->chiefId, 'role' => 'admin']]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertSame([$this->memberId(1)], $diff->adminLostMemberIds);
        $this->assertSame([$this->memberId(1)], $diff->departedMemberIds);
    }

    public function testAnArrivingChefDUniteCountsAsAnAdminGained(): void
    {
        $before = $this->snapshotOf([['member' => 1, 'function' => $this->animateurId, 'role' => 'identified']]);
        $after = $this->snapshotOf([
            ['member' => 1, 'function' => $this->animateurId, 'role' => 'identified'],
            ['member' => 2, 'function' => $this->chiefId, 'role' => 'admin'],
        ]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertSame([$this->memberId(2)], $diff->adminGainedMemberIds);
    }

    public function testNewFunctionsAreCarriedThroughForQualification(): void
    {
        $newFunction = $this->createFunction('EQUIPIER-ADJ', 'identified');
        $before = $this->snapshotOf([['member' => 1]]);
        $after = $this->snapshotOf([['member' => 1]]);

        $diff = $this->calculator->calculate(
            $after,
            $before,
            1,
            new NewMappings(functionIds: [$newFunction], sectionIds: [$this->sectionB], branchIds: [], feeCategoryIds: [$this->feeFamily])
        );

        $this->assertSame([$newFunction], $diff->newFunctionIds);
        $this->assertSame([$this->sectionB], $diff->newSectionIds);
        $this->assertSame([$this->feeFamily], $diff->newFeeCategoryIds);
        // A new function is an access matter, not a neutral addition: it
        // arrives at the lowest role and its holders see nothing until it
        // is qualified.
        $this->assertSame(1, $diff->accessImpactCount());
        $this->assertFalse($diff->isEmpty());
    }

    /* ------------------------------------------------------------------ */
    /* Structural impact                                                   */
    /* ------------------------------------------------------------------ */

    public function testASectionLosingItsLastMemberIsReported(): void
    {
        $before = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionA],
            ['member' => 2, 'section' => $this->sectionB],
        ]);
        $after = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA]]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertSame([$this->sectionB], $diff->sectionsGoneInactiveIds);
        $this->assertSame([], $diff->sectionsGoneActiveIds);
    }

    public function testASectionGainingItsFirstMemberIsReported(): void
    {
        $before = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA]]);
        $after = $this->snapshotOf([
            ['member' => 1, 'section' => $this->sectionA],
            ['member' => 2, 'section' => $this->sectionB],
        ]);

        $diff = $this->calculator->calculate($after, $before, 1, new NewMappings());

        $this->assertSame([$this->sectionB], $diff->sectionsGoneActiveIds);
        $this->assertSame([], $diff->sectionsGoneInactiveIds);
    }

    /* ------------------------------------------------------------------ */
    /* What it carries, and what it must not                               */
    /* ------------------------------------------------------------------ */

    public function testItSurvivesARoundTripThroughStorage(): void
    {
        $before = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA, 'function' => $this->chiefId, 'role' => 'admin']]);
        $after = $this->snapshotOf([['member' => 1, 'section' => $this->sectionB, 'function' => $this->animateurId, 'role' => 'identified']]);

        $diff = $this->calculator->calculate($after, $before, 7, new NewMappings(functionIds: [$this->animateurId]));
        $json = json_encode($diff->toArray());
        $this->assertIsString($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $restored = ImportDiff::fromArray($decoded);

        $this->assertEquals($diff->toArray(), $restored->toArray());
        $this->assertSame(7, $restored->previousImportId);
    }

    public function testAStoredDiffFromAnOlderShapeStillOpens(): void
    {
        $restored = ImportDiff::fromArray(['available' => true, 'arrived_member_ids' => [4, 5]]);

        $this->assertTrue($restored->available);
        $this->assertSame([4, 5], $restored->arrivedMemberIds);
        $this->assertSame([], $restored->roleChanges);
    }

    public function testItCarriesNoPersonalData(): void
    {
        $before = $this->snapshotOf([['member' => 1, 'section' => $this->sectionA]]);
        $after = $this->snapshotOf([['member' => 2, 'section' => $this->sectionB]]);

        $encoded = json_encode($this->calculator->calculate($after, $before, 1, new NewMappings())->toArray());

        $this->assertIsString($encoded);
        // Foreign keys and codes only — never a name, never a Desk id.
        $this->assertStringNotContainsString('Dupont', $encoded);
        $this->assertStringNotContainsString('BAL1', $encoded);
        $this->assertStringNotContainsString('T00', $encoded);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A snapshot holding exactly the rows given, written directly.
     *
     * The calculator reads snapshots, so a test that built them by
     * running whole imports would be testing the import, not the diff.
     *
     * @param array<int, array{member: int, section?: int, function?: int, role?: string, fee?: int}> $rows
     */
    private function snapshotOf(array $rows): RosterSnapshot
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshots (scout_year_id, taken_at, member_count) VALUES (?, ?, ?)'
        );
        $stmt->execute([$this->scoutYearId, '2026-01-01 10:00:00', count($rows)]);
        $snapshotId = (int) $this->pdo->lastInsertId();

        foreach ($rows as $row) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO fees_roster_snapshot_members
                    (snapshot_id, member_id, fee_category_id, section_id, function_role, function_id, leaving)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->execute([
                $snapshotId,
                $this->memberId($row['member']),
                $row['fee'] ?? null,
                $row['section'] ?? null,
                $row['role'] ?? null,
                $row['function'] ?? null,
            ]);
        }

        return new RosterSnapshot($snapshotId, $this->scoutYearId, new \DateTimeImmutable('2026-01-01 10:00:00'), count($rows));
    }

    /** members.id for a stable test-local number, created on first use. */
    private function memberId(int $n): int
    {
        $deskId = 'T' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);

        $stmt = $this->pdo->prepare('SELECT id FROM members WHERE desk_id = ?');
        $stmt->execute([$deskId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createFunction(string $deskCode, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $deskCode, $role]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label) VALUES (?, ?)');
        $stmt->execute(['B-' . $deskCode, $deskCode]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createFeeCategory(string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO fee_categories (desk_code, label) VALUES (?, ?)');
        $stmt->execute([$deskCode, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }
}
