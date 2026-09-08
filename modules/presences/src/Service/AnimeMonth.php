<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

/**
 * One month of an animé's year.
 *
 * A month in which the section held no POINTED evening is absent
 * entirely rather than reported at 0 %: a bar at zero for a month of
 * holidays is the same lie as an unpointed Saturday drawn as an empty
 * meeting. What this chart exists to show is a slope over three months —
 * that is a drop-out, where a single absence is not — and a fabricated
 * zero would produce one that is not there.
 */
final class AnimeMonth
{
    public function __construct(
        /** `YYYY-MM`, the key the months are ordered by. */
        public readonly string $month,
        /** « Sep », « Oct » — the short French label the chart draws. */
        public readonly string $label,
        public readonly int $present,
        public readonly int $pointedEvents,
        public readonly int $rate
    ) {
    }

    private const SHORT_MONTHS = [
        1 => 'Jan', 2 => 'Fév', 3 => 'Mar', 4 => 'Avr', 5 => 'Mai', 6 => 'Juin',
        7 => 'Juil', 8 => 'Août', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Déc',
    ];

    public static function labelFor(string $month): string
    {
        $parts = explode('-', $month);

        return self::SHORT_MONTHS[(int) ($parts[1] ?? 0)] ?? $month;
    }
}
