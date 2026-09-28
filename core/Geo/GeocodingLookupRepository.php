<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * One row per address lookup Core\Geo\AddressLocator had to queue for
 * Nominatim — so that, per account, a lookup still waiting for its turn
 * gives way to a newer one (issue #692). A chief who corrects an address
 * while the first lookup waits wants the correction looked up, not both.
 *
 * It used to feed a per-account quota (30 per 10 minutes); the maintainer
 * decided a quota must never be the reason an address is not found, and
 * a waiting request giving way to a newer one keeps one account from
 * sending a burst.
 *
 * The row holds an account id and an instant, never the address. An
 * answer served from the cache never creates one.
 */
class GeocodingLookupRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * A lookup of this account joins the queue; its id orders it against
     * the account's others. created_at is stamped from PHP, the clock
     * deleteOlderThan() compares against.
     */
    public function record(int $userAccountId, \DateTimeImmutable $at): int
    {
        $this->pdo->prepare('INSERT INTO geocoding_lookups (user_account_id, created_at) VALUES (?, ?)')
            ->execute([$userAccountId, $at->format('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The newest lookup this account has queued, or 0 when none. */
    public function latestId(int $userAccountId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(id) FROM geocoding_lookups WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        return (int) $stmt->fetchColumn();
    }

    /** Task\PurgeGeocodingHandler's cleanup: rows no lookup can still be waiting on. */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM geocoding_lookups WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
