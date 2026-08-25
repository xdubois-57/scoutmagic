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
     */
    public const ISO_DATE = 'Y-m-d';

    /**
     * `Y-m-d` with a `datetime-local` input's `T` separator, which is what
     * a browser posts for `<input type="datetime-local">`.
     */
    public const ISO_DATETIME_LOCAL = 'Y-m-d\TH:i';

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
     * A datetime read back from a column, or from anywhere else that is
     * supposed to hold one — never the current moment as a consolation
     * prize.
     *
     * This is the replacement for `new DateTimeImmutable($row['…'])`,
     * which throws on a malformed value and, worse, returns *now* for an
     * empty string. A column that should hold a timestamp and does not is
     * a fact worth handling; it is not worth a 500, and it is certainly
     * not worth being silently reported as today.
     */
    public static function fromStorage(?string $value): ?\DateTimeImmutable
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
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
