<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Repository;

class CalendarEventRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?CalendarEvent
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Events in any of the given calendars whose date range overlaps
     * [$fromDate, $toDate] (inclusive, "YYYY-MM-DD"). A multi-day event
     * overlaps if it starts on/before $toDate and ends on/after $fromDate.
     *
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function findByCalendarIdsInRange(array $calendarIds, string $fromDate, string $toDate): array
    {
        if (count($calendarIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM calendar_events
             WHERE calendar_id IN ({$placeholders})
               AND start_date <= ?
               AND COALESCE(end_date, start_date) >= ?
             ORDER BY start_date, start_time"
        );
        $stmt->execute([...$calendarIds, $toDate, $fromDate]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Events whose effective end date (end_date, falling back to
     * start_date) falls within [$fromDate, $toDate] — distinct from
     * findByCalendarIdsInRange()'s overlap semantics, which
     * Api\CalendarEventLookupInterface::findEventsInWindow() needs instead
     * (only events actually ending inside the retro module's -10/+15 day
     * picker window, not merely overlapping it).
     *
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function findByCalendarIdsWithEffectiveEndInRange(array $calendarIds, string $fromDate, string $toDate): array
    {
        if (count($calendarIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM calendar_events
             WHERE calendar_id IN ({$placeholders})
               AND COALESCE(end_date, start_date) BETWEEN ? AND ?
             ORDER BY start_date, start_time"
        );
        $stmt->execute([...$calendarIds, $fromDate, $toDate]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Upcoming events (starting on/after $fromDate), soonest first.
     *
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function findUpcoming(array $calendarIds, string $fromDate, int $limit): array
    {
        if (count($calendarIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM calendar_events
             WHERE calendar_id IN ({$placeholders})
               AND COALESCE(end_date, start_date) >= ?
             ORDER BY start_date, start_time
             LIMIT " . max(0, $limit)
        );
        $stmt->execute([...$calendarIds, $fromDate]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * All events in a calendar, any date — used to build a full ICS feed.
     *
     * @return CalendarEvent[]
     */
    public function findByCalendarId(int $calendarId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_events WHERE calendar_id = ? ORDER BY start_date, start_time');
        $stmt->execute([$calendarId]);
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function findByCalendarIds(array $calendarIds): array
    {
        if (count($calendarIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($calendarIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM calendar_events WHERE calendar_id IN ({$placeholders}) ORDER BY start_date, start_time"
        );
        $stmt->execute($calendarIds);
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function calendarHasEvents(int $calendarId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM calendar_events WHERE calendar_id = ? LIMIT 1');
        $stmt->execute([$calendarId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return int the new event id
     */
    public function create(
        int $calendarId,
        string $title,
        string $startDate,
        ?string $endDate,
        ?string $startTime,
        ?string $endTime,
        ?string $location,
        ?string $description,
        ?int $createdBy,
        bool $autoCreateRetro = false
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_events
                (calendar_id, title, start_date, end_date, start_time, end_time, location, description, created_by, auto_create_retro)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$calendarId, $title, $startDate, $endDate, $startTime, $endTime, $location, $description, $createdBy, $autoCreateRetro ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bumps sequence and updated_at — sequence feeds the ICS SEQUENCE
     * property so subscribed clients know a cached event was superseded;
     * updated_at is set explicitly here rather than via an SQL "ON UPDATE
     * CURRENT_TIMESTAMP" clause, which the project's migration system
     * doesn't model (see schema.sql).
     */
    public function update(
        int $id,
        int $calendarId,
        string $title,
        string $startDate,
        ?string $endDate,
        ?string $startTime,
        ?string $endTime,
        ?string $location,
        ?string $description,
        bool $autoCreateRetro = false
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE calendar_events
             SET calendar_id = ?, title = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?,
                 location = ?, description = ?, auto_create_retro = ?, sequence = sequence + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$calendarId, $title, $startDate, $endDate, $startTime, $endTime, $location, $description, $autoCreateRetro ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM calendar_events WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CalendarEvent
    {
        return new CalendarEvent(
            id: (int) $row['id'],
            calendarId: (int) $row['calendar_id'],
            title: (string) $row['title'],
            startDate: (string) $row['start_date'],
            endDate: $row['end_date'] !== null ? (string) $row['end_date'] : null,
            startTime: $row['start_time'] !== null ? (string) $row['start_time'] : null,
            endTime: $row['end_time'] !== null ? (string) $row['end_time'] : null,
            location: $row['location'] !== null ? (string) $row['location'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            sequence: (int) $row['sequence'],
            autoCreateRetro: (bool) $row['auto_create_retro'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null,
            updatedAt: (string) $row['updated_at']
        );
    }
}
