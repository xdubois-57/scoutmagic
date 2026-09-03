<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * finance_receivable_allocations. Nothing here is encrypted: an
 * allocation is two foreign keys and an integer — who paid and what for
 * live in the movement and the receivable it points at, both of which
 * encrypt their own free text.
 */
class ReceivableAllocationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return ReceivableAllocation[]
     */
    public function findByReceivableId(int $receivableId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_receivable_allocations WHERE receivable_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$receivableId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every allocation for a set of receivables at once, keyed by
     * receivable id — the campaign and reconciliation screens read a few
     * hundred rows in one go and must not run a query per line.
     *
     * @param int[] $receivableIds
     * @return array<int, ReceivableAllocation[]>
     */
    public function findByReceivableIds(array $receivableIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $receivableIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM finance_receivable_allocations WHERE receivable_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($ids);

        $byReceivable = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $allocation = $this->hydrate($row);
            $byReceivable[$allocation->receivableId][] = $allocation;
        }

        return $byReceivable;
    }

    /**
     * @return ReceivableAllocation[]
     */
    public function findByTransactionId(int $transactionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_receivable_allocations WHERE transaction_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$transactionId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $transactionIds
     * @return array<int, ReceivableAllocation[]>
     */
    public function findByTransactionIds(array $transactionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $transactionIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM finance_receivable_allocations WHERE transaction_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($ids);

        $byTransaction = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $allocation = $this->hydrate($row);
            $byTransaction[$allocation->transactionId][] = $allocation;
        }

        return $byTransaction;
    }

    public function findPair(int $transactionId, int $receivableId): ?ReceivableAllocation
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_receivable_allocations WHERE transaction_id = ? AND receivable_id = ?'
        );
        $stmt->execute([$transactionId, $receivableId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        int $transactionId,
        int $receivableId,
        int $amountCents,
        string $source,
        ?int $createdBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_receivable_allocations (transaction_id, receivable_id, amount_cents, source, '
                . 'created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $transactionId,
            $receivableId,
            $amountCents,
            $source,
            $createdBy,
            // Written from PHP rather than left to the column default:
            // SQLite's CURRENT_TIMESTAMP is UTC and the rest of the
            // application is on Europe/Brussels (docs/module-development.md
            // § Timestamps).
            date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Moves an existing pair to a new amount and provenance — used when a
     * human corrects an automatic allocation, and when the automatic pass
     * revises one of its own after the receivable's amount changed.
     */
    public function update(int $id, int $amountCents, string $source, ?int $updatedBy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE finance_receivable_allocations SET amount_cents = ?, source = ?, created_by = ?, created_at = ? '
                . 'WHERE id = ?'
        );
        $stmt->execute([$amountCents, $source, $updatedBy, date('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_receivable_allocations WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function findById(int $id): ?ReceivableAllocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_receivable_allocations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ReceivableAllocation
    {
        return new ReceivableAllocation(
            id: (int) $row['id'],
            transactionId: (int) $row['transaction_id'],
            receivableId: (int) $row['receivable_id'],
            amountCents: (int) $row['amount_cents'],
            source: (string) $row['source'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
