<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

use Core\Config\ScoutYearService;

/**
 * A finance "exercice" is a scout year (`scout_years`, Core\Config\
 * ScoutYearService) — not a module-specific entity an admin creates by
 * hand. This repository is an adapter, not an owner of its own table:
 * it never writes to scout_years except to ensure the current and next
 * two years exist (ScoutYearService::ensureYear()), the same idempotent
 * pattern as Service\FinanceService::ensureDefaultAccountsForSections().
 * "Current" is never read from scout_years.is_current (that column is
 * only ever set at row-insert time and is not kept in sync — the site's
 * real current year lives in the `current_scout_year_id` setting, see
 * Core\ScoutYear\ScoutYearResolver) — it is always derived from
 * ScoutYearService::getCurrentYear(), which is date-computed.
 */
class FiscalYearRepository
{
    public function __construct(
        private \PDO $pdo,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * The years finance ever shows: up to 2 past (only if they already
     * exist — never fabricated), the current one, and the next 2 (both
     * ensured to exist), ordered oldest first.
     *
     * @return FiscalYear[]
     */
    public function findAllOrdered(): array
    {
        $currentLabel = ScoutYearService::labelForDate(new \DateTimeImmutable());
        $currentId = $this->scoutYearService->ensureYear($currentLabel);

        $pastLabels = [$this->previousLabel($currentLabel, 2), $this->previousLabel($currentLabel, 1)];
        $ensuredLabels = [
            $currentLabel,
            ScoutYearService::nextLabel($currentLabel),
            ScoutYearService::nextLabel(ScoutYearService::nextLabel($currentLabel)),
        ];

        $fiscalYears = [];

        foreach ($pastLabels as $label) {
            $row = $this->findRowByLabel($label);
            if ($row !== null) {
                $fiscalYears[] = $this->hydrate($row, $currentId);
            }
        }

        foreach ($ensuredLabels as $label) {
            $this->scoutYearService->ensureYear($label);
            $row = $this->findRowByLabel($label);
            if ($row !== null) {
                $fiscalYears[] = $this->hydrate($row, $currentId);
            }
        }

        return $fiscalYears;
    }

    public function findById(int $id): ?FiscalYear
    {
        $row = $this->scoutYearService->findById($id);
        if ($row === null) {
            return null;
        }

        return $this->hydrateFromScoutYearRow($row);
    }

    public function findCurrent(): ?FiscalYear
    {
        $current = $this->scoutYearService->getCurrentYear();
        return $this->hydrateFromScoutYearRow($current);
    }

    /**
     * Finds the fiscal year whose [start_date, end_date] range contains
     * $date — used to auto-assign a transaction's fiscal_year_id from its
     * transaction_date at import time. Never creates a missing year: an
     * import date outside every known scout year is a real data gap, not
     * something finance should silently paper over.
     */
    public function findForDate(string $date): ?FiscalYear
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scout_years WHERE start_date <= ? AND end_date >= ? LIMIT 1'
        );
        $stmt->execute([$date, $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row, $this->currentYearId()) : null;
    }

    /**
     * The oldest scout year (by end_date) whose end_date is strictly
     * before $date — used by Task\PurgeOldMovementsHandler to purge one
     * complete fiscal year at a time, oldest first, rather than a
     * day-by-day cutoff.
     */
    public function findOldestEndingBefore(string $date): ?FiscalYear
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scout_years WHERE end_date < ? ORDER BY end_date ASC LIMIT 1'
        );
        $stmt->execute([$date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row, $this->currentYearId()) : null;
    }

    private function currentYearId(): int
    {
        return (int) $this->scoutYearService->getCurrentYear()['id'];
    }

    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $row
     */
    private function hydrateFromScoutYearRow(array $row): FiscalYear
    {
        return new FiscalYear(
            id: $row['id'],
            label: $row['label'],
            startDate: $row['start_date'],
            endDate: $row['end_date'],
            isCurrent: $row['id'] === $this->currentYearId()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowByLabel(string $label): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scout_years WHERE label = ?');
        $stmt->execute([$label]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * "2025-2026" going back 1 → "2024-2025". Mirrors
     * ScoutYearService::nextLabel() in the opposite direction (that
     * service has no such helper of its own).
     */
    private function previousLabel(string $label, int $steps): string
    {
        $parts = explode('-', $label);
        $startYear = (int) $parts[0] - $steps;
        return sprintf('%d-%d', $startYear, $startYear + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, int $currentYearId): FiscalYear
    {
        return new FiscalYear(
            id: (int) $row['id'],
            label: (string) $row['label'],
            startDate: (string) $row['start_date'],
            endDate: (string) $row['end_date'],
            isCurrent: (int) $row['id'] === $currentYearId
        );
    }
}
