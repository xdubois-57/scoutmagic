<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Modules\Registration\Repository\SlotCapacityRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SlotCapacityRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SlotCapacityRepository $repository;
    private int $branchId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->repository = new SlotCapacityRepository($this->pdo);
        $this->branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
    }

    public function testUpsertThenCapacityForRoundTrips(): void
    {
        $this->repository->upsert($this->branchId, 1, 20);
        $this->assertSame(20, $this->repository->capacityFor($this->branchId, 1));

        $this->repository->upsert($this->branchId, 1, 25);
        $this->assertSame(25, $this->repository->capacityFor($this->branchId, 1));
    }

    public function testCapacityForUnknownSlotIsZero(): void
    {
        $this->assertSame(0, $this->repository->capacityFor($this->branchId, 3));
    }

    public function testFindAllAsMapIsKeyedByBranchThenYear(): void
    {
        $this->repository->upsert($this->branchId, 1, 20);
        $this->repository->upsert($this->branchId, 2, 15);

        $map = $this->repository->findAllAsMap();
        $this->assertSame(20, $map[$this->branchId][1]);
        $this->assertSame(15, $map[$this->branchId][2]);
    }
}
