<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

class CalendarSyncRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return CalendarSyncEntry[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sos_calendar_sync ORDER BY start_date ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function create(int $memberId, string $startDate, string $endDate, ?int $calendarEventId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sos_calendar_sync (member_id, start_date, end_date, calendar_event_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $startDate, $endDate, $calendarEventId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Drop every sync bookkeeping row — the caller is responsible for
     * deleting the underlying calendar module events first (via
     * CalendarEventService), this only clears the bookkeeping.
     */
    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM sos_calendar_sync');
    }

    /**
     * Streaks whose end_date is before the cutoff (module spec §6 cleanup)
     * — the caller deletes each one's calendar_event_id via the calendar
     * module first, then calls deleteByIds().
     *
     * @return CalendarSyncEntry[]
     */
    public function findOlderThan(string $cutoffDate): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sos_calendar_sync WHERE end_date < ?');
        $stmt->execute([$cutoffDate]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (count($ids) === 0) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM sos_calendar_sync WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CalendarSyncEntry
    {
        return new CalendarSyncEntry(
            id: (int) $row['id'],
            memberId: (int) $row['member_id'],
            startDate: (string) $row['start_date'],
            endDate: (string) $row['end_date'],
            calendarEventId: $row['calendar_event_id'] !== null ? (int) $row['calendar_event_id'] : null
        );
    }
}
