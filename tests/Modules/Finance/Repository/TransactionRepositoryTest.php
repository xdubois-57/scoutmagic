<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class TransactionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private TransactionRepository $repository;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new TransactionRepository($this->pdo, $encryption);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();

        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    public function testCreateAndFindByIdRoundTripsEncryptedLabel(): void
    {
        $id = $this->repository->create(
            $this->accountId, $this->fiscalYearId, 'REF001', '2026-10-01',
            'VIR/456 Jean Dupont', -35.98, null, null, Transaction::SOURCE_MANUAL, null
        );

        $transaction = $this->repository->findById($id);
        $this->assertNotNull($transaction);
        $this->assertSame('VIR/456 Jean Dupont', $transaction->label);
        $this->assertSame(-35.98, $transaction->amount);
    }

    public function testLabelIsStoredEncryptedNotInPlaintext(): void
    {
        $id = $this->repository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01',
            'Communication confidentielle', -10.0, null, null, Transaction::SOURCE_MANUAL, null
        );

        $stmt = $this->pdo->prepare('SELECT label FROM finance_transactions WHERE id = ?');
        $stmt->execute([$id]);
        $rawLabel = $stmt->fetchColumn();

        $this->assertStringNotContainsString('Communication confidentielle', (string) $rawLabel);
    }

    public function testUniqueAccountBankReferenceConstraint(): void
    {
        $this->repository->create($this->accountId, $this->fiscalYearId, 'REF001', '2026-10-01', 'A', -1.0, null, null, Transaction::SOURCE_IMPORT, null);

        $this->expectException(\PDOException::class);
        $this->repository->create($this->accountId, $this->fiscalYearId, 'REF001', '2026-10-02', 'B', -2.0, null, null, Transaction::SOURCE_IMPORT, null);
    }

    public function testInsertOrSkipInsertsNewAndSkipsDuplicate(): void
    {
        $inserted = $this->repository->insertOrSkip($this->accountId, $this->fiscalYearId, 'REF002', '2026-10-01', 'A', -5.0, null);
        $this->assertTrue($inserted);

        $insertedAgain = $this->repository->insertOrSkip($this->accountId, $this->fiscalYearId, 'REF002', '2026-10-01', 'A', -5.0, null);
        $this->assertFalse($insertedAgain);

        $this->assertCount(1, $this->repository->findByAccountId($this->accountId));
    }

    public function testUpdateEditableFieldsOnlyTouchesCategoryCommentFiscalYear(): void
    {
        $id = $this->repository->create($this->accountId, $this->fiscalYearId, 'REF003', '2026-10-01', 'Label original', -20.0, null, null, Transaction::SOURCE_IMPORT, null);

        $this->repository->updateEditableFields($id, 42, 'Remboursement matériel', $this->fiscalYearId);

        $transaction = $this->repository->findById($id);
        $this->assertSame(42, $transaction->categoryId);
        $this->assertSame('Remboursement matériel', $transaction->comment);
        $this->assertSame('Label original', $transaction->label);
        $this->assertSame(-20.0, $transaction->amount);
    }

    public function testFindByAccountAfterDateExcludesEarlierAndSameDate(): void
    {
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R1', '2026-10-01', 'A', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R2', '2026-10-05', 'B', -2.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R3', '2026-10-10', 'C', -3.0, null, null, Transaction::SOURCE_MANUAL, null);

        $after = $this->repository->findByAccountAfterDate($this->accountId, '2026-10-05');
        $this->assertCount(1, $after);
        $this->assertSame('2026-10-10', $after[0]->transactionDate);
    }

    public function testDeleteAllForAccountReturnsCountAndClearsTable(): void
    {
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R1', '2026-10-01', 'A', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R2', '2026-10-05', 'B', -2.0, null, null, Transaction::SOURCE_MANUAL, null);

        $deleted = $this->repository->deleteAllForAccount($this->accountId);
        $this->assertSame(2, $deleted);
        $this->assertCount(0, $this->repository->findByAccountId($this->accountId));
    }

    public function testFindByIdsReturnsMatchingTransactionsWithDecryptedLabels(): void
    {
        $id1 = $this->repository->create($this->accountId, $this->fiscalYearId, 'R1', '2026-10-01', 'Achat A', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $id2 = $this->repository->create($this->accountId, $this->fiscalYearId, 'R2', '2026-10-02', 'Achat B', -2.0, null, null, Transaction::SOURCE_MANUAL, null);
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R3', '2026-10-03', 'Achat C', -3.0, null, null, Transaction::SOURCE_MANUAL, null);

        $results = $this->repository->findByIds([$id1, $id2]);

        $this->assertCount(2, $results);
        $labels = array_map(fn(Transaction $t) => $t->label, $results);
        $this->assertContains('Achat A', $labels);
        $this->assertContains('Achat B', $labels);
    }

    public function testFindByIdsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->repository->findByIds([]));
    }

    public function testFindIdsByAccountAndFiscalYear(): void
    {
        $id1 = $this->repository->create($this->accountId, $this->fiscalYearId, 'R1', '2026-10-01', 'A', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $otherFiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, 'other', '2020-01-01', '2020-12-31');
        $this->repository->create($this->accountId, $otherFiscalYearId, 'R2', '2020-06-01', 'B', -2.0, null, null, Transaction::SOURCE_MANUAL, null);

        $ids = $this->repository->findIdsByAccountAndFiscalYear($this->accountId, $this->fiscalYearId);

        $this->assertSame([$id1], $ids);
    }

    public function testDeleteByAccountAndFiscalYearOnlyTouchesThatFiscalYear(): void
    {
        $this->repository->create($this->accountId, $this->fiscalYearId, 'R1', '2026-10-01', 'A', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $otherFiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, 'other', '2020-01-01', '2020-12-31');
        $this->repository->create($this->accountId, $otherFiscalYearId, 'R2', '2020-06-01', 'B', -2.0, null, null, Transaction::SOURCE_MANUAL, null);

        $deleted = $this->repository->deleteByAccountAndFiscalYear($this->accountId, $this->fiscalYearId);

        $this->assertSame(1, $deleted);
        $remaining = $this->repository->findByAccountId($this->accountId);
        $this->assertCount(1, $remaining);
        $this->assertSame('B', $remaining[0]->label);
    }
}
