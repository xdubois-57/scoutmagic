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

    /**
     * A slot nobody configured has NO capacity — not a capacity of zero.
     * Returning 0 here is what used to make an unconfigured branch read as
     * a full one all the way up to the public page.
     */
    public function testCapacityForUnknownSlotIsNullNotZero(): void
    {
        $this->assertNull($this->repository->capacityFor($this->branchId, 3));
    }

    public function testANullCapacityRoundTripsAsNullAndAZeroAsZero(): void
    {
        $this->repository->upsert($this->branchId, 1, null);
        $this->repository->upsert($this->branchId, 2, 0);

        $this->assertNull($this->repository->capacityFor($this->branchId, 1), 'an emptied box is "pas de limite"');
        $this->assertSame(0, $this->repository->capacityFor($this->branchId, 2), 'a typed 0 is "branche fermée"');

        $map = $this->repository->findAllAsMap();
        $this->assertNull($map[$this->branchId][1]);
        $this->assertSame(0, $map[$this->branchId][2]);
        // Both rows exist; only their values differ. `array_key_exists`
        // rather than isset(), which cannot tell a null value from a
        // missing key — the very confusion under test.
        $this->assertTrue(array_key_exists(1, $map[$this->branchId]));
        $this->assertTrue(array_key_exists(2, $map[$this->branchId]));
    }

    public function testUpsertCanClearAnExistingCapacityBackToNoLimit(): void
    {
        $this->repository->upsert($this->branchId, 1, 20);
        $this->repository->upsert($this->branchId, 1, null);

        $this->assertNull($this->repository->capacityFor($this->branchId, 1));
    }

    public function testInsertIfMissingCreatesTheRowOnceAndNeverRewritesIt(): void
    {
        $this->assertTrue($this->repository->insertIfMissing($this->branchId, 1, 15));
        $this->assertSame(15, $this->repository->capacityFor($this->branchId, 1));

        $this->assertFalse($this->repository->insertIfMissing($this->branchId, 1, 15));
        $this->assertSame(15, $this->repository->capacityFor($this->branchId, 1));
    }

    /**
     * The decisive case: a chief who emptied a box stored NULL on purpose,
     * and seeding must leave that alone. A row exists, so nothing is
     * written — even though its value is null.
     */
    public function testInsertIfMissingLeavesADeliberatelyEmptiedSlotEmpty(): void
    {
        $this->repository->upsert($this->branchId, 1, null);

        $this->assertFalse($this->repository->insertIfMissing($this->branchId, 1, 15));
        $this->assertNull($this->repository->capacityFor($this->branchId, 1));
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
