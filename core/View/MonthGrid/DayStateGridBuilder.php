<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View\MonthGrid;

use Core\Service\DateInput;

/**
 * Builds a month grid where every day cell carries exactly one
 * Core\View\MonthGrid\DayState — 7 columns (Monday first), one row per week,
 * spanning the Monday on/before the 1st to the Sunday on/after the last day
 * of the month, so it always renders as full weeks (4–6 rows).
 *
 * **Why this sits beside MonthGridBuilder rather than inside it.**
 * MonthGridBuilder answers "what is happening on these days", and its whole
 * algorithm is interval row-packing: several events per day, laid out as
 * continuous multi-day bars, capped and overflowed. This builder answers "can
 * I take these days", where there is exactly one state per cell, nothing to
 * pack, nothing to overflow, and the *reason* for a state is deliberately not
 * expressible (see DayState::STATE_OCCUPIED). Merging the two would mean one
 * class with two disjoint code paths sharing only the calendar arithmetic —
 * and, worse, would put the packing algorithm one refactor away from the
 * confidentiality-sensitive path. The calendar module keeps using
 * MonthGridBuilder, untouched, with zero regression risk from anything here.
 *
 * Generic: no notion of a rental, a booking, or any other domain object.
 */
class DayStateGridBuilder
{
    /**
     * @param array<string, DayState> $states Keyed by 'Y-m-d'. Days absent from this map get $default — so a caller only describes the days it knows something about, and cannot accidentally leave a cell with no accessible label.
     * @param DayState $default State for every day the caller did not map, including the leading/trailing padding days of adjacent months. Required rather than defaulted: the neutral label is user-facing French text, which is the caller's to write, not core's to guess.
     * @param \DateTimeImmutable|null $today Injectable for tests; defaults to the real current date.
     * @return array<int, array{days: array<int, array{date: string, day_number: int, in_month: bool, is_today: bool, state: string, accessible_label: string, color: ?string, selectable: bool, data: array<string, string>}>}>
     */
    public function build(int $year, int $month, array $states, DayState $default, ?\DateTimeImmutable $today = null): array
    {
        $firstOfMonth = DateInput::firstOfMonth($year, $month);
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $isoWeekdayOfFirst = (int) $firstOfMonth->format('N'); // 1 (Mon) .. 7 (Sun)
        $gridStart = $firstOfMonth->modify('-' . ($isoWeekdayOfFirst - 1) . ' days');

        $isoWeekdayOfLast = (int) $lastOfMonth->format('N');
        $gridEnd = $lastOfMonth->modify('+' . (7 - $isoWeekdayOfLast) . ' days');

        $todayStr = ($today ?? new \DateTimeImmutable())->format('Y-m-d');
        $monthPrefix = sprintf('%04d-%02d', $year, $month);

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $cursor->modify("+{$i} days");
                $dateStr = $date->format('Y-m-d');
                $dayState = $states[$dateStr] ?? $default;

                $days[] = [
                    'date' => $dateStr,
                    'day_number' => (int) $date->format('j'),
                    'in_month' => str_starts_with($dateStr, $monthPrefix),
                    'is_today' => $dateStr === $todayStr,
                    'state' => $dayState->state,
                    'accessible_label' => $dayState->accessibleLabel,
                    'color' => $dayState->color,
                    'selectable' => $dayState->selectable,
                    'data' => $dayState->data,
                ];
            }

            $weeks[] = ['days' => $days];
            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }
}
