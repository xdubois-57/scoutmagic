<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Modules\Rental\Availability\Occupancy;

/**
 * A period a manager took off the market (§6.18).
 *
 * Carries no renter, no price and no lifecycle — that is the whole reason
 * it is not a booking with a special status.
 */
final class RentalBlock
{
    public function __construct(
        public readonly int $id,
        public readonly int $assetId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?string $reason,
        public readonly ?int $createdByMemberId,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /**
     * The same shape a booking becomes, deliberately: a public visitor
     * cannot tell a block from a booking because there is nothing in an
     * `Occupancy` to tell them apart by (specifications.md §22.2).
     */
    /** How a block's occupancy names itself, so the managed calendar can set blocks apart (#708, IT-07). */
    public const OCCUPANCY_REFERENCE_PREFIX = 'block:';

    public function toOccupancy(): Occupancy
    {
        return new Occupancy(
            arrivalDate: $this->startDate,
            departureDate: $this->endDate,
            reference: self::OCCUPANCY_REFERENCE_PREFIX . $this->id,
            // `end_date` is the last day blocked, inclusive — that is what
            // findUpcoming()'s `end_date >= ?` and the manager calendar's
            // "du X au Y" both mean. A stay's departure day is not held
            // under the nights model; a block's end date always is, so it
            // must be flagged rather than left to the billing unit.
            endDateIsHeld: true,
            // The whole asset, however many units it has (#708, IT-07).
            wholeAsset: true
        );
    }
}
