<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Service;

/**
 * Reads a whole number that came from a form, refusing what the column it
 * is headed for could not hold.
 *
 * The idiom this replaces is `(int) $request->getBody('capacity')`, often
 * with a floor — `max(0, (int) …)` — and never with a ceiling. A floor
 * without a ceiling is exactly half a bound, and the missing half is the
 * one a visitor can reach: every one of these columns is `INT UNSIGNED`,
 * so 4 294 967 296 is a value the form accepts, the cast preserves, and
 * MySQL refuses with «Out of range value for column» — a PDOException
 * nobody catches, five frames below a controller, and a 500 page. A
 * dynamic scan reached that on four separate fields (`capacity`,
 * `min_nights`, `vote_budget`, `member_id`) simply by typing a long
 * number, which is as much sophistication as it takes.
 *
 * Two things it deliberately does NOT do:
 *
 * It does not **clamp**. Storing 4 294 967 295 because the visitor typed
 * something larger records a number they never chose, and does it
 * silently — the same sin as a date library rolling the 31st of February
 * forward to the 3rd of March. Out of range is refused, and the caller
 * says so in French.
 *
 * It does not **salvage**. `(int) '12 places'` is 12 in PHP, which reads
 * as helpful right up to the day a mistyped field is stored as a number
 * that was never in it. A value is digits or it is nothing.
 *
 * The bounds here are the storage layer's, not the product's: nothing in
 * this class knows that a scout hall sleeps fewer than four billion
 * people. A field with a real-world ceiling should state it, by passing
 * its own $max.
 */
final class IntegerInput
{
    /**
     * `INT UNSIGNED`, which is what essentially every count, capacity,
     * delay and foreign key in this schema is declared as.
     */
    public const UNSIGNED_INT_MAX = 4294967295;

    /**
     * `INT` — for the few columns that are signed.
     */
    public const SIGNED_INT_MAX = 2147483647;

    /**
     * The value as an integer within [$min, $max], or null when it is
     * absent, blank, not written as a whole number, or outside those
     * bounds.
     */
    public static function bounded(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            return $value >= $min && $value <= $max ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Written as a whole number and nothing else. `is_numeric` is not
        // the check to make here: it accepts '1e3', '0x1A' and ' 12.0',
        // each of which a cast turns into some number the visitor did not
        // type.
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        // Past PHP_INT_MAX the cast saturates rather than failing, so
        // `(int) '99999999999999999999'` is PHP_INT_MAX — a different
        // number, and one that would pass any range check whose ceiling
        // is PHP_INT_MAX. Casting back and comparing is what proves the
        // value survived the trip; leading zeros are normalised away
        // first so '007' compares equal to 7 rather than looking lost.
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $canonical = $digits === '' ? '0' : ($negative ? '-' : '') . $digits;

        $number = (int) $canonical;
        if ((string) $number !== $canonical) {
            return null;
        }

        return $number >= $min && $number <= $max ? $number : null;
    }

    /**
     * A row identifier as it arrived from a request.
     *
     * Zero is refused on purpose: no row has id 0, so a 0 here is the
     * signature of `(int) $somethingThatWasNeverAnId`.
     *
     * That cast is also what an active scanner probes for. It replaces
     * an id with an arithmetic expression evaluating to the same number
     * — `4/2`, `4-2` where the id was 2 — on the theory that an
     * unchanged response means the database evaluated it. PHP does
     * something else again: the cast stops at the first non-digit, so
     * both become **4**, which is neither what the visitor sent nor what
     * the expression evaluates to. There is no injection here (every
     * statement is prepared) and the scanner's conclusion is wrong, but
     * its suspicion was not baseless: the endpoint really was acting on
     * a row nobody named. Refusing the value answers both.
     */
    public static function id(mixed $value): ?int
    {
        return self::bounded($value, 1, self::UNSIGNED_INT_MAX);
    }

    /**
     * The common case: a non-negative value headed for an
     * `INT UNSIGNED` column.
     */
    public static function unsigned(mixed $value): ?int
    {
        return self::bounded($value, 0, self::UNSIGNED_INT_MAX);
    }

    /**
     * Same, for a field whose form has a default and whose out-of-range
     * value the caller would rather ignore than report — a page-number
     * or per-page control, never a value the visitor is storing.
     */
    public static function unsignedOr(mixed $value, int $fallback): int
    {
        return self::unsigned($value) ?? $fallback;
    }
}
