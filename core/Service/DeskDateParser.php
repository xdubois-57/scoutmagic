<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Service;

/**
 * The one reader for a date that came out of Desk.
 *
 * Desk exports a date in more than one shape depending on the export:
 * `15/03/2012` in one, `2019-05-22` in another
 * (`tests/fixtures/desk_export_sample.csv` and `desk_export_comma.csv` show
 * both). Two independent copies of that list are two chances for one of
 * them to miss the shape a given unit's export uses — which reads as "this
 * member has no birth date" rather than as a bug.
 *
 * Since issue #854 the import refuses a file whose dates this class cannot
 * read (`Core\Import\DeskImportValidator`) and stores what it can read as
 * `Y-m-d` (`Core\Import\DeskImportService`). Rows imported before that
 * still hold the verbatim Desk text, which is why the readers of
 * `member_years.birth_date_encrypted` that go through here keep doing so.
 *
 * A shared primitive for the same reason as
 * `Core\Service\TextNormalizerService::fold()` (ARCHITECTURE.md §8.0): the
 * same rule written twice drifts, and this one drifts silently.
 */
final class DeskDateParser
{
    /** The shapes a Desk export has been observed to use, in the order they are tried. */
    private const FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y'];

    public static function parse(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        // A datetime column that came through as "1998-03-15 00:00:00" is
        // the same date; drop the time part before matching.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $raw, $match) === 1) {
            $raw = $match[1];
        }

        foreach (self::FORMATS as $format) {
            // '!' zeroes the time fields; the round-trip check rejects
            // PHP's overflow tolerance, which would otherwise turn an
            // impossible 31/02 into 3 March rather than into "unknown".
            $parsed = DateInput::parse('!' . $format, $raw);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * The earliest birth date an import accepts. Nobody on a scout unit's
     * roster was born before it, and a date before it is a typing or
     * conversion accident — `01/01/0012` from a spreadsheet that dropped
     * two digits reads as a valid date otherwise.
     */
    public const EARLIEST_BIRTH_DATE = '1900-01-01';

    /**
     * Whether a date READ by parse() can be somebody's birth date: not
     * after $today, not before EARLIEST_BIRTH_DATE. parse() alone accepts
     * `2099-01-01`, which is a perfectly formed date and nobody's birthday.
     */
    public static function isPlausibleBirthDate(\DateTimeImmutable $date, \DateTimeImmutable $today): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= self::EARLIEST_BIRTH_DATE && $day <= $today->format('Y-m-d');
    }

    /** The same date as `Y-m-d`, which is what anything comparing dates should hold. */
    public static function toIso(?string $raw): ?string
    {
        return self::parse($raw)?->format('Y-m-d');
    }
}
