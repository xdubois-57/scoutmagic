<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

/**
 * Which month a calendar is showing, and where its arrows may go.
 *
 * The public calendar and the private one want the same paging with one
 * difference that matters: **a visitor never goes into the past** (§6.7),
 * while a manager must, because half their work is about stays that already
 * happened. One class with a floor rather than two near-identical copies —
 * the clamping is the part worth having in exactly one place.
 *
 * Clamping happens here rather than only in the template: the month is a
 * query parameter, and a hidden arrow is not a boundary
 * (ARCHITECTURE.md §12).
 */
final class MonthWindow
{
    /** French month names, spelled out rather than left to the server locale. */
    private const MONTHS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
        5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
        9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    private function __construct(
        public readonly int $year,
        public readonly int $month,
        private \DateTimeImmutable $earliest,
        private \DateTimeImmutable $latest
    ) {
    }

    /**
     * @param string $requested `Y-m`, or anything at all — a value that does
     *   not parse falls back to the current month rather than erroring, since
     *   it reaches us from a URL a visitor may have typed.
     * @param int $monthsBack How far back the arrows may go. Zero for the
     *   public calendar, which never shows the past.
     */
    public static function resolve(
        string $requested,
        \DateTimeImmutable $today,
        int $monthsBack,
        int $monthsAhead
    ): self {
        $current = $today->modify('first day of this month')->setTime(0, 0);
        $earliest = $monthsBack > 0 ? $current->modify('-' . $monthsBack . ' months') : $current;
        $latest = $current->modify('+' . max(0, $monthsAhead) . ' months');

        $candidate = $current;
        if (preg_match('/^(\d{4})-(\d{2})$/', $requested, $matches) === 1) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $matches[1] . '-' . $matches[2] . '-01');
            if ($parsed !== false) {
                $candidate = $parsed->setTime(0, 0);
            }
        }

        if ($candidate < $earliest) {
            $candidate = $earliest;
        } elseif ($candidate > $latest) {
            $candidate = $latest;
        }

        return new self((int) $candidate->format('Y'), (int) $candidate->format('n'), $earliest, $latest);
    }

    /** `Y-m` for the previous month, or null when the arrow must be disabled. */
    public function previous(): ?string
    {
        $shown = $this->firstDay();

        return $shown <= $this->earliest ? null : $shown->modify('-1 month')->format('Y-m');
    }

    public function next(): ?string
    {
        $shown = $this->firstDay();

        return $shown >= $this->latest ? null : $shown->modify('+1 month')->format('Y-m');
    }

    /** `mars 2027`. */
    public function label(): string
    {
        return (self::MONTHS[$this->month] ?? '') . ' ' . $this->year;
    }

    public function firstDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month));
    }

    public function lastDay(): \DateTimeImmutable
    {
        return $this->firstDay()->modify('last day of this month');
    }
}
