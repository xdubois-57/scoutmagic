<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * **The one lock** every write that creates, moves or lengthens an
 * occupation of an asset takes (#927, chantier §2.1): the asset's own row,
 * `SELECT … FROM rental_assets WHERE id = ? FOR UPDATE`, inside one
 * transaction. A public request, an option, a contract sent, a
 * confirmation, a copy received or refused, a manual block — each re-checks
 * availability under it and writes before letting go.
 *
 * **Never the booking rows.** A `FOR UPDATE` over `rental_bookings WHERE
 * asset_id = ?` locks nothing on an asset with no booking yet, so the first
 * two requests for a fresh hall both read it free and both landed — which
 * is exactly how it used to be. The asset row always exists; every writer
 * meets on it.
 *
 * **Lock order: the asset first, then anything else.** A gesture that also
 * holds a booking's row (`RentalDocumentRepository::withBookingLocked()`)
 * takes it inside this one, never around it: the other order against a
 * confirmation — asset row, then an `UPDATE` of the booking — is a
 * deadlock.
 *
 * Re-entrant: inside a transaction already open, the lock joins it and the
 * outer caller commits. SQLite, the test engine, has no `FOR UPDATE` and
 * serialises writers on its own, so it gets the same guarantee through its
 * whole-database write lock.
 */
final class AssetLock
{
    private function __construct()
    {
    }

    /**
     * Runs `$work` holding `$assetId`'s row, as one transaction. A failure
     * inside rolls back everything it wrote.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(\PDO $pdo, int $assetId, callable $work): mixed
    {
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lock = $pdo->prepare('SELECT id FROM rental_assets WHERE id = ? FOR UPDATE');
                $lock->execute([$assetId]);
                $lock->fetchAll();
            }

            $result = $work();

            if ($ownTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
