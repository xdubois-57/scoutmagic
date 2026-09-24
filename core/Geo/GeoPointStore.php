<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * The manual lock on a point, written once for every table that carries one.
 *
 * A table that places something on the map declares four columns under
 * these exact names — `latitude DECIMAL(9, 6) NULL`, `longitude DECIMAL(9,
 * 6) NULL`, `coordinates_are_manual BOOLEAN NOT NULL DEFAULT FALSE` and
 * `geocoded_at DATETIME NULL` — plus the `updated_at` every table has. The
 * columns stay in the consumer's own table: this repository's schema is
 * declarative and has no way to move data from one table to another, so a
 * shared core table would have cost every installation the pins its chiefs
 * placed by hand. What moved to the core is the RULE, and every write that
 * could break it goes through here.
 *
 * **The rule: a human always wins.** `coordinates_are_manual` is set the
 * moment somebody types or moves a point, and NOTHING here ever clears it.
 * Automatic geocoding skips such a row for ever, and the fence is in the
 * SQL itself (`AND coordinates_are_manual = 0`), so even a direct call
 * cannot overwrite a hand-placed point. Somebody who moved the pin onto the
 * actual field knows something a gazetteer does not.
 *
 * **A failed lookup is a result.** It stamps `geocoded_at` — without that,
 * an address that means nothing to Nominatim is retried on every run for
 * ever and blocks the one-at-a-time queue behind it — and it leaves any
 * point already there untouched: no answer is not an answer of « nowhere ».
 *
 * **An automatic point belongs to the address it was found for.** When the
 * address changes, `forgetGeocoding()` drops it along with the stamp: kept,
 * it would survive a failed lookup of the new address and stay on the map,
 * stamped as done, at a place the row no longer describes.
 *
 * The table name is a constant of the calling repository, checked here
 * against a strict pattern before it is ever written into a statement;
 * every VALUE is bound.
 */
final class GeoPointStore
{
    private readonly string $table;

    public function __construct(private \PDO $pdo, string $table)
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('Not a table name: ' . $table);
        }
        $this->table = $table;
    }

    /**
     * The result of a geocoding attempt. A found point replaces an
     * automatic one; no point leaves whatever was there. Either way the
     * attempt is stamped, and a manual row is not touched at all.
     */
    public function recordGeocoding(int $id, ?GeoPoint $point, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
                SET latitude = COALESCE(?, latitude),
                    longitude = COALESCE(?, longitude),
                    geocoded_at = ?
              WHERE id = ? AND coordinates_are_manual = 0"
        );
        $stmt->execute([$point?->latitude, $point?->longitude, $at->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Puts an automatically-placed row back in the geocoding queue — for an
     * address that changed. `geocoded_at` means « we have tried this
     * address », so it has to be cleared when the address is a different
     * one, and the point found for the old address goes with it. A manual
     * row keeps its point and stays out of the queue.
     */
    public function forgetGeocoding(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
                SET latitude = NULL, longitude = NULL, geocoded_at = NULL
              WHERE id = ? AND coordinates_are_manual = 0"
        );
        $stmt->execute([$id]);
    }

    /**
     * A point a human typed or dragged — or removed, with null. Locks the
     * row as manual, for ever.
     */
    public function setManual(int $id, ?GeoPoint $point, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table}
                SET latitude = ?, longitude = ?, coordinates_are_manual = 1, updated_at = ?
              WHERE id = ?"
        );
        $stmt->execute([$point?->latitude, $point?->longitude, $at->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Another row's point, carrying over whether a human placed it — a
     * manual pin that became automatic on the way over would be
     * re-geocoded by the next run, silently undoing the correction. Never
     * turns a manual row back into an automatic one.
     */
    public function copy(int $id, GeoPoint $point, bool $manual, \DateTimeImmutable $at): void
    {
        $lock = $manual ? ', coordinates_are_manual = 1' : '';
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET latitude = ?, longitude = ?{$lock}, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([$point->latitude, $point->longitude, $at->format('Y-m-d H:i:s'), $id]);
    }
}
