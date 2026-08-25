<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * Belgian money, as a federation invoice prints it: a comma for the
 * decimals, a **non-breaking** space between the thousands, a leading
 * minus on a reduction — `1 215,00`, `-100,00`.
 *
 * This is the single most frequent way a reading of a document like this
 * one silently produces a total of zero, which is why it is its own class
 * with its own tests rather than a regex inside the parser.
 *
 * Only NBSP-class characters are treated as a thousands separator, never
 * an ordinary space: an ordinary space is what separates the COLUMNS of a
 * line, and collapsing it would turn "3 117,00" (a quantity and an amount)
 * into "3117,00" (one number). A dot between two groups of three digits is
 * accepted too, since some exports use it.
 *
 * Everything is cents, integers, end to end: an invoice that has to fall
 * on the centime cannot be recomputed in floating point.
 */
final class BelgianNumber
{
    /** U+00A0, U+202F, U+2007, U+2009 — every space a typesetter uses inside a number. */
    public const NBSP_CLASS = "\u{00A0}\u{202F}\u{2007}\u{2009}";

    /** The shape of one amount, once {@see collapseGroupSeparators()} has run. */
    public const PATTERN = '-?\d+(?:,\d{1,2})?';

    /**
     * Removes the separators that live INSIDE a number, leaving the spaces
     * that separate columns alone.
     */
    public static function collapseGroupSeparators(string $line): string
    {
        $line = (string) preg_replace('/(?<=\d)[' . self::NBSP_CLASS . '](?=\d{3}\b)/u', '', $line);

        return (string) preg_replace('/(?<=\d)\.(?=\d{3}\b)/u', '', $line);
    }

    /** @return int|null cents, or null when this is not a number of this shape */
    public static function toCents(string $raw): ?int
    {
        $raw = trim(self::collapseGroupSeparators($raw));
        if (preg_match('/^' . self::PATTERN . '$/u', $raw) !== 1) {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $digits = ltrim($raw, '-');
        [$units, $decimals] = array_pad(explode(',', $digits, 2), 2, '0');
        $cents = ((int) $units) * 100 + (int) str_pad(substr((string) $decimals, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** The way this codebase writes an amount back out, for a message a human reads. */
    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', "\u{00A0}") . "\u{00A0}€";
    }
}
