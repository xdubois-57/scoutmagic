<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

use Core\Service\DateInput;

/**
 * What is being priced: a date range and how much of the asset is wanted.
 *
 * Dates are `Y-m-d` and the range is expressed the way a renter says it —
 * "from the 17th to the 20th". Turning that into nights or into full days is
 * BillingUnit's job, never the caller's, so the public page, the simulator
 * and the contract cannot disagree about what "to the 20th" means.
 */
final class PricingRequest
{
    /**
     * @param string $arrivalDate `Y-m-d`.
     * @param string $departureDate `Y-m-d`. The day the renter leaves — whether it is itself billed depends on the billing unit.
     * @param int $persons Expected head count. Actual, never pre-raised to a minimum: applying the minimum is the engine's job and has to stay visible in the result.
     * @param int $units How many items of a countable stock are wanted (three of eight tents). Ignored by the person-based and per-room billing units.
     * @param int $rooms How many rooms are wanted. Only used by BillingUnit::PER_ROOM_DAY.
     * @param int|null $renterCategoryId Second axis of the price grid; null means "not stated yet", which prices from the default rate and warns.
     */
    public function __construct(
        public readonly string $arrivalDate,
        public readonly string $departureDate,
        public readonly int $persons = 0,
        public readonly int $units = 1,
        public readonly int $rooms = 1,
        public readonly ?int $renterCategoryId = null
    ) {
    }

    public function arrival(): \DateTimeImmutable
    {
        return DateInput::requireFromStorage($this->arrivalDate, 'a pricing request arrival date');
    }

    public function departure(): \DateTimeImmutable
    {
        return DateInput::requireFromStorage($this->departureDate, 'a pricing request departure date');
    }

    /**
     * Nights between arrival and departure — the half-open count, so the
     * 17th to the 20th is three nights (17, 18, 19).
     *
     * Computed from whole dates via the day difference rather than from a
     * seconds delta: a stay crossing a daylight-saving change is 23 or 25
     * hours long, and a seconds-based count silently loses or gains a night
     * twice a year. Belgium changes clocks in late March and late October,
     * both squarely in scout camp season.
     */
    public function nights(): int
    {
        $arrival = $this->arrival()->setTime(0, 0);
        $departure = $this->departure()->setTime(0, 0);

        return max(0, (int) $arrival->diff($departure)->format('%r%a'));
    }
}
