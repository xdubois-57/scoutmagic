<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The movement/receipt join table. Every method is exercised here, per
 * AGENTS.md's "every Repository method must be tested against a test
 * database" — this table had no test file of its own, despite backing the
 * paperclip indicator, the receipt matching candidate pool, and the purge.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TransactionAttachmentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private TransactionAttachmentRepository $repository;
    private int $accountId;
    private int $fiscalYearId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new TransactionAttachmentRepository($this->pdo);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id) VALUES ('p/f.pdf', 'f.pdf', 'application/pdf', 1, 'intendant', 'finance')");
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testAssociateLinksAMovementAndAReceipt(): void
    {
        $transactionId = $this->createTransaction();
        $attachmentId = $this->createAttachment();

        $this->repository->associate($transactionId, $attachmentId);

        $this->assertSame([$attachmentId], $this->repository->findAttachmentIdsForTransaction($transactionId));
        $this->assertSame([$transactionId], $this->repository->findTransactionIdsForAttachment($attachmentId));
    }

    /**
     * Existence is checked before insert rather than relying on INSERT
     * IGNORE, which is not portable between MySQL and the SQLite test
     * database — so associating twice must stay a single row.
     */
    public function testAssociateIsIdempotent(): void
    {
        $transactionId = $this->createTransaction();
        $attachmentId = $this->createAttachment();

        $this->repository->associate($transactionId, $attachmentId);
        $this->repository->associate($transactionId, $attachmentId);

        $this->assertSame([$attachmentId], $this->repository->findAttachmentIdsForTransaction($transactionId));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transaction_attachments')->fetchColumn());
    }

    public function testDissociateRemovesOnlyThatOnePair(): void
    {
        $transactionId = $this->createTransaction();
        $keptTransactionId = $this->createTransaction();
        $attachmentId = $this->createAttachment();
        $this->repository->associate($transactionId, $attachmentId);
        $this->repository->associate($keptTransactionId, $attachmentId);

        $this->repository->dissociate($transactionId, $attachmentId);

        $this->assertSame([], $this->repository->findAttachmentIdsForTransaction($transactionId));
        $this->assertSame([$attachmentId], $this->repository->findAttachmentIdsForTransaction($keptTransactionId));
    }

    public function testDissociateIsANoOpForAPairThatWasNeverLinked(): void
    {
        $this->repository->dissociate(999, 999);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transaction_attachments')->fetchColumn());
    }

    public function testFindAssociatedIdsReturnEachIdOnceAcrossSeveralLinks(): void
    {
        $t1 = $this->createTransaction();
        $t2 = $this->createTransaction();
        $a1 = $this->createAttachment();
        $a2 = $this->createAttachment();
        $this->repository->associate($t1, $a1);
        $this->repository->associate($t1, $a2);
        $this->repository->associate($t2, $a1);

        $this->assertEqualsCanonicalizing([$a1, $a2], $this->repository->findAssociatedAttachmentIds());
        $this->assertEqualsCanonicalizing([$t1, $t2], $this->repository->findAssociatedTransactionIds());
    }

    public function testCountByTransactionIdsOmitsMovementsWithNoReceipt(): void
    {
        $withTwo = $this->createTransaction();
        $withNone = $this->createTransaction();
        $this->repository->associate($withTwo, $this->createAttachment());
        $this->repository->associate($withTwo, $this->createAttachment());

        $counts = $this->repository->countByTransactionIds([$withTwo, $withNone]);

        $this->assertSame(2, $counts[$withTwo]);
        $this->assertArrayNotHasKey($withNone, $counts, 'a movement with no receipt has no entry, not a zero');
    }

    public function testCountByTransactionIdsReturnsEmptyForAnEmptyInput(): void
    {
        $this->assertSame([], $this->repository->countByTransactionIds([]));
    }

    public function testFindAttachmentIdsForTransactionsMatchesTheSingleLookups(): void
    {
        $withTwo = $this->createTransaction();
        $withNone = $this->createTransaction();
        $a = $this->createAttachment();
        $b = $this->createAttachment();
        $this->repository->associate($withTwo, $a);
        $this->repository->associate($withTwo, $b);

        $batch = $this->repository->findAttachmentIdsForTransactions([$withTwo, $withNone]);

        $this->assertSame($this->repository->findAttachmentIdsForTransaction($withTwo), $batch[$withTwo]);
        $this->assertArrayNotHasKey($withNone, $batch, 'a movement with no receipt has no entry');
        $this->assertSame([], $this->repository->findAttachmentIdsForTransactions([]));
    }

    /**
     * "First" is the lowest attachment id — the join table tracks no
     * association order, so this is the documented approximation.
     */
    public function testFindFirstAttachmentIdsPicksTheLowestId(): void
    {
        $transactionId = $this->createTransaction();
        $first = $this->createAttachment();
        $second = $this->createAttachment();
        $this->repository->associate($transactionId, $second);
        $this->repository->associate($transactionId, $first);

        $this->assertSame(
            [$transactionId => $first],
            $this->repository->findFirstAttachmentIdsByTransactionIds([$transactionId])
        );
    }

    public function testFindFirstAttachmentIdsReturnsEmptyForAnEmptyInput(): void
    {
        $this->assertSame([], $this->repository->findFirstAttachmentIdsByTransactionIds([]));
    }

    public function testTransferAttachmentMovesEveryLinkAndLeavesTheOldOneBare(): void
    {
        $t1 = $this->createTransaction();
        $t2 = $this->createTransaction();
        $old = $this->createAttachment();
        $new = $this->createAttachment();
        $this->repository->associate($t1, $old);
        $this->repository->associate($t2, $old);

        $this->repository->transferAttachment($old, $new);

        $this->assertSame([], $this->repository->findTransactionIdsForAttachment($old));
        $this->assertEqualsCanonicalizing([$t1, $t2], $this->repository->findTransactionIdsForAttachment($new));
    }

    /**
     * The replacement may already share a movement with the receipt it
     * replaces; the transfer must not trip over the duplicate.
     */
    public function testTransferAttachmentToleratesAnAlreadySharedMovement(): void
    {
        $transactionId = $this->createTransaction();
        $old = $this->createAttachment();
        $new = $this->createAttachment();
        $this->repository->associate($transactionId, $old);
        $this->repository->associate($transactionId, $new);

        $this->repository->transferAttachment($old, $new);

        $this->assertSame([$transactionId], $this->repository->findTransactionIdsForAttachment($new));
        $this->assertSame([$new], $this->repository->findAttachmentIdsForTransaction($transactionId));
    }

    public function testDeleteAllForAttachmentAndForTransaction(): void
    {
        $t1 = $this->createTransaction();
        $t2 = $this->createTransaction();
        $a1 = $this->createAttachment();
        $a2 = $this->createAttachment();
        $this->repository->associate($t1, $a1);
        $this->repository->associate($t1, $a2);
        $this->repository->associate($t2, $a1);

        $this->repository->deleteAllForAttachment($a1);
        $this->assertSame([$a2], $this->repository->findAttachmentIdsForTransaction($t1));
        $this->assertSame([], $this->repository->findAttachmentIdsForTransaction($t2));

        $this->repository->deleteAllForTransaction($t1);
        $this->assertSame([], $this->repository->findAttachmentIdsForTransaction($t1));
    }

    private function createTransaction(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_transactions (account_id, fiscal_year_id, transaction_date, label, amount, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->accountId, $this->fiscalYearId, '2026-10-01', 'x', -10.0, 'manual']);
        return (int) $this->pdo->lastInsertId();
    }

    private function createAttachment(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_attachments (account_id, file_id, mime_type, original_filename) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->accountId, $this->fileId, 'application/pdf', 'r.pdf']);
        return (int) $this->pdo->lastInsertId();
    }
}
