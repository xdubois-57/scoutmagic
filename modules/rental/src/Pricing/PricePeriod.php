<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * One season on the price grid's first axis — "haute saison", "vacances de
 * Pâques", whatever the unit calls it. Configurable per asset; an asset with
 * a single rate all year simply has none.
 *
 * Dates are stored as `Y-m-d` strings and compared as strings, which is
 * correct for that format and keeps the object free of timezone questions
 * the rest of the module does not have either.
 *
 * A period may **recur yearly**: "1 July → 31 August, every year" is how a
 * high season is actually expressed, and forcing an operator to re-enter it
 * each year is how it ends up wrong. A recurring period matches on
 * month/day only, and correctly covers a range that wraps the new year
 * (e.g. 20 December → 5 January).
 */
final class PricePeriod
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        /** `Y-m-d`. For a recurring period only the month and day matter. */
        public readonly string $startDate,
        /** `Y-m-d`, inclusive. */
        public readonly string $endDate,
        public readonly bool $recursYearly = false
    ) {
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        if (!$this->recursYearly) {
            $day = $date->format('Y-m-d');

            return $day >= $this->startDate && $day <= $this->endDate;
        }

        $day = $date->format('m-d');
        $start = substr($this->startDate, 5);
        $end = substr($this->endDate, 5);

        // A period that wraps the new year (20-12 → 05-01) has start > end,
        // so it matches OUTSIDE the interval rather than inside it. Getting
        // this backwards silently prices the Christmas holidays as low
        // season, which is exactly when a scout hall is busiest.
        return $start <= $end
            ? ($day >= $start && $day <= $end)
            : ($day >= $start || $day <= $end);
    }
}
