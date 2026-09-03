<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats;

use Core\Config\ScoutYearService;

/**
 * How long the counters are kept — written down rather than left at
 * « indefinitely » by omission, which is what a table nobody purges
 * actually means.
 *
 * **Three scout years**: the one running and the two before it. That is
 * the span the screens can draw (a twelve-month curve, and last year's
 * same month beside this one's) plus one year of margin for a chief
 * comparing three seasons. Past that the rows answer no question anybody
 * asks and keeping them would be a retention decision made by inaction.
 *
 * The boundary is the scout year's, not the calendar's: a season starts on
 * 1 September (Core\Config\ScoutYearService::labelForDate()), and cutting
 * in the middle of one would delete the autumn of a year whose spring is
 * still on screen.
 */
final class Retention
{
    public const SCOUT_YEARS_KEPT = 3;

    /**
     * The oldest month that survives, as 'YYYY-MM'. Everything strictly
     * before it goes.
     *
     * With `SCOUT_YEARS_KEPT = 3` and « now » anywhere in 2026-2027, this
     * is `2024-09`: September 2024 is the first month of 2024-2025, the
     * third-most-recent season.
     */
    public static function cutoffMonth(\DateTimeImmutable $now): string
    {
        $currentSeasonStartYear = (int) explode('-', ScoutYearService::labelForDate($now))[0];

        return sprintf('%04d-09', $currentSeasonStartYear - (self::SCOUT_YEARS_KEPT - 1));
    }
}
