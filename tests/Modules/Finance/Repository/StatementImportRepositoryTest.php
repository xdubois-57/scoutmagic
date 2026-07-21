<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\Repository\StatementImportRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class StatementImportRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private StatementImportRepository $repository;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new StatementImportRepository($this->pdo);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create($this->accountId, 'bnp', 'releve_octobre.csv', 42, 40, 2, 7);

        $import = $this->repository->findById($id);
        $this->assertNotNull($import);
        $this->assertSame('bnp', $import->bankCode);
        $this->assertSame('releve_octobre.csv', $import->originalFilename);
        $this->assertSame(42, $import->linesTotal);
        $this->assertSame(40, $import->linesNew);
        $this->assertSame(2, $import->linesDuplicate);
        $this->assertSame(7, $import->importedBy);
    }

    public function testHasAnyForAccountFalseThenTrue(): void
    {
        $this->assertFalse($this->repository->hasAnyForAccount($this->accountId));

        $this->repository->create($this->accountId, 'bnp', 'a.csv', 1, 1, 0, null);

        $this->assertTrue($this->repository->hasAnyForAccount($this->accountId));
    }

    public function testFindByAccountId(): void
    {
        $this->repository->create($this->accountId, 'bnp', 'a.csv', 1, 1, 0, null);
        $this->repository->create($this->accountId, 'bnp', 'b.csv', 2, 2, 0, null);

        $this->assertCount(2, $this->repository->findByAccountId($this->accountId));
    }

    public function testFindMostRecentForAccountReturnsNullWhenNone(): void
    {
        $this->assertNull($this->repository->findMostRecentForAccount($this->accountId));
    }

    public function testFindMostRecentForAccountReturnsLatestImport(): void
    {
        $this->repository->create($this->accountId, 'bnp', 'a.csv', 1, 1, 0, null);
        $latestId = $this->repository->create($this->accountId, 'bnp', 'b.csv', 2, 2, 0, null);

        $latest = $this->repository->findMostRecentForAccount($this->accountId);

        $this->assertNotNull($latest);
        $this->assertSame($latestId, $latest->id);
    }

    public function testFindMostRecentForAccountIgnoresOtherAccounts(): void
    {
        $this->repository->create($this->accountId, 'bnp', 'a.csv', 1, 1, 0, null);
        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Autre', 'bank')");
        $stmt->execute();
        $otherAccountId = (int) $this->pdo->lastInsertId();

        $this->assertNull($this->repository->findMostRecentForAccount($otherAccountId));
    }
}
