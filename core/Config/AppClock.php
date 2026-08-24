<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Config;

/**
 * The one clock this application runs on.
 *
 * ScoutMagic serves Belgian scout units. "Aujourd'hui", "demain 04:00", a
 * camp's start date, a duty roster's hours and every timestamp rendered on
 * a page mean Belgian wall-clock time to the people reading them — so that
 * is the timezone PHP runs in (`public/index.php`, `public/cron.php` and
 * `tests/bootstrap.php` all call self::apply() as their first act) and the
 * timezone every naive DATETIME in the database is expressed in.
 *
 * The second half of that invariant is what makes the first half safe.
 * Part of this codebase writes a timestamp from PHP and part of it lets a
 * column's `DEFAULT CURRENT_TIMESTAMP` do it, and rate limiters, retention
 * cutoffs and the scheduler then compare the two against each other. Both
 * sides therefore have to be on this same clock:
 *
 *  - PHP's default timezone is set from here, at the entry point;
 *  - every MySQL connection's session `time_zone` is aligned with PHP's
 *    current offset at connect time (Core\Database\Connection);
 *  - SQLite (tests only) can do neither — its `CURRENT_TIMESTAMP` is UTC
 *    and it has no session timezone — so any repository whose timestamps
 *    are ever compared against a PHP-computed instant writes them from PHP
 *    rather than leaning on the column default. That is the long-standing
 *    convention in this tree ("computed in PHP and bound as a parameter,
 *    never MySQL's NOW()"), and it is now the rule rather than a habit.
 *
 * Code that needs a *fixed* zone regardless of the ambient default — the
 * groups module's edit windows, the auto-update scheduler's wall-clock
 * slot — pins self::TIMEZONE explicitly rather than assuming the process
 * happens to be configured. See docs/module-development.md § Database.
 */
final class AppClock
{
    public const TIMEZONE = 'Europe/Brussels';

    /**
     * Make PHP's default timezone the application clock. Called once, as
     * early as possible, by each real entry point.
     */
    public static function apply(): void
    {
        date_default_timezone_set(self::TIMEZONE);
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * "Now" on the application clock, independent of the ambient default
     * timezone — for the few places that must not drift if a caller (or a
     * test) has changed it underneath them.
     */
    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::zone());
    }
}
