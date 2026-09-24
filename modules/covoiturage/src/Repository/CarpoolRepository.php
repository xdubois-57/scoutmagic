<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

use Core\Geo\GeoPoint;
use Core\Geo\GeoPointStore;

/**
 * The carpools and the events they serve. Nothing here is personal data —
 * an outing's venue and dates — so nothing is encrypted; the people are in
 * OfferRepository and SeatRequestRepository.
 */
class CarpoolRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(
        string $address,
        string $outboundDate,
        ?string $returnDate,
        ?int $sectionId,
        ?int $createdBy
    ): int {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO carpools (address, outbound_date, return_date, section_id, created_by_user_account_id,
                                   created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$address, $outboundDate, $returnDate, $sectionId, $createdBy, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $address, string $outboundDate, ?string $returnDate, ?int $sectionId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE carpools SET address = ?, outbound_date = ?, return_date = ?, section_id = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([$address, $outboundDate, $returnDate, $sectionId, date('Y-m-d H:i:s'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM carpools WHERE id = ?')->execute([$id]);
    }

    public function findById(int $id): ?Carpool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carpools WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $carpool = $this->hydrate($row);

        return $carpool->withEvents($this->eventsFor([$carpool->id])[$carpool->id] ?? []);
    }

    /**
     * Every carpool whose last date is $since or later, soonest first, with
     * its events — one query for the carpools, one for all their events.
     *
     * @return list<Carpool>
     */
    public function findEndingOnOrAfter(string $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM carpools
              WHERE COALESCE(return_date, outbound_date) >= ?
              ORDER BY outbound_date ASC, id ASC'
        );
        $stmt->execute([$since]);
        $carpools = array_map(fn(array $row): Carpool => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $events = $this->eventsFor(array_map(static fn(Carpool $c): int => $c->id, $carpools));

        return array_map(static fn(Carpool $c): Carpool => $c->withEvents($events[$c->id] ?? []), $carpools);
    }

    /**
     * Replaces the set of events a carpool serves.
     *
     * @param list<CarpoolEvent> $events
     */
    public function replaceEvents(int $carpoolId, array $events): void
    {
        $this->pdo->prepare('DELETE FROM carpool_events WHERE carpool_id = ?')->execute([$carpoolId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO carpool_events (carpool_id, calendar_event_id, event_title, section_id, section_name)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($events as $event) {
            $stmt->execute([$carpoolId, $event->eventId, $event->title, $event->sectionId, $event->sectionName]);
        }
    }

    /**
     * @param list<int> $carpoolIds
     * @return array<int, list<CarpoolEvent>>
     */
    public function eventsFor(array $carpoolIds): array
    {
        if ($carpoolIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($carpoolIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carpool_events WHERE carpool_id IN ({$placeholders})
              ORDER BY section_name IS NULL, section_name, calendar_event_id"
        );
        $stmt->execute($carpoolIds);

        $events = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $events[(int) $row['carpool_id']][] = new CarpoolEvent(
                (int) $row['calendar_event_id'],
                (string) $row['event_title'],
                $row['section_id'] !== null ? (int) $row['section_id'] : null,
                $row['section_name'] !== null ? (string) $row['section_name'] : null
            );
        }

        return $events;
    }

    /**
     * Which carpool already serves each of $eventIds, keyed by event id —
     * an event absent from the result is free.
     *
     * @param list<int> $eventIds
     * @return array<int, int>
     */
    public function carpoolIdsByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT calendar_event_id, carpool_id FROM carpool_events WHERE calendar_event_id IN ({$placeholders})"
        );
        $stmt->execute($eventIds);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['calendar_event_id']] = (int) $row['carpool_id'];
        }

        return $map;
    }

    /**
     * Deletes every carpool whose last date is before $cutoff — its offers,
     * requests and phone numbers with it (ON DELETE CASCADE).
     *
     * @return int how many carpools went
     */
    public function deleteEndedBefore(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM carpools WHERE COALESCE(return_date, outbound_date) < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    /** The next carpool whose address has never been looked up, oldest first. */
    public function findNextToGeocode(): ?Carpool
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM carpools WHERE coordinates_are_manual = 0 AND geocoded_at IS NULL ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function countPendingGeocoding(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM carpools WHERE coordinates_are_manual = 0 AND geocoded_at IS NULL'
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * The four point columns of `carpools`, under the core's manual lock
     * (Core\Geo\GeoPointStore): a point a chief placed is never overwritten.
     */
    public function points(): GeoPointStore
    {
        return new GeoPointStore($this->pdo, 'carpools');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Carpool
    {
        return new Carpool(
            (int) $row['id'],
            (string) $row['address'],
            GeoPoint::fromColumns($row['latitude'] ?? null, $row['longitude'] ?? null),
            (bool) $row['coordinates_are_manual'],
            substr((string) $row['outbound_date'], 0, 10),
            $row['return_date'] !== null ? substr((string) $row['return_date'], 0, 10) : null,
            $row['section_id'] !== null ? (int) $row['section_id'] : null,
            $row['created_by_user_account_id'] !== null ? (int) $row['created_by_user_account_id'] : null
        );
    }
}
