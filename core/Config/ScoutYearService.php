<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Config;

class ScoutYearService
{
    /**
     * Per-instance memo of rows already read. Safe because the four
     * fields callers get (id, label, start_date, end_date) are fixed at
     * INSERT and never updated anywhere — only is_current changes, and it
     * is not part of what this service returns. The composition root and
     * several controllers resolve the same year repeatedly per request.
     *
     * @var array<int, array{id: int, label: string, start_date: string, end_date: string}|null>
     */
    private array $byId = [];

    /** @var array{id: int, label: string, start_date: string, end_date: string}|null */
    private ?array $currentYear = null;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Get the current scout year. A scout year runs September 1 to August 31.
     * If no scout year exists for the current date, create it automatically.
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}
     */
    public function getCurrentYear(): array
    {
        $label = self::labelForDate(new \DateTimeImmutable());
        if ($this->currentYear !== null && $this->currentYear['label'] === $label) {
            return $this->currentYear;
        }

        $id = $this->ensureYear($label);
        $year = $this->findById($id);
        if ($year === null) {
            throw new \RuntimeException('ensureYear() returned an id that does not resolve.');
        }

        return $this->currentYear = $year;
    }

    /**
     * Find a scout year by id.
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}|null
     */
    public function findById(int $id): ?array
    {
        if (array_key_exists($id, $this->byId)) {
            return $this->byId[$id];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM scout_years WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return $this->byId[$id] = null;
        }

        return $this->byId[$id] = [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'start_date' => (string) $row['start_date'],
            'end_date' => (string) $row['end_date'],
        ];
    }

    /**
     * Find a scout year by its exact label (e.g. "2025-2026") — never
     * creates one, unlike ensureYear(). Used where "does this year already
     * exist" must be answered without side effects (e.g. resolving the
     * previous scout year for a historical comparison, where fabricating a
     * not-yet-imported year would be wrong).
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}|null
     */
    public function findByLabel(string $label): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scout_years WHERE label = ?');
        $stmt->execute([$label]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'start_date' => (string) $row['start_date'],
            'end_date' => (string) $row['end_date'],
        ];
    }

    /**
     * Get all scout years, ordered by start_date ascending (oldest first).
     *
     * @return array<int, array{id: int, label: string, start_date: string, end_date: string}>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM scout_years ORDER BY start_date ASC');
        if ($stmt === false) {
            return [];
        }
        return array_map(fn(array $row) => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'start_date' => (string) $row['start_date'],
            'end_date' => (string) $row['end_date'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Ensure a scout year exists for a given label (e.g. "2025-2026").
     * Creates it if not found. Returns the year ID.
     */
    public function ensureYear(string $label): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM scout_years WHERE label = ?');
        $stmt->execute([$label]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return (int) $row['id'];
        }

        // Parse label to compute dates
        $parts = explode('-', $label);
        $startYear = (int) $parts[0];
        $startDate = sprintf('%d-09-01', $startYear);
        $endDate = sprintf('%d-08-31', $startYear + 1);

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current, created_at) VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$label, $startDate, $endDate, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Compute the label of the year following the given one.
     * "2025-2026" → "2026-2027".
     */
    public static function nextLabel(string $label): string
    {
        $parts = explode('-', $label);
        $startYear = (int) $parts[0];

        return sprintf('%d-%d', $startYear + 1, $startYear + 2);
    }

    /**
     * Compute the label of the year preceding the given one.
     * "2025-2026" → "2024-2025".
     */
    public static function previousLabel(string $label): string
    {
        $parts = explode('-', $label);
        $startYear = (int) $parts[0];

        return sprintf('%d-%d', $startYear - 1, $startYear);
    }

    /**
     * Determine the scout year label for a given date.
     * September 2025 → "2025-2026". August 2026 → "2025-2026".
     */
    public static function labelForDate(\DateTimeInterface $date): string
    {
        $month = (int) $date->format('n');
        $year = (int) $date->format('Y');

        if ($month >= 9) {
            return sprintf('%d-%d', $year, $year + 1);
        }

        return sprintf('%d-%d', $year - 1, $year);
    }
}
