<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

/**
 * The monthly history: a working table of contributions, and the immutable
 * aggregates it is collapsed into (ARCHITECTURE.md §8.50).
 */
class SupportMonthlyAggregateRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Records that this installation reported during this month.
     *
     * Several reports in one month are **one** contribution, and the
     * uniqueness is the table's, not this method's: two reports arriving
     * concurrently would interleave past a read-then-write check, so the
     * duplicate is swallowed by the unique index instead.
     */
    public function recordContribution(string $month, string $installationId): void
    {
        $sql = $this->isSqlite()
            ? 'INSERT OR IGNORE INTO support_monthly_contributions (month, installation_id) VALUES (?, ?)'
            : 'INSERT IGNORE INTO support_monthly_contributions (month, installation_id) VALUES (?, ?)';

        $this->pdo->prepare($sql)->execute([$month, $installationId]);
    }

    /**
     * Months with contributions recorded but no aggregate yet, oldest
     * first. A site left idle for a while therefore catches up naturally,
     * one call finalising every month that has ended.
     *
     * @return array<int, string>
     */
    public function findUnfinalizedMonthsBefore(string $currentMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT c.month
               FROM support_monthly_contributions c
          LEFT JOIN support_monthly_aggregates a ON a.month = c.month
              WHERE c.month < ? AND a.id IS NULL
           ORDER BY c.month ASC'
        );
        $stmt->execute([$currentMonth]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    public function countContributions(string $month): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM support_monthly_contributions WHERE month = ?');
        $stmt->execute([$month]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Writes the month's aggregate and **deletes the contributions that
     * produced it**, in one transaction.
     *
     * The delete is not housekeeping — it is the requirement. It is what
     * makes "a finalised aggregate contains no individual identifier" true
     * of the data rather than merely of the queries that read it.
     *
     * Returns false if the month was already finalised: an aggregate is
     * immutable, so a second finalisation is a no-op, never an overwrite.
     */
    public function finalize(string $month): bool
    {
        if ($this->find($month) !== null) {
            return false;
        }

        $count = $this->countContributions($month);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO support_monthly_aggregates (month, installation_count, finalized_at) VALUES (?, ?, ?)'
            )->execute([$month, $count, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

            $this->pdo->prepare('DELETE FROM support_monthly_contributions WHERE month = ?')->execute([$month]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $month): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_monthly_aggregates WHERE month = ?');
        $stmt->execute([$month]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The finalised series, oldest first, limited to the most recent
     * $months when a number is given.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSeries(?int $months = null): array
    {
        $stmt = $this->pdo->query('SELECT * FROM support_monthly_aggregates ORDER BY month ASC');
        $rows = $stmt !== false ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];

        if ($months !== null && $months > 0 && count($rows) > $months) {
            $rows = array_slice($rows, -$months);
        }

        return $rows;
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
