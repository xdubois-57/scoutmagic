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
 *
 * **`$isFirm` is the one distinction that survives**, and it is not a public
 * one: it never changes what a visitor sees, because to a visitor the dates
 * are taken either way. It exists for a single question only — "may a
 * manager confirm this?" — where a temporary hold on somebody else's pending
 * request must NOT stand in the way. Two requests for the same week are
 * exactly the situation a manager is there to arbitrate; if a soft hold
 * blocked the confirmation, neither could ever be accepted. A confirmed
 * booking or a manual block is a different matter: those are commitments,
 * and they do stop a confirmation.
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
     * @param string|null $reference An internal identifier for the manager-facing calendar. NEVER rendered publicly — see the class docblock.
     * @param bool $isFirm Whether this is a commitment or merely a temporary hold. See the note below.
     * @param bool $endDateIsHeld Whether `$departureDate` is itself taken, whatever the billing unit. See the note below.
     */
    public function __construct(
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $units = 1,
        public readonly ?string $reference = null,
        public readonly bool $isFirm = true,
        public readonly bool $endDateIsHeld = false
    ) {
    }
}
