<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

/**
 * Something that takes an asset for a date range: a booking, a temporary
 * hold, or a manual block.
 *
 * **All three are the same object here, on purpose.** A public visitor must
 * not be able to tell them apart (module spec §6.7/§6.14) — a period that is
 * held for someone else, booked, or closed for maintenance all read as
 * "occupé" with no reason given. Making the calculator blind to the
 * difference is stronger than making the template hide it: there is no
 * variant of the data to leak in the first place.
 *
 * The dates are expressed the way a renter says them — "from the 17th to the
 * 20th". Whether the departure day is itself taken depends on the asset's
 * billing unit, and resolving that is AvailabilityCalculator's job, never
 * the caller's.
 */
final class Occupancy
{
    /**
     * @param string $arrivalDate `Y-m-d`.
     * @param string $departureDate `Y-m-d`.
     * @param int $units How much of a countable stock this takes. 1 for an exclusive asset.
     * @param string|null $reference An internal identifier for the manager-facing calendar. NEVER rendered publicly — see the class docblock.
     */
    public function __construct(
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $units = 1,
        public readonly ?string $reference = null
    ) {
    }
}
