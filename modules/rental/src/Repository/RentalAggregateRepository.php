<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * What survives a purge (§6.35).
 *
 * **Nothing here can be tied back to a person**, and that is the whole
 * design: no booking id, no reference, no token, no name, no file. An
 * asset, a month, a number of days and an amount — precisely what the
 * overview's three figures need (§6.34), and nothing that would make this
 * table a way around the deletion it exists alongside.
 *
 * There is deliberately no `findByBookingId()` and no way back: once the
 * booking is gone, the link is gone, which is the point.
 */
class RentalAggregateRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function record(
        int $assetId,
        string $stayMonth,
        int $occupiedDays,
        int $amountCents,
        ?int $scoutYearId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_booking_aggregates
                (asset_id, stay_month, occupied_days, amount_cents, scout_year_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId,
            $stayMonth,
            max(0, $occupiedDays),
            max(0, $amountCents),
            $scoutYearId,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The purged-away half of §6.34's figures, for one asset over a date
     * range.
     *
     * Returned as a pair rather than two queries because the caller always
     * needs both, and asking twice for one row's worth of sums is one round
     * trip too many on a page that already runs several.
     *
     * @return array{days: int, amount_cents: int}
     */
    public function totalsForAsset(int $assetId, string $fromMonth, string $toMonth): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(occupied_days), 0) AS days, COALESCE(SUM(amount_cents), 0) AS amount_cents
               FROM rental_booking_aggregates
              WHERE asset_id = ? AND stay_month >= ? AND stay_month <= ?'
        );
        $stmt->execute([$assetId, $fromMonth, $toMonth]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'days' => is_array($row) ? (int) $row['days'] : 0,
            'amount_cents' => is_array($row) ? (int) $row['amount_cents'] : 0,
        ];
    }

    public function countForAsset(int $assetId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rental_booking_aggregates WHERE asset_id = ?');
        $stmt->execute([$assetId]);

        return (int) $stmt->fetchColumn();
    }
}
