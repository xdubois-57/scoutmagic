<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

class BalanceCheckpointRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return BalanceCheckpoint[]
     */
    public function findByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_balance_checkpoints WHERE account_id = ? ORDER BY checkpoint_date ASC, id ASC');
        $stmt->execute([$accountId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The most recent checkpoint at or before $date — Service\BalanceService's
     * starting point for a balance-at-date calculation.
     */
    public function findClosestBefore(int $accountId, string $date): ?BalanceCheckpoint
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_balance_checkpoints
             WHERE account_id = ? AND checkpoint_date <= ?
             ORDER BY checkpoint_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$accountId, $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function hasAnyForAccount(int $accountId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM finance_balance_checkpoints WHERE account_id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(int $accountId, string $checkpointDate, float $balance, string $source): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_balance_checkpoints (account_id, checkpoint_date, balance, source) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $checkpointDate, $balance, $source]);
        return (int) $this->pdo->lastInsertId();
    }

    public function deleteAllForAccount(int $accountId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_balance_checkpoints WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return $stmt->rowCount();
    }

    /**
     * Used by Task\PurgeOldMovementsHandler right after inserting a
     * consolidated checkpoint at the purge cutoff — every checkpoint
     * that predates it is now redundant (nothing to reference it since
     * the transactions it covered have been purged too).
     */
    public function deleteBeforeOrAt(int $accountId, string $date): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM finance_balance_checkpoints WHERE account_id = ? AND checkpoint_date <= ?');
        $stmt->execute([$accountId, $date]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BalanceCheckpoint
    {
        return new BalanceCheckpoint(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            checkpointDate: (string) $row['checkpoint_date'],
            balance: (float) $row['balance'],
            source: (string) $row['source']
        );
    }
}
