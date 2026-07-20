<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

class StatementImportRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return StatementImport[]
     */
    public function findByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_statement_imports WHERE account_id = ? ORDER BY imported_at DESC');
        $stmt->execute([$accountId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?StatementImport
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_statement_imports WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function hasAnyForAccount(int $accountId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_statement_imports WHERE account_id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(
        int $accountId,
        string $bankCode,
        string $originalFilename,
        int $linesTotal,
        int $linesNew,
        int $linesDuplicate,
        ?int $importedBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_statement_imports
                (account_id, bank_code, original_filename, lines_total, lines_new, lines_duplicate, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $bankCode, $originalFilename, $linesTotal, $linesNew, $linesDuplicate, $importedBy]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StatementImport
    {
        return new StatementImport(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            bankCode: (string) $row['bank_code'],
            originalFilename: (string) $row['original_filename'],
            linesTotal: (int) $row['lines_total'],
            linesNew: (int) $row['lines_new'],
            linesDuplicate: (int) $row['lines_duplicate'],
            importedBy: $row['imported_by'] !== null ? (int) $row['imported_by'] : null,
            importedAt: (string) $row['imported_at']
        );
    }
}
