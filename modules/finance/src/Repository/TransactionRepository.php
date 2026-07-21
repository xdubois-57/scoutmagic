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
     * @param int[] $ids
     * @return Transaction[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM finance_transactions WHERE id IN ({$placeholders}) ORDER BY transaction_date DESC, id DESC");
        $stmt->execute(array_values($ids));
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

    /**
     * Movements page filtering (Controller\MovementController::list()).
     * account_id/fiscal_year_id/category_id are filtered in SQL;
     * $search is matched against the decrypted label in PHP afterwards —
     * label is encrypted (non-deterministic ciphertext), so it can never
     * be matched with a SQL WHERE/LIKE clause. $accountIds is the RBAC
     * boundary (accounts visible to the caller's role) — null means "no
     * account restriction" and must never be passed directly from user
     * input, only from a caller that has already computed the visible
     * set (see MovementController::list()).
     *
     * @param int[]|null $accountIds
     * @return Transaction[]
     */
    public function findFiltered(?array $accountIds, ?int $fiscalYearId, ?int $categoryId, ?string $search): array
    {
        $sql = 'SELECT * FROM finance_transactions WHERE 1=1';
        $params = [];

        if ($accountIds !== null) {
            if ($accountIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $sql .= " AND account_id IN ({$placeholders})";
            array_push($params, ...$accountIds);
        }
        if ($fiscalYearId !== null) {
            $sql .= ' AND fiscal_year_id = ?';
            $params[] = $fiscalYearId;
        }
        if ($categoryId !== null) {
            $sql .= ' AND category_id = ?';
            $params[] = $categoryId;
        }

        $sql .= ' ORDER BY transaction_date DESC, id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $transactions = array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        if ($search !== null && trim($search) !== '') {
            $transactions = array_values(array_filter(
                $transactions,
                fn(Transaction $transaction) => mb_stripos($transaction->label, $search) !== false
            ));
        }

        return $transactions;
    }

    /**
     * Per-category income/expense/total for an account's fiscal year —
     * backs Service\FinanceService::getCategorySummary(). Pure SQL
     * aggregation: category_id and amount are both plain columns, so
     * there's nothing here that needs decrypting.
     *
     * @return array<int, array{category_id: ?int, category_name: ?string, income: float, expense: float, total: float}>
     */
    public function getCategorySummary(int $accountId, int $fiscalYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                t.category_id AS category_id,
                c.name AS category_name,
                SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END) AS income,
                SUM(CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END) AS expense,
                SUM(t.amount) AS total
             FROM finance_transactions t
             LEFT JOIN finance_categories c ON c.id = t.category_id
             WHERE t.account_id = ? AND t.fiscal_year_id = ?
             GROUP BY t.category_id, c.name
             ORDER BY income DESC'
        );
        $stmt->execute([$accountId, $fiscalYearId]);

        return array_map(fn(array $row) => [
            'category_id' => $row['category_id'] !== null ? (int) $row['category_id'] : null,
            'category_name' => $row['category_name'] !== null ? (string) $row['category_name'] : null,
            'income' => (float) $row['income'],
            'expense' => (float) $row['expense'],
            'total' => (float) $row['total'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Backs the dashboard's alert banner ("N mouvements non catégorisés").
     */
    public function countUncategorized(int $accountId, int $fiscalYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM finance_transactions WHERE account_id = ? AND fiscal_year_id = ? AND category_id IS NULL'
        );
        $stmt->execute([$accountId, $fiscalYearId]);
        return (int) $stmt->fetchColumn();
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

    /**
     * @return int[]
     */
    public function findIdsByAccountAndFiscalYear(int $accountId, int $fiscalYearId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM finance_transactions WHERE account_id = ? AND fiscal_year_id = ?');
        $stmt->execute([$accountId, $fiscalYearId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Task\PurgeOldMovementsHandler purges one complete fiscal year at a
     * time (per account) rather than a day-based cutoff — see
     * Repository\FiscalYearRepository::findOldestEndingBefore().
     */
    public function deleteByAccountAndFiscalYear(int $accountId, int $fiscalYearId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_transactions WHERE account_id = ? AND fiscal_year_id = ?');
        $stmt->execute([$accountId, $fiscalYearId]);
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
