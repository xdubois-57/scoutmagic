<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocation;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivableAllocationRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReceivableAllocationRepository $repository;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new ReceivableAllocationRepository($this->pdo);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->transactions = new TransactionRepository($this->pdo, $encryption);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
    }

    public function testCreateReturnsAnIdAndTheRowReadsBack(): void
    {
        $id = $this->repository->create($this->transaction(45.0), $this->receivable(), 4500, ReceivableAllocation::SOURCE_AUTO, null);

        $allocation = $this->repository->findById($id);
        $this->assertNotNull($allocation);
        $this->assertSame(4500, $allocation->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_AUTO, $allocation->source);
        $this->assertNull($allocation->createdBy);
        $this->assertTrue($allocation->isSettlement());
        $this->assertFalse($allocation->isManual());
    }

    /**
     * The timestamp is written from PHP, not left to the column default:
     * SQLite's CURRENT_TIMESTAMP is UTC while the application runs on
     * Europe/Brussels, so a default-written row would be an hour or two
     * behind everything it is ever compared against.
     */
    public function testTheCreationTimestampIsOnTheApplicationsOwnClock(): void
    {
        $before = date('Y-m-d H:i:s', time() - 5);
        $id = $this->repository->create($this->transaction(45.0), $this->receivable(), 4500, ReceivableAllocation::SOURCE_AUTO, null);

        $allocation = $this->repository->findById($id);
        $this->assertNotNull($allocation);
        $this->assertGreaterThanOrEqual($before, $allocation->createdAt);
    }

    public function testFindByIdReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    public function testFindByReceivableIdReturnsEveryAllocationInWritingOrder(): void
    {
        $receivableId = $this->receivable();
        $this->repository->create($this->transaction(20.0), $receivableId, 2000, ReceivableAllocation::SOURCE_AUTO, null);
        $this->repository->create($this->transaction(25.0), $receivableId, 2500, ReceivableAllocation::SOURCE_MANUAL, 7);

        $found = $this->repository->findByReceivableId($receivableId);

        $this->assertSame([2000, 2500], array_map(static fn($a) => $a->amountCents, $found));
        $this->assertSame(7, $found[1]->createdBy);
        $this->assertTrue($found[1]->isManual());
    }

    public function testFindByReceivableIdsGroupsByReceivable(): void
    {
        $first = $this->receivable();
        $second = $this->receivable();
        $this->repository->create($this->transaction(20.0), $first, 2000, ReceivableAllocation::SOURCE_AUTO, null);
        $this->repository->create($this->transaction(25.0), $second, 2500, ReceivableAllocation::SOURCE_AUTO, null);

        $found = $this->repository->findByReceivableIds([$first, $second]);

        $this->assertSame([2000], array_map(static fn($a) => $a->amountCents, $found[$first]));
        $this->assertSame([2500], array_map(static fn($a) => $a->amountCents, $found[$second]));
    }

    public function testFindByReceivableIdsOnAnEmptyListAsksTheDatabaseNothing(): void
    {
        $this->assertSame([], $this->repository->findByReceivableIds([]));
        $this->assertSame([], $this->repository->findByTransactionIds([]));
    }

    public function testFindByTransactionIdAndIdsSeeTheSameRows(): void
    {
        $transactionId = $this->transaction(128.25);
        $this->repository->create($transactionId, $this->receivable(), 4500, ReceivableAllocation::SOURCE_MANUAL, 7);
        $this->repository->create($transactionId, $this->receivable(), 3825, ReceivableAllocation::SOURCE_MANUAL, 7);

        $this->assertCount(2, $this->repository->findByTransactionId($transactionId));
        $this->assertCount(2, $this->repository->findByTransactionIds([$transactionId])[$transactionId]);
    }

    public function testFindPairFindsExactlyOneRowOrNothing(): void
    {
        $transactionId = $this->transaction(45.0);
        $receivableId = $this->receivable();
        $this->assertNull($this->repository->findPair($transactionId, $receivableId));

        $this->repository->create($transactionId, $receivableId, 4500, ReceivableAllocation::SOURCE_AUTO, null);

        $pair = $this->repository->findPair($transactionId, $receivableId);
        $this->assertNotNull($pair);
        $this->assertSame(4500, $pair->amountCents);
    }

    public function testUpdateMovesTheAmountAndTheProvenanceTogether(): void
    {
        $transactionId = $this->transaction(45.0);
        $receivableId = $this->receivable();
        $id = $this->repository->create($transactionId, $receivableId, 4500, ReceivableAllocation::SOURCE_AUTO, null);

        $this->repository->update($id, 2000, ReceivableAllocation::SOURCE_MANUAL, 7);

        $allocation = $this->repository->findById($id);
        $this->assertNotNull($allocation);
        $this->assertSame(2000, $allocation->amountCents);
        $this->assertTrue($allocation->isManual());
        $this->assertSame(7, $allocation->createdBy);
    }

    public function testANegativeAmountReadsBackAsARefund(): void
    {
        $id = $this->repository->create($this->transaction(-6.75), $this->receivable(), -675, ReceivableAllocation::SOURCE_MANUAL, 7);

        $allocation = $this->repository->findById($id);
        $this->assertNotNull($allocation);
        $this->assertTrue($allocation->isRefund());
        $this->assertFalse($allocation->isSettlement());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create($this->transaction(45.0), $this->receivable(), 4500, ReceivableAllocation::SOURCE_AUTO, null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    /**
     * The unique pair is what makes the automatic pass idempotent — it
     * revises its own row rather than adding a second one.
     */
    public function testTheSamePairCannotBeWrittenTwice(): void
    {
        $transactionId = $this->transaction(45.0);
        $receivableId = $this->receivable();
        $this->repository->create($transactionId, $receivableId, 4500, ReceivableAllocation::SOURCE_AUTO, null);

        $this->expectException(\PDOException::class);
        $this->repository->create($transactionId, $receivableId, 1000, ReceivableAllocation::SOURCE_AUTO, null);
    }

    private function receivable(): int
    {
        static $sequence = 0;
        $sequence++;

        return $this->receivables->create('finance', $sequence, $this->accountId, 4500, '+++123/4567/8901' . ($sequence % 10) . '+++', null);
    }

    private function transaction(float $amount): int
    {
        return $this->transactions->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            '2026-02-18',
            'Virement',
            $amount,
            null,
            null,
            'import',
            null
        );
    }
}
