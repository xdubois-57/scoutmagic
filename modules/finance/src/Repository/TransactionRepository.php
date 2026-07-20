<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Security\EncryptionService;

class TransactionRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function findById(int $id): ?Transaction
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_transactions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Transaction[]
     */
    public function findByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_transactions WHERE account_id = ? ORDER BY transaction_date ASC, id ASC');
        $stmt->execute([$accountId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Transactions for an account strictly after a given date — used by
     * Service\BalanceService to add up everything that happened since the
     * closest balance checkpoint.
     *
     * @return Transaction[]
     */
    public function findByAccountAfterDate(int $accountId, string $afterDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_transactions WHERE account_id = ? AND transaction_date > ? ORDER BY transaction_date ASC, id ASC'
        );
        $stmt->execute([$accountId, $afterDate]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return Transaction[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_transactions ORDER BY transaction_date DESC, id DESC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function create(
        int $accountId,
        int $fiscalYearId,
        ?string $bankReference,
        string $transactionDate,
        string $label,
        float $amount,
        ?int $categoryId,
        ?string $comment,
        string $source,
        ?string $importedAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_transactions
                (account_id, fiscal_year_id, bank_reference, transaction_date, label, amount, category_id, comment, source, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $accountId,
            $fiscalYearId,
            $bankReference,
            $transactionDate,
            $this->encryption->encrypt($label),
            $amount,
            $categoryId,
            $comment,
            $source,
            $importedAt,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Inserts a transaction unless (account_id, bank_reference) already
     * exists — the import deduplication key (module spec follow-up
     * "itération 3": re-importing an overlapping statement range must
     * silently skip already-known lines). Returns true when a new row was
     * actually inserted, false when it was a duplicate no-op.
     */
    public function insertOrSkip(
        int $accountId,
        int $fiscalYearId,
        string $bankReference,
        string $transactionDate,
        string $label,
        float $amount,
        ?int $categoryId
    ): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_transactions WHERE account_id = ? AND bank_reference = ?');
        $stmt->execute([$accountId, $bankReference]);
        if ($stmt->fetchColumn() !== false) {
            return false;
        }

        $this->create(
            $accountId,
            $fiscalYearId,
            $bankReference,
            $transactionDate,
            $label,
            $amount,
            $categoryId,
            null,
            Transaction::SOURCE_IMPORT,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        );
        return true;
    }

    /**
     * Updates only the three fields the movements page ever lets an
     * intendant change — amount/date/label/bank_reference are read-only
     * bank data (module spec follow-up "itération 3").
     */
    public function updateEditableFields(int $id, ?int $categoryId, ?string $comment, int $fiscalYearId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE finance_transactions SET category_id = ?, comment = ?, fiscal_year_id = ? WHERE id = ?'
        );
        $stmt->execute([$categoryId, $comment, $fiscalYearId, $id]);
    }

    public function deleteAllForAccount(int $accountId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_transactions WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return $stmt->rowCount();
    }

    public function deleteOlderThan(string $cutoffDate): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_transactions WHERE transaction_date < ?');
        $stmt->execute([$cutoffDate]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Transaction
    {
        return new Transaction(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            fiscalYearId: (int) $row['fiscal_year_id'],
            bankReference: $row['bank_reference'] !== null ? (string) $row['bank_reference'] : null,
            transactionDate: (string) $row['transaction_date'],
            label: $this->encryption->decrypt($row['label']),
            amount: (float) $row['amount'],
            categoryId: $row['category_id'] !== null ? (int) $row['category_id'] : null,
            comment: $row['comment'] !== null ? (string) $row['comment'] : null,
            source: (string) $row['source'],
            importedAt: $row['imported_at'] !== null ? (string) $row['imported_at'] : null
        );
    }
}
