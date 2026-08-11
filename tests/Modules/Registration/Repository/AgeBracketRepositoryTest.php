<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Modules\Registration\Repository\AgeBracketRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
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

    public function testUpsertCreatesThenUpdatesInPlace(): void
    {
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);

        $this->repository->upsert($branchId, 6, 2);
        $bracket = $this->repository->findForBranch($branchId);
        $this->assertNotNull($bracket);
        $this->assertSame(6, $bracket->entryAge);
        $this->assertSame(2, $bracket->durationYears);

        $this->repository->upsert($branchId, 7, 3);
        $updated = $this->repository->findForBranch($branchId);
        $this->assertSame(7, $updated->entryAge);
        $this->assertSame(3, $updated->durationYears);
    }

    public function testFindAllOrderedFollowsCanonicalBranchSortOrder(): void
    {
        $pionniersId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'PION', 'Pionniers', 40);
        $baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);

        $this->repository->upsert($pionniersId, 16, 2);
        $this->repository->upsert($baladinsId, 6, 2);

        $ordered = $this->repository->findAllOrdered();
        $this->assertCount(2, $ordered);
        $this->assertSame('Baladins', $ordered[0]->branchLabel);
        $this->assertSame('Pionniers', $ordered[1]->branchLabel);
    }

    public function testFindForBranchReturnsNullWhenNotConfigured(): void
    {
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        $this->assertNull($this->repository->findForBranch($branchId));
    }
}
