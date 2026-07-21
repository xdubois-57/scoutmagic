<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class BalanceCheckpointRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BalanceCheckpointRepository $repository;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new BalanceCheckpointRepository($this->pdo);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    public function testHasAnyForAccountFalseThenTrue(): void
    {
        $this->assertFalse($this->repository->hasAnyForAccount($this->accountId));

        $this->repository->create($this->accountId, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);

        $this->assertTrue($this->repository->hasAnyForAccount($this->accountId));
    }

    public function testFindClosestBeforeReturnsMostRecentAtOrBeforeDate(): void
    {
        $this->repository->create($this->accountId, '2026-09-01', 500.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->repository->create($this->accountId, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->repository->create($this->accountId, '2026-11-01', 1500.0, BalanceCheckpoint::SOURCE_IMPORT);

        $checkpoint = $this->repository->findClosestBefore($this->accountId, '2026-10-15');
        $this->assertNotNull($checkpoint);
        $this->assertSame('2026-10-01', $checkpoint->checkpointDate);
        $this->assertSame(1000.0, $checkpoint->balance);
    }

    public function testFindClosestBeforeReturnsNullWhenNoneBeforeDate(): void
    {
        $this->repository->create($this->accountId, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);

        $this->assertNull($this->repository->findClosestBefore($this->accountId, '2026-01-01'));
    }

    public function testFindClosestBeforeIncludesExactDateMatch(): void
    {
        $this->repository->create($this->accountId, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_MANUAL);

        $checkpoint = $this->repository->findClosestBefore($this->accountId, '2026-10-01');
        $this->assertNotNull($checkpoint);
        $this->assertSame(1000.0, $checkpoint->balance);
    }

    public function testFindEarliestForAccountReturnsNullWhenNone(): void
    {
        $this->assertNull($this->repository->findEarliestForAccount($this->accountId));
    }

    public function testFindEarliestForAccountReturnsOldestByDate(): void
    {
        $this->repository->create($this->accountId, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->repository->create($this->accountId, '2026-09-01', 500.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->repository->create($this->accountId, '2026-11-01', 1500.0, BalanceCheckpoint::SOURCE_IMPORT);

        $earliest = $this->repository->findEarliestForAccount($this->accountId);

        $this->assertNotNull($earliest);
        $this->assertSame('2026-09-01', $earliest->checkpointDate);
        $this->assertSame(500.0, $earliest->balance);
    }

    public function testDeleteAllForAccountReturnsCount(): void
    {
        $this->repository->create($this->accountId, '2026-10-01', 1000.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->repository->create($this->accountId, '2026-11-01', 1500.0, BalanceCheckpoint::SOURCE_IMPORT);

        $deleted = $this->repository->deleteAllForAccount($this->accountId);
        $this->assertSame(2, $deleted);
        $this->assertFalse($this->repository->hasAnyForAccount($this->accountId));
    }
}
