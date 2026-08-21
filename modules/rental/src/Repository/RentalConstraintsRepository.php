<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Availability\BookingConstraints;

/**
 * The booking rules stored on the asset row itself — what a visitor may ask
 * for, as opposed to whether the asset is free.
 *
 * Its own repository rather than more methods on RentalAssetRepository:
 * constraints are their own configuration section, saved on their own
 * (§6.4), and the split keeps a partial post of one section from ever
 * touching another's columns.
 */
class RentalConstraintsRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function load(int $assetId): BookingConstraints
    {
        $stmt = $this->pdo->prepare(
            'SELECT min_nights, max_nights, min_notice_days, max_horizon_days,
                    allowed_arrival_weekdays, max_persons, buffer_nights
             FROM rental_assets WHERE id = ?'
        );
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            // An unknown asset gets the permissive defaults rather than an
            // exception: a caller rendering a calendar has already resolved
            // the asset, and throwing here would only turn a 404 into a 500.
            return new BookingConstraints();
        }

        return new BookingConstraints(
            minNights: (int) $row['min_nights'],
            maxNights: (int) $row['max_nights'],
            minNoticeDays: (int) $row['min_notice_days'],
            maxHorizonDays: (int) $row['max_horizon_days'],
            allowedArrivalWeekdays: self::parseWeekdays((string) $row['allowed_arrival_weekdays']),
            maxPersons: $row['max_persons'] !== null ? (int) $row['max_persons'] : null,
            bufferNights: (int) $row['buffer_nights']
        );
    }

    public function save(int $assetId, BookingConstraints $constraints): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rental_assets
             SET min_nights = ?, max_nights = ?, min_notice_days = ?, max_horizon_days = ?,
                 allowed_arrival_weekdays = ?, max_persons = ?, buffer_nights = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $constraints->minNights,
            $constraints->maxNights,
            $constraints->minNoticeDays,
            $constraints->maxHorizonDays,
            implode(',', $constraints->allowedArrivalWeekdays),
            $constraints->maxPersons,
            $constraints->bufferNights,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $assetId,
        ]);
    }

    /**
     * @return int[]
     */
    private static function parseWeekdays(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $days = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn(int $day) => $day >= 1 && $day <= 7
        )));
        sort($days);

        return $days;
    }
}
