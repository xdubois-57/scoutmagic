<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Service;

/**
 * Reads a date that came from somewhere untrusted — a form field, a query
 * string, a CSV cell, a column — and answers whether it is one, without
 * ever throwing.
 *
 * Both of PHP's ways of doing this have a trap, and they are opposite
 * traps, which is why nearly every site in this codebase that reads a
 * submitted date had one of them:
 *
 * `DateTimeImmutable::createFromFormat($f, $v)` looks total — it returns
 * `false` for "../../.." and for "9999-99-99" — so ~20 sites wrote
 * `$d = createFromFormat('Y-m-d', $v); return $d !== false && …;` and
 * believed that covered everything. It does not: a `$v` containing a NUL
 * byte raises a **ValueError**, uncaught, five frames above a controller
 * that has no idea a date parse can fail loudly. A dynamic scan found
 * exactly that, twice, by sending `2026-01-01%00` — the payload costs
 * nothing to send and every one of those sites turns it into a 500.
 *
 * `new DateTimeImmutable($v)` fails the other way. It throws
 * `DateMalformedStringException` on "../../..", so it looks stricter —
 * but it accepts `""`, `"now"`, `"yesterday"` and `"a\0b"`, silently
 * returning **the current moment** for all four. An unvalidated field
 * then becomes today's date and is written as if the visitor had typed
 * it, which nothing anywhere will ever report.
 *
 * So: one place, two entry points, no throw and no silent "now".
 *
 * Deliberately NOT a general date library. It answers one question — is
 * this string the date it claims to be — and returns `null` when the
 * answer is no. Formatting, arithmetic and display stay where they are.
 */
final class DateInput
{
    /**
     * The one format almost every caller wants: an ISO calendar date, no
     * time. Named so a reader does not have to know whether this file
     * spells it 'Y-m-d' or 'Y/m/d'.
     *
     * The leading `!` is not decoration. `createFromFormat()` fills every
     * field the format does not mention from the CURRENT time, so
     * `createFromFormat('Y-m-d', '2026-08-01')` is the first of August at
     * whatever o'clock it happens to be — while
     * `new DateTimeImmutable('2026-08-01')`, which is what most callers
     * are used to, is midnight. That difference is invisible in a
     * `format('d/m/Y')` and decisive the moment two of these are compared
     * or subtracted, and it is silent either way. `!` resets the unnamed
     * fields instead, which is what a date with no time should mean; every
     * caller in the project that spells its own format out already writes
     * it (`'!d/m/Y'`, `'!Y-m-d'`).
     */
    public const ISO_DATE = '!Y-m-d';

    /**
     * `Y-m-d` with a `datetime-local` input's `T` separator, which is what
     * a browser posts for `<input type="datetime-local">`. Reset for the
     * same reason as ISO_DATE: the format names no seconds, so without the
     * `!` a submitted 09:30 would carry the current second.
     */
    public const ISO_DATETIME_LOCAL = '!Y-m-d\TH:i';

    /**
     * Parse $value as exactly $format, or return null.
     *
     * Round-trip checked: `createFromFormat` happily rolls 2026-02-31
     * forward to 2026-03-03, so a value that does not format back to
     * itself is refused rather than quietly corrected. That check is what
     * the sites this replaces were already doing by hand; it is kept
     * because it is right, not merely because it was there.
     *
     * A leading `!` or `|` in $format (reset the unspecified fields) is
     * stripped for the round-trip comparison only — it steers the parse,
     * it is not part of what the value should look like.
     *
     * $exact = false keeps the NUL guard and drops only the round-trip,
     * for the callers that must accept what a third party actually
     * writes — a bank's CSV putting `1/2/2026` where the format says
     * `d/m/Y`. It is a narrower guarantee: PHP will then roll `31/02`
     * forward to 3 March rather than refuse it, so use it only where the
     * input's shape is somebody else's decision, never for a form.
     */
    public static function parse(string $format, string $value, bool $exact = true): ?\DateTimeImmutable
    {
        // Before anything else, and the whole reason this class exists.
        // A real date contains no control character, so refusing them
        // costs no legitimate input — and NUL is the one that turns
        // createFromFormat() from a function that returns false into one
        // that raises a ValueError.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed === false) {
            return null;
        }

        if (!$exact) {
            return $parsed;
        }

        $comparisonFormat = ltrim($format, '!|');

