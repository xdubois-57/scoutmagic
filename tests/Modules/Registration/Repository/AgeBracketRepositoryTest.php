<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Modules\Registration\Repository\AgeBracketRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * AgeBracketRepository has nothing of its own to store any more: every
 * bracket is derived, at read time, from the real Desk-imported
 * age_branches table matched against Core\Member\MemberYearService::
 * BRANCHES — the same federation age ranges member_stats already uses.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AgeBracketRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AgeBracketRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->repository = new AgeBracketRepository($this->pdo);
    }

    public function testFindForBranchDerivesAgeRangeFromCentralBranches(): void
    {
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);

        $bracket = $this->repository->findForBranch($branchId);

        $this->assertNotNull($bracket);
        $this->assertSame('Baladins', $bracket->branchLabel);
        $this->assertSame(6, $bracket->entryAge);
        $this->assertSame(2, $bracket->durationYears);
    }

    public function testFindForBranchReturnsNullForNonexistentBranch(): void
    {
        $this->assertNull($this->repository->findForBranch(999));
    }

    public function testFindForBranchReturnsNullForABranchOutsideTheFourAnimeBranches(): void
    {
        $staffUniteId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'SU', "Staff d'unité", 50);

        $this->assertNull($this->repository->findForBranch($staffUniteId));
    }

    public function testFindAllOrderedFollowsCanonicalBranchSortOrder(): void
    {
        $pionniersId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'PION', 'Pionniers', 40);
        $baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);

        $ordered = $this->repository->findAllOrdered();

        $this->assertCount(2, $ordered);
        $this->assertSame('Baladins', $ordered[0]->branchLabel);
        $this->assertSame(6, $ordered[0]->entryAge);
        $this->assertSame(2, $ordered[0]->durationYears);
        $this->assertSame('Pionniers', $ordered[1]->branchLabel);
        $this->assertSame(16, $ordered[1]->entryAge);
        $this->assertSame(2, $ordered[1]->durationYears);
    }

    public function testFindAllOrderedExcludesBranchesOutsideTheFourAnimeBranches(): void
    {
        RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        RegistrationTestHelper::insertAgeBranch($this->pdo, 'SU', "Staff d'unité", 50);
        RegistrationTestHelper::insertAgeBranch($this->pdo, 'RT', 'Route', 60);

        $ordered = $this->repository->findAllOrdered();

        $this->assertCount(1, $ordered);
        $this->assertSame('Baladins', $ordered[0]->branchLabel);
    }
}
