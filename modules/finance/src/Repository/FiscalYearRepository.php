<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

class FiscalYearRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return FiscalYear[]
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_fiscal_years ORDER BY start_date DESC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?FiscalYear
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finance_fiscal_years WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findCurrent(): ?FiscalYear
    {
        $stmt = $this->pdo->query('SELECT * FROM finance_fiscal_years WHERE is_current = 1 LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Finds the fiscal year whose [start_date, end_date] range contains
     * $date — used to auto-assign a transaction's fiscal_year_id from its
     * transaction_date at import time (module spec follow-up "itération 3").
     */
    public function findForDate(string $date): ?FiscalYear
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM finance_fiscal_years WHERE start_date <= ? AND end_date >= ? LIMIT 1'
        );
        $stmt->execute([$date, $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(string $label, string $startDate, string $endDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_fiscal_years (label, start_date, end_date) VALUES (?, ?, ?)'
        );
        $stmt->execute([$label, $startDate, $endDate]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Sets $id as the only current fiscal year — clears the flag on every
     * other row first (application-enforced single-current invariant, see
     * schema.sql's comment on finance_fiscal_years).
     */
    public function setCurrent(int $id): void
    {
        $this->pdo->exec('UPDATE finance_fiscal_years SET is_current = 0');
        $stmt = $this->pdo->prepare('UPDATE finance_fiscal_years SET is_current = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FiscalYear
    {
        return new FiscalYear(
            id: (int) $row['id'],
            label: (string) $row['label'],
            startDate: (string) $row['start_date'],
            endDate: (string) $row['end_date'],
            isCurrent: (bool) $row['is_current']
        );
    }
}
