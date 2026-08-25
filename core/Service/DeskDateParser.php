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
 * `Core\Import\DeskCsvParser` stores the "Date de naissance" column
 * **verbatim**, and Desk exports it in more than one shape depending on the
 * export: `15/03/2012` in one, `2019-05-22` in another
 * (`tests/fixtures/desk_export_sample.csv` and `desk_export_comma.csv` show
 * both). Anything reading `member_years.birth_date_encrypted` therefore has
 * to accept several formats, and two independent copies of that list are
 * two chances for one of them to miss the shape a given unit's export uses
 * — which reads as "this member has no birth date" rather than as a bug.
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

    /** The same date as `Y-m-d`, which is what anything comparing dates should hold. */
    public static function toIso(?string $raw): ?string
    {
        return self::parse($raw)?->format('Y-m-d');
    }
}
