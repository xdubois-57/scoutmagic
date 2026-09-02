<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats;

/**
 * The 'YYYY-MM' key every counter is filed under, and the two French
 * labels a screen draws it as.
 *
 * A month here is a string rather than a date on purpose (the same choice
 * `support_monthly_aggregates` made): the value IS the month, not a day
 * inside it, and it sorts and compares as text exactly as it should.
 *
 * The month names are a twelve-entry lookup rather than `intl`, for the
 * reason Core\View\TwigFactory's `french_date` gives: no dependency this
 * small is worth adding.
 */
final class Month
{
    /** @var array<int, string> */
    private const NAMES = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    /** @var array<int, string> The three-letter form an axis label uses. */
    private const ABBREVIATIONS = [
        1 => 'Jan', 2 => 'Fév', 3 => 'Mar', 4 => 'Avr',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juil', 8 => 'Août',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Déc',
    ];

    public static function isValid(string $month): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    public static function current(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now'))->format('Y-m');
    }

    /** « août 2026 » — sentence case, for prose. */
    public static function label(string $month): string
    {
        [$year, $index] = self::parts($month);

        return self::NAMES[$index] . ' ' . $year;
    }

    /** « Août 2026 » — for a picker option or a heading. */
    public static function capitalisedLabel(string $month): string
    {
        return mb_strtoupper(mb_substr(self::label($month), 0, 1)) . mb_substr(self::label($month), 1);
    }

    /** « Août » — for the axis of a twelve-column chart on a phone. */
    public static function shortLabel(string $month): string
    {
        [, $index] = self::parts($month);

        return self::ABBREVIATIONS[$index];
    }

    /** The month $count months before $month, same 'YYYY-MM' shape. */
    public static function shift(string $month, int $count): string
    {
        [$year, $index] = self::parts($month);
        $absolute = $year * 12 + ($index - 1) + $count;

        return sprintf('%04d-%02d', intdiv($absolute, 12), $absolute % 12 + 1);
    }

    /**
     * The $count months ending at $month, oldest first.
     *
     * @return list<string>
     */
    public static function windowEndingAt(string $month, int $count): array
    {
        $months = [];
        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $months[] = self::shift($month, -$offset);
        }

        return $months;
    }

    /** @return array{0: int, 1: int} */
    private static function parts(string $month): array
    {
        if (!self::isValid($month)) {
            throw new \InvalidArgumentException("Not a 'YYYY-MM' month: {$month}");
        }

        $pieces = explode('-', $month);

        return [(int) $pieces[0], (int) $pieces[1]];
    }
}
