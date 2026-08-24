<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/**
 * camp_places. No EncryptionService here, and that is the point: every
 * column of this table is in clear (see schema.sql), which is what lets
 * the module's main screen filter on name, address, postal code and city
 * in SQL instead of decrypting every place on every keystroke.
 */
class PlaceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Place
    {
        $stmt = $this->pdo->prepare('SELECT * FROM camp_places WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every live place, most recently visited first.
     *
     * The ordering is the one the roadmap asks for and it needs the join:
     * a place is "recent" because of when the unit last stayed there, not
     * when its row was created. A place with no stay at all (just created,
     * or every stay deleted before this module forbade that) sorts last
     * rather than dropping out — MAX() of nothing is NULL, and a NULL that
     * silently removed a place from the only screen listing places would
     * be a place nobody could find again.
     *
     * @return Place[]
     */
    public function findAllVisible(bool $archived = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, (SELECT MAX(COALESCE(c.end_date, CONCAT(c.year_only, \'-12-31\')))
                            FROM camp_camps c WHERE c.place_id = p.id) AS last_stay
               FROM camp_places p
              WHERE p.is_archived = ?
              ORDER BY last_stay IS NULL, last_stay DESC, p.name ASC'
        );
        $stmt->execute([$archived ? 1 : 0]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The search behind the main screen: name, address, postal code and
     * city, all in clear, all in SQL.
     *
     * Deliberately NOT the booking person or the contacts. Those are
     * encrypted BLOBs, and reaching them would mean decrypting every camp
     * and every contact of the whole installation on every keystroke — for
     * a match nobody can index. The placeholder promises only what this
     * returns.
     *
     * @return Place[]
     */
    public function search(string $term, bool $archived = false): array
    {
        $term = trim($term);
        if ($term === '') {
            return $this->findAllVisible($archived);
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $stmt = $this->pdo->prepare(
            'SELECT p.*, (SELECT MAX(COALESCE(c.end_date, CONCAT(c.year_only, \'-12-31\')))
                            FROM camp_camps c WHERE c.place_id = p.id) AS last_stay
               FROM camp_places p
              WHERE p.is_archived = ?
                AND (p.name LIKE ? OR p.address LIKE ? OR p.postal_code LIKE ? OR p.city LIKE ?)
              ORDER BY last_stay IS NULL, last_stay DESC, p.name ASC'
        );
        $stmt->execute([$archived ? 1 : 0, $like, $like, $like, $like]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param int[] $ids
     * @return Place[]
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM camp_places WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(
        string $name,
        ?string $address,
        ?string $postalCode,
        ?string $city,
        ?string $country,
        ?string $websiteUrl
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO camp_places (name, address, postal_code, city, country, website_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$name, $address, $postalCode, $city, $country, $websiteUrl, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        ?string $address,
        ?string $postalCode,
        ?string $city,
        ?string $country,
        ?string $websiteUrl
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE camp_places SET name = ?, address = ?, postal_code = ?, city = ?, country = ?,
                    website_url = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([$name, $address, $postalCode, $city, $country, $websiteUrl, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Every live place that has a point — what the map shows. Places
     * without coordinates simply do not appear, and the map says so
     * underneath rather than pretending the unit has never camped
     * anywhere else.
     *
     * @return Place[]
     */
    public function findMappable(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM camp_places
              WHERE is_archived = 0 AND latitude IS NOT NULL AND longitude IS NOT NULL
              ORDER BY name ASC'
        );
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The next place worth geocoding: never one whose coordinates a human
     * set or corrected, never one already tried, and oldest first so a
     * backlog drains in a predictable order.
     *
     * Returns ONE, because that is how the one-request-per-second rule of
     * Nominatim's usage policy is expressed in a scheduler that has no
     * rate limiting of its own.
     */
    public function findNextToGeocode(): ?Place
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM camp_places
              WHERE coordinates_are_manual = 0
                AND geocoded_at IS NULL
                AND is_archived = 0
              ORDER BY id ASC
              LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function countPendingGeocoding(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM camp_places
              WHERE coordinates_are_manual = 0 AND geocoded_at IS NULL AND is_archived = 0'
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Records the RESULT of a geocoding attempt, including a failed one:
     * geocoded_at is stamped either way, so a place whose address means
     * nothing to Nominatim is tried once and then left alone instead of
     * being retried on every run for ever.
     */
    public function recordGeocoding(int $id, ?float $latitude, ?float $longitude, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE camp_places SET latitude = ?, longitude = ?, geocoded_at = ? WHERE id = ? AND coordinates_are_manual = 0'
        );
        $stmt->execute([$latitude, $longitude, $at->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Coordinates a human typed. Sets coordinates_are_manual, which is
     * never cleared — from here on, automatic geocoding leaves this place
     * alone.
     */
    public function setManualCoordinates(int $id, ?float $latitude, ?float $longitude): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE camp_places SET latitude = ?, longitude = ?, coordinates_are_manual = 1, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$latitude, $longitude, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Takes another place's point during a merge, preserving whether a
     * human had placed it: a manual pin that became automatic on the way
     * over would be re-geocoded on the next task run, silently undoing
     * the very correction somebody made.
     */
    public function copyCoordinates(int $id, float $latitude, float $longitude, bool $manual): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE camp_places SET latitude = ?, longitude = ?, coordinates_are_manual = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$latitude, $longitude, $manual ? 1 : 0, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Archiving hides a place from every normal screen and from search.
     * Never a deletion: deleting a place would take its stays' history
     * with it, and the history is the module.
     */
    public function archive(int $id, bool $archived): void
    {
        $stmt = $this->pdo->prepare('UPDATE camp_places SET is_archived = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$archived ? 1 : 0, date('Y-m-d H:i:s'), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Place
    {
        return new Place(
            id: (int) $row['id'],
            name: (string) $row['name'],
            address: $this->nullableString($row['address']),
            postalCode: $this->nullableString($row['postal_code']),
            city: $this->nullableString($row['city']),
            country: $this->nullableString($row['country']),
            websiteUrl: $this->nullableString($row['website_url']),
            isArchived: (bool) $row['is_archived'],
            latitude: $row['latitude'] !== null ? (float) $row['latitude'] : null,
            longitude: $row['longitude'] !== null ? (float) $row['longitude'] : null,
            coordinatesAreManual: (bool) ($row['coordinates_are_manual'] ?? false),
            geocodedAt: $this->nullableString($row['geocoded_at'] ?? null),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
