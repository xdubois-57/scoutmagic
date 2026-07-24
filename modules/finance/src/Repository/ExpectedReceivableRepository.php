<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Security\EncryptionService;

class ExpectedReceivableRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function communicationExists(string $communication): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_expected_receivables WHERE communication = ?');
        $stmt->execute([$communication]);
        return $stmt->fetch() !== false;
    }

    public function create(
        string $sourceModule,
        int $sourceReferenceId,
        int $accountId,
        int $amountDueCents,
        string $communication,
        ?string $label
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_expected_receivables (source_module, source_reference_id, account_id, amount_due_cents, communication, label_encrypted)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sourceModule,
            $sourceReferenceId,
            $accountId,
            $amountDueCents,
            $communication,
            $label !== null ? $this->encryption->encrypt($label) : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?ExpectedReceivable
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_expected_receivables WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return ExpectedReceivable[]
     */
    public function findBySource(string $sourceModule, int $sourceReferenceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_expected_receivables WHERE source_module = ? AND source_reference_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sourceModule, $sourceReferenceId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * All receivables for a source module, grouped by source_reference_id
     * — used by the "Paiements attendus" reconciliation page (level 2:
     * one group per source instance).
     *
     * @return ExpectedReceivable[]
     */
    public function findAllByModule(string $sourceModule): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_expected_receivables WHERE source_module = ? ORDER BY source_reference_id ASC, id ASC'
        );
        $stmt->execute([$sourceModule]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Distinct source modules with at least one receivable — level 1 of
     * the reconciliation page's accordion.
     *
     * @return string[]
     */
    public function findDistinctSourceModules(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT source_module FROM finance_expected_receivables ORDER BY source_module ASC');
        return $stmt !== false ? array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
    }

    public function deleteBySource(string $sourceModule, int $sourceReferenceId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_expected_receivables WHERE source_module = ? AND source_reference_id = ?');
        $stmt->execute([$sourceModule, $sourceReferenceId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ExpectedReceivable
    {
        return new ExpectedReceivable(
            id: (int) $row['id'],
            sourceModule: (string) $row['source_module'],
            sourceReferenceId: (int) $row['source_reference_id'],
            accountId: (int) $row['account_id'],
            amountDueCents: (int) $row['amount_due_cents'],
            communication: (string) $row['communication'],
            label: $row['label_encrypted'] !== null ? $this->encryption->decrypt($row['label_encrypted']) : null,
            createdAt: (string) $row['created_at']
        );
    }
}
