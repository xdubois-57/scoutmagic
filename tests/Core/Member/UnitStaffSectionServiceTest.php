<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\UnitStaffSectionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class UnitStaffSectionServiceTest extends TestCase
{
    private \PDO $pdo;
    private UnitStaffSectionService $service;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new UnitStaffSectionService($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function createFunction(string $deskCode, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $deskCode, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberYearWithFunction(string $deskId, int $functionId, ?int $sectionId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberYearId;
    }

    public function testEnsureSectionCreatesBranchAndSection(): void
    {
        $sectionId = $this->service->ensureSection();

        $stmt = $this->pdo->prepare('SELECT * FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        $section = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($section);
        $this->assertSame('STAFFDU', $section['desk_code']);
        $this->assertSame(1, (int) $section['is_active']);

        $stmt = $this->pdo->prepare('SELECT * FROM age_branches WHERE id = ?');
        $stmt->execute([$section['age_branch_id']]);
        $branch = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('STAFFDU', $branch['desk_code']);
        $this->assertSame(50, (int) $branch['sort_order']);
    }

    public function testEnsureSectionIsIdempotent(): void
    {
        $firstId = $this->service->ensureSection();
        $secondId = $this->service->ensureSection();

        $this->assertSame($firstId, $secondId);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM sections WHERE desk_code = \'STAFFDU\'');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testEnsureSectionReactivatesADeactivatedSection(): void
    {
        $sectionId = $this->service->ensureSection();
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE id = {$sectionId}");

        $this->service->ensureSection();

        $stmt = $this->pdo->prepare('SELECT is_active FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSyncMembershipAssignsUnassignedAdminFunctionsToStaffdu(): void
    {
        $adminFunctionId = $this->createFunction('CU', 'admin');
        $memberYearId = $this->createMemberYearWithFunction('D1', $adminFunctionId, null);

        $this->service->syncMembership($this->scoutYearId);

        $staffduId = $this->service->ensureSection();
        $stmt = $this->pdo->prepare('SELECT section_id FROM member_functions WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertSame($staffduId, (int) $stmt->fetchColumn());
    }

    public function testSyncMembershipDoesNotTouchAdminFunctionsAlreadyInARealSection(): void
    {
        $branchId = $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn();
        if ($branchId === false) {
            $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 10)");
            $branchId = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id) VALUES ('BAL01', {$branchId})");
        $realSectionId = (int) $this->pdo->lastInsertId();

        $adminFunctionId = $this->createFunction('CU', 'admin');
        $memberYearId = $this->createMemberYearWithFunction('D1', $adminFunctionId, $realSectionId);

        $this->service->syncMembership($this->scoutYearId);

        $stmt = $this->pdo->prepare('SELECT section_id FROM member_functions WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertSame($realSectionId, (int) $stmt->fetchColumn());
    }

    public function testSyncMembershipClearsStaffduAssignmentWhenRoleNoLongerAdmin(): void
    {
        $adminFunctionId = $this->createFunction('CU', 'admin');
        $memberYearId = $this->createMemberYearWithFunction('D1', $adminFunctionId, null);
        $this->service->syncMembership($this->scoutYearId);

        // Role demoted away from admin.
        $this->pdo->exec("UPDATE functions SET role = 'chief' WHERE id = {$adminFunctionId}");

        $this->service->syncMembership($this->scoutYearId);

        $stmt = $this->pdo->prepare('SELECT section_id FROM member_functions WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertNull($stmt->fetchColumn());
    }

    public function testSyncMembershipIsIdempotent(): void
    {
        $adminFunctionId = $this->createFunction('CU', 'admin');
        $this->createMemberYearWithFunction('D1', $adminFunctionId, null);

        $this->service->syncMembership($this->scoutYearId);
        $this->service->syncMembership($this->scoutYearId);

        $staffduId = $this->service->ensureSection();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM member_functions WHERE section_id = ?');
        $stmt->execute([$staffduId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSyncMembershipIgnoresOtherScoutYears(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $otherYearId = (int) $this->pdo->lastInsertId();

        $adminFunctionId = $this->createFunction('CU', 'admin');
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $otherYearId, 'enc', 'enc']);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, NULL)');
        $stmt->execute([$memberYearId, $adminFunctionId]);

        $this->service->syncMembership($this->scoutYearId);

        $stmt = $this->pdo->prepare('SELECT section_id FROM member_functions WHERE member_year_id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertNull($stmt->fetchColumn());
    }
}
