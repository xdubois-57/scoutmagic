<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * How far from the public year another scout year may be and still take
 * part in an access decision: **the public year itself, or the one right
 * after it.**
 *
 * One rule, one home, because it is load-bearing in two places that would
 * otherwise drift apart — and did. `AuthorizationYearService` applies it
 * to the years a ROLE may be resolved in; `ScoutYearResolver` applies it
 * to the staff year before serving it, which is the year every per-year
 * authorization check then runs in. A bound on one half and none on the
 * other is not half a rule, it is a hole: whichever half is unbounded is
 * the one an attacker uses.
 *
 * **Two years away is out in both directions.** On an instance where
 * nobody ever runs the transition, the public year and the calendar drift
 * apart indefinitely and nothing forces anyone to close the gap; without
 * a bound, every chief who left two seasons ago would keep their access
 * for as long as that lasts.
 *
 * **The interval is asymmetric on purpose, and this is the half that is
 * easy to get wrong.** A year BEFORE the public year is out too, one year
 * or two. Both mechanisms that legitimately put a year in play push it
 * FORWARD — activating the staff year, and 1 September moving the
 * date-computed year ahead of a public year nobody has switched yet. A
 * year behind the public year means the opposite happened: a chef
 * d'unité switched the whole site to the next year early, which is an
 * explicit « we are on the new year now ». Keeping it would hand the
 * animateur who has just left their access back for every day between
 * that switch and 1 September.
 *
 * `staff_scout_year_id` is written with no validation of its own
 * (`ScoutYearAdminService::activateStaffYear()`), and the page that
 * writes it offers every year on record. So this predicate is enforced
 * where the value is USED rather than where it is stored: a setting that
 * arrived from a restored backup, a hand-edited row or an older release
 * gets the same answer as one typed today.
 */
final class ScoutYearAdjacency
{
    /**
     * Whether $label may take part in a decision anchored on $publicLabel.
     *
     * Scout year labels are `YYYY-YYYY` and their first half orders them,
     * so this is a subtraction rather than a date range.
     */
    public static function isCandidateFor(string $label, string $publicLabel): bool
    {
        $distance = self::startYear($label) - self::startYear($publicLabel);

        return $distance >= 0 && $distance <= 1;
    }

    private static function startYear(string $label): int
    {
        return (int) explode('-', $label)[0];
    }
}
