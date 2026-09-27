<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * One row per request Core\Geo\AddressLocator actually sent to Nominatim,
 * for the per-account quota — the shape of
 * Core\Help\Assistant\AssistantRateLimitRepository. An answer served from
 * the cache costs nothing and is not counted.
 *
 * The row holds an account id and an instant, never the address: the
 * quota needs to count, not to remember.
 */
class GeocodingLookupRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * created_at is stamped from PHP, as the assistant's limiter does, so
     * that countSince() compares against the same clock it was written by.
     */
    public function record(int $userAccountId, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare('INSERT INTO geocoding_lookups (user_account_id, created_at) VALUES (?, ?)')
            ->execute([$userAccountId, $at->format('Y-m-d H:i:s')]);
    }

    public function countSince(int $userAccountId, string $sinceDatetime): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM geocoding_lookups WHERE user_account_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userAccountId, $sinceDatetime]);

        return (int) $stmt->fetchColumn();
    }

    /** Task\PurgeGeocodingHandler's cleanup: rows past the quota window. */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM geocoding_lookups WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
