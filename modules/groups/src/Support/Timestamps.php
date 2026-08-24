<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

use Core\Config\AppClock;

/**
 * Every timestamp this module writes and compares goes through here, on the
 * application clock (Core\Config\AppClock) and pinned to it explicitly
 * rather than taken from PHP's ambient default timezone.
 *
 * Two separate reasons, and both still hold:
 *
 *  - **The window must not drift.** The post edit window (15 minutes from
 *    creation, Service\PostService) is a security-relevant comparison
 *    between a stored value and "now". If the two were produced under
 *    different timezones the window would silently widen or vanish, so both
 *    ends come from here — and naming the zone means a caller that has
 *    changed `date_default_timezone_set()` underneath us cannot move a
 *    deadline by hours.
 *  - **It must be the SAME clock as everything else.** These columns are
 *    read back by core (the `relative_date`/`datetime_fr` filters render
 *    them straight to the page) and compared against rows other parts of
 *    the app wrote. This used to be UTC, which was right while the whole
 *    application ran on UTC and became wrong the moment it stopped: a post
 *    written at 14:30 would have displayed as 12:30 and read "il y a
 *    2 heures" the second it was published.
 */
final class Timestamps
{
    public static function now(): string
    {
        return AppClock::now()->format('Y-m-d H:i:s');
    }

    /**
     * A storable timestamp $modifier away from now ('-10 minutes',
     * '+7 days'), on the same clock as now() — the shape every window and
     * retention cutoff in this module needs, and the reason none of them
     * has to reach for a DateTimeZone of its own.
     */
    public static function at(string $modifier): string
    {
        return AppClock::now()->modify($modifier)->format('Y-m-d H:i:s');
    }

    /**
     * Parse a value this module stored (or a core `DEFAULT CURRENT_TIMESTAMP`
     * one — same clock either way) so arithmetic on it can never pick up a
     * different timezone than the one it was written under.
     */
    public static function parse(string $stored): \DateTimeImmutable
    {
        return new \DateTimeImmutable($stored, AppClock::zone());
    }
}
