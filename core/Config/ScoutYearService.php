<?php

declare(strict_types=1);

namespace Core\Config;

class ScoutYearService
{
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
        $id = $this->ensureYear($label);

        $stmt = $this->pdo->prepare('SELECT * FROM scout_years WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'start_date' => (string) $row['start_date'],
            'end_date' => (string) $row['end_date'],
        ];
    }

    /**
     * Get all scout years, ordered by start_date descending.
     *
     * @return array<int, array{id: int, label: string, start_date: string, end_date: string}>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM scout_years ORDER BY start_date DESC');
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
