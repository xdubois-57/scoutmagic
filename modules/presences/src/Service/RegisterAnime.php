<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

/**
 * One animé's year, reduced to what « qui décroche ? » needs.
 *
 * $consideredEvents counts the evenings somebody actually pointed, never
 * every evening on the calendar: an animé who joined in January must not
 * read as having missed September.
 */
final class RegisterAnime
{
    public function __construct(
        public readonly int $memberId,
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?string $totem,
        public readonly int $present,
        public readonly int $consideredEvents,
        /** Present over pointed evenings, 0–100; 0 when none was pointed. */
        public readonly int $rate
    ) {
    }

    /**
     * The colour band a rate falls in — the only place the four
     * thresholds live, so the register, the search panel and the animé's
     * own page cannot disagree about what « bas » means.
     *
     * Bootstrap semantic names, never hex: both themes follow
     * (design.md §7.8).
     */
    public static function toneForRate(int $rate): string
    {
        if ($rate < 40) {
            return 'danger';
        }
        if ($rate < 60) {
            return 'warning';
        }
        if ($rate < 80) {
            return 'info';
        }

        return 'success';
    }

    public function tone(): string
    {
        return self::toneForRate($this->rate);
    }
}
