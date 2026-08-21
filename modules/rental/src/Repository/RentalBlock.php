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
        public readonly int $units,
        public readonly ?string $reason,
        public readonly ?int $createdByMemberId,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /**
     * The same shape a booking becomes, deliberately: a public visitor
     * cannot tell a block from a booking because there is nothing in an
     * `Occupancy` to tell them apart by (§6.7).
     */
    public function toOccupancy(): Occupancy
    {
        return new Occupancy(
            arrivalDate: $this->startDate,
            departureDate: $this->endDate,
            units: max(1, $this->units),
            reference: 'block:' . $this->id
        );
    }
}
