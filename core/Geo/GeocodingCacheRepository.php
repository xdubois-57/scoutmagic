<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * Addresses already looked up while a form was being filled in
 * (Core\Geo\AddressLocator), so the same place asked twice costs Nominatim
 * one request instead of two.
 *
 * The key is a FINGERPRINT of the folded address, never the address: the
 * cache needs to recognise a line, not to remember it. What it keeps is
 * the answer — a point, or none — and when it was obtained.
 *
 * « Nothing found » is an answer too and is cached, for less time: a typo
 * would otherwise be sent again on every visit of the form.
 */
class GeocodingCacheRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return array{point: ?GeoPoint, looked_up_at: string}|null null when
     *         this address was never looked up (or its row was purged)
     */
    public function find(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT latitude, longitude, looked_up_at FROM geocoding_cache WHERE fingerprint = ?'
        );
        $stmt->execute([$fingerprint]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'point' => GeoPoint::fromColumns($row['latitude'], $row['longitude']),
            'looked_up_at' => (string) $row['looked_up_at'],
        ];
    }

    /**
     * Replaces whatever was known about this address. Always called under
     * AddressLocator's advisory lock, so the delete-then-insert cannot race
     * another writer of the same fingerprint — and it spells the same on
     * MySQL and on the SQLite the fast tests use, which no upsert does.
     */
    public function store(string $fingerprint, ?GeoPoint $point, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare('DELETE FROM geocoding_cache WHERE fingerprint = ?')->execute([$fingerprint]);
        $this->pdo->prepare(
            'INSERT INTO geocoding_cache (fingerprint, latitude, longitude, looked_up_at) VALUES (?, ?, ?, ?)'
        )->execute([$fingerprint, $point?->latitude, $point?->longitude, $at->format('Y-m-d H:i:s')]);
    }

    /** Task\PurgeGeocodingHandler's cleanup: rows no lookup can still use. */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM geocoding_cache WHERE looked_up_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
