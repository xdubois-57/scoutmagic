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
 * not be able to tell them apart (specifications.md §22.2) — a period that is
 * held for someone else, booked, or closed for maintenance all read as
 * "occupé" with no reason given. Making the calculator blind to the
 * difference is stronger than making the template hide it: there is no
 * variant of the data to leak in the first place.
 *
 * The dates are expressed the way a renter says them — "from the 17th to the
 * 20th". Whether the departure day is itself taken depends on the asset's
 * billing unit, and resolving that is AvailabilityCalculator's job, never
 * the caller's.
 *
 * **Nothing distinguishes a firm hold from a provisional one here**, and
 * that is the rule rather than an omission (#927, chantier §2.1). A
 * `$isFirm` flag used to let a confirmation look past a competing request's
 * hold, so two requests for the same week could both end up confirmed by
 * two managers who each thought they were arbitrating. Whatever occupies the
 * asset now stops everybody else, managers included: whether a booking
 * holds its dates at all is decided before it becomes an `Occupancy`
 * (`RentalBooking::occupiesTheAsset()`), and once it does, it is taken.
 *
 * **`$reference` is also who it belongs to.** Every occupancy of one
 * booking carries that booking's reference — its own dates, and the dates
 * of its active modification project once that exists (chantier §2.3) — so
 * excluding a reference leaves a booking out of its own conflicts, and its
 * project out of its own, and never out of anybody else's.
 *
 * **`$endDateIsHeld` is not a second billing unit.** A *stay* is half-open
 * under the nights model — the departure day frees up for the next arrival,
 * which is the whole point of letting a hall by night. A *manual block* is
 * not a stay: "du 10 au 11" means both days are off the market, and it means
 * that whether the asset is let by night or by day. Without this flag the
 * night-based rule silently dropped a block's last day, so a caretaker who
 * blocked two days for cleaning got one, and the second was bookable.
 */
final class Occupancy
{
    /**
     * @param string $arrivalDate `Y-m-d`.
     * @param string $departureDate `Y-m-d`.
     * @param int $units How much of a countable stock this takes. 1 for an exclusive asset.
     * @param string|null $reference An internal identifier for the manager-facing calendar, and the key a booking is
     *     left out of its own conflicts by. NEVER rendered publicly — see the class docblock.
     * @param bool $endDateIsHeld Whether `$departureDate` is itself taken, whatever the billing unit. See the note
     *     below.
     * @param bool $wholeAsset Whether it takes every unit of the asset, however many there are — a period the unit
     *     blocks (#708, IT-07). Not a large `$units`: an asset that grows from 10 to 12 units must stay closed on a
     *     period meant to be closed, which a stored count could never promise.
     */
    public function __construct(
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $units = 1,
        public readonly ?string $reference = null,
        public readonly bool $endDateIsHeld = false,
        public readonly bool $wholeAsset = false
    ) {
    }
}
