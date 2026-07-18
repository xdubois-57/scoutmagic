<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Repository;

use Core\Database\Connection;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class TrombinoscopeRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private TrombinoscopeRepository $repository;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo->exec('CREATE TABLE trombinoscope_function_flags (
            function_id INTEGER PRIMARY KEY,
            is_lead INTEGER NOT NULL DEFAULT 0
        )');
        $this->repository = new TrombinoscopeRepository(Connection::withPdo($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ECL', 'Éclaireurs', 30)");
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id) VALUES (?, ?)');
        $stmt->execute(['ECL01', $branchId]);
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function createFunction(string $code, string $role): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)');
        $stmt->execute([$code, $code, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createStaffMember(string $deskId, int $functionId, int $sectionId, bool $active = true): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc', $active ? 1 : 0]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberYearId;
    }

    public function testReturnsChiefAndAdminButNotAnimeFunctions(): void
    {
        $chiefFn = $this->createFunction('CHEF', 'chief');
        $animeFn = $this->createFunction('SCOUT', 'identified');

        $chiefMy = $this->createStaffMember('D1', $chiefFn, $this->sectionId);
        $this->createStaffMember('D2', $animeFn, $this->sectionId);

        $result = $this->repository->getEligibleStaffForSection($this->sectionId, $this->scoutYearId);

        $this->assertCount(1, $result);
        $this->assertSame($chiefMy, $result[0]['member_year_id']);
    }

    public function testExcludesInactiveMembers(): void
    {
        $chiefFn = $this->createFunction('CHEF', 'chief');
        $this->createStaffMember('D1', $chiefFn, $this->sectionId, active: false);

        $result = $this->repository->getEligibleStaffForSection($this->sectionId, $this->scoutYearId);

        $this->assertEmpty($result);
    }

    public function testMarksLeadFunctionMembersAsLead(): void
    {
        $leadFn = $this->createFunction('RESP', 'admin');
        $stmt = $this->pdo->prepare('INSERT INTO trombinoscope_function_flags (function_id, is_lead) VALUES (?, 1)');
        $stmt->execute([$leadFn]);

        $regularFn = $this->createFunction('CHEF', 'chief');

        $leadMy = $this->createStaffMember('D1', $leadFn, $this->sectionId);
        $regularMy = $this->createStaffMember('D2', $regularFn, $this->sectionId);

        $result = $this->repository->getEligibleStaffForSection($this->sectionId, $this->scoutYearId);
        $byMember = [];
        foreach ($result as $row) {
            $byMember[$row['member_year_id']] = $row['is_lead'];
        }

        $this->assertTrue($byMember[$leadMy]);
        $this->assertFalse($byMember[$regularMy]);
    }
}