        return $parsed->format($comparisonFormat) === $value ? $parsed : null;
    }

    public static function isValid(string $format, string $value): bool
    {
        return self::parse($format, $value) !== null;
    }

    /**
     * The `Y-m-d` shorthand, which is the great majority of call sites.
     */
    public static function iso(string $value): ?\DateTimeImmutable
    {
        return self::parse(self::ISO_DATE, $value);
    }

    public static function isIso(string $value): bool
    {
        return self::parse(self::ISO_DATE, $value) !== null;
    }

    /**
     * `Y-m-d`, trimmed, returned as a string — for the many callers that
     * want the value back to hand to SQL rather than a date object. Null
     * when the value is absent, blank, or not a date.
     *
     * Trimming is done here because a form field is where these come from
     * and a trailing space is not a different date. It is applied BEFORE
     * the control-character check on purpose: `trim()` removes `\t`, `\n`,
     * `\r` and `\0` at the ends, so " 2026-01-01\0" is a date with
     * whitespace around it, while "2026-01\0-01" is not a date at all and
     * must stay refused.
     */
    public static function isoStringOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && self::isIso($value) ? $value : null;
    }

    /**
     * The first day of a month, from a year and a month number.
     *
     * `new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month))` was
     * written out ELEVEN times, identically, in month grids, availability
     * calculators and on-call planners. Eleven copies of a composition is
     * eleven places for the same question — what if the month is 13? —
     * and none of them asked it: the sprintf produced "2026-13-01", the
     * constructor raised a DateMalformedStringException from four frames
     * down, and the page 500ed with a message about a string.
     *
     * Here it is one place, and the refusal names the values it was
     * given. The bounds are the format's own — `Y-m-d` writes a year in
     * four digits, and a month is one of twelve — so nothing about a
     * product rule is invented.
     *
     * @throws \InvalidArgumentException when there is no such month
     */
    public static function firstOfMonth(int $year, int $month): \DateTimeImmutable
    {
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException("No such month: {$year}-{$month}.");
        }

        // Midnight, because ISO_DATE resets — which is what the
        // `new DateTimeImmutable(sprintf(...))` this replaces answered, and
        // what a calendar comparing months against each other needs
        // (Rental\Availability\MonthWindow pages back on `<=`).
        $first = self::iso(sprintf('%04d-%02d-01', $year, $month));
        \assert($first !== null);

        return $first;
    }

    /**
     * The same reading, for a value the schema says is always there.
     *
     * A `NOT NULL DATETIME` that does not parse is corrupt storage, and
     * the honest answer to corrupt storage is to stop. What this replaces
     * did something worse than stopping: `new DateTimeImmutable('')` is
     * *now*, so an empty column rendered as today and was believed, and
     * MySQL's zero date read as the 30th of November, year -1. Both were
     * silent. This is loud, and for a value that parses it is the same
     * object as before, byte for byte.
     *
     * $what names the column so the log says which one, and is written
     * in English and never shown to a visitor: it reaches
     * Core\Http\ErrorHandler as a 500, which is what a corrupt row
     * deserves (SECURITY.md § 35).
     *
     * $timezone is passed straight through to the constructor and means
     * what it means there — see fromStorage().
     *
     * @throws \RuntimeException when $value is not a stored date
     */
    public static function requireFromStorage(
        ?string $value,
        string $what,
        ?\DateTimeZone $timezone = null
    ): \DateTimeImmutable {
        return self::fromStorage($value, $timezone) ?? throw new \RuntimeException(
            'Not a stored date: ' . $what . '.'
        );
    }

    /**
     * A datetime read back from a column, or from anywhere else that is
     * supposed to hold one — never the current moment as a consolation
     * prize.
     *
     * This is the replacement for `new DateTimeImmutable($row['…'])`,
     * which throws on a malformed value and, worse, returns *now* for an
     * empty string. A column that should hold a timestamp and does not is
     * a fact worth handling; it is not worth a 500, and it is certainly
     * not worth being silently reported as today.
     *
     * $timezone is handed to the constructor unchanged and therefore means
     * exactly what it means there: the zone a NAIVE value is read in, and
     * nothing at all when the value carries its own offset. Passing null
     * — the usual case — reads the value on the default clock, which is
     * what `new DateTimeImmutable($v)` did. It is here for the callers
     * that were already passing a second argument (an ICS export reading
     * an all-day date as UTC, a repository reading a naive column on the
     * application clock); a caller that only wants to CONVERT a moment
     * should keep using ->setTimezone() on the result.
     */
    public static function fromStorage(?string $value, ?\DateTimeZone $timezone = null): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        // A stored timestamp is an absolute point in time. "now",
        // "tomorrow" and "+1 day" parse, and would each be a different
        // answer on every read — so the value must open with a real
        // calendar date. Checking that date through iso() rather than a
        // bare shape regex also disposes of MySQL's zero date, which is
        // its way of writing "no value" and which PHP does not refuse:
        // `new DateTimeImmutable('0000-00-00 00:00:00')` returns the
        // 30th of November, year -1, and means nothing at all.
        if (self::iso(substr($value, 0, 10)) === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
