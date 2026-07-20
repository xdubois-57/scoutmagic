<?php

declare(strict_types=1);

namespace Core\View\MonthGrid;

/**
 * Builds an iCal-style month grid — one entry per week (rows), each with 7
 * days (columns, Monday first) plus a list of event "bars" laid out so
 * multi-day events render as a single continuous bar spanning the days they
 * cover, never a chip repeated per day. The grid spans from the Monday
 * on/before the 1st to the Sunday on/after the last day of the month, so it
 * always renders as full weeks (4–6 rows depending on the month).
 *
 * Visible bar rows per week are capped at $maxVisibleRows so a day's cell
 * height never grows with its event count — any event beyond the cap is
 * omitted from `bars` and instead counted into the day(s) it touches via
 * `overflow_count`/`overflow_labels`, for a caller to render as a "+N"
 * indicator (partials/month_grid.html.twig does this).
 *
 * Generic and calendar-module-agnostic (Core\View\MonthGrid\GridEvent, not
 * any calendar-specific entity) — reusable by any module with date-ranged
 * things to show in a month view.
 */
class MonthGridBuilder
{
    private const DEFAULT_MAX_VISIBLE_ROWS = 3;

    /**
     * @param GridEvent[] $events
     * @return array<int, array{
     *     days: array<int, array{date: string, day_number: int, in_month: bool, is_today: bool, overflow_count: int, overflow_labels: array<int, string>}>,
     *     bars: array<int, array{event: GridEvent, col_start: int, col_span: int, row: int, continues_before: bool, continues_after: bool}>,
     *     row_count: int
     * }>
     */
    public function build(int $year, int $month, array $events, int $maxVisibleRows = self::DEFAULT_MAX_VISIBLE_ROWS): array
    {
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $isoWeekdayOfFirst = (int) $firstOfMonth->format('N'); // 1 (Mon) .. 7 (Sun)
        $gridStart = $firstOfMonth->modify('-' . ($isoWeekdayOfFirst - 1) . ' days');

        $isoWeekdayOfLast = (int) $lastOfMonth->format('N');
        $gridEnd = $lastOfMonth->modify('+' . (7 - $isoWeekdayOfLast) . ' days');

        $gridStartStr = $gridStart->format('Y-m-d');
        $gridEndStr = $gridEnd->format('Y-m-d');
        $eventsInGrid = array_values(array_filter(
            $events,
            fn(GridEvent $e) => $e->startDate <= $gridEndStr && ($e->endDate ?? $e->startDate) >= $gridStartStr
        ));

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $monthPrefix = sprintf('%04d-%02d', $year, $month);

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $cursor->modify("+{$i} days");
                $dateStr = $date->format('Y-m-d');
                $days[] = [
                    'date' => $dateStr,
                    'day_number' => (int) $date->format('j'),
                    'in_month' => str_starts_with($dateStr, $monthPrefix),
                    'is_today' => $dateStr === $today,
                    'overflow_count' => 0,
                    'overflow_labels' => [],
                ];
            }

            $weekStart = $days[0]['date'];
            $weekEnd = $days[6]['date'];
            $weekEvents = array_values(array_filter(
                $eventsInGrid,
                fn(GridEvent $e) => $e->startDate <= $weekEnd && ($e->endDate ?? $e->startDate) >= $weekStart
            ));

            [$bars, $days] = $this->layoutWeekBars($weekEvents, $days, $maxVisibleRows);

            $weeks[] = [
                'days' => $days,
                'bars' => $bars,
                'row_count' => count($bars) > 0 ? max(array_column($bars, 'row')) + 1 : 0,
            ];

            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * Greedy interval row-packing: sort events touching the week by start
     * date (earliest first, longer duration first as a tie-break so
     * multi-day events claim a stable row before shorter ones), then place
     * each into the first row whose already-assigned column ranges don't
     * collide with it. Rows at or beyond $maxVisibleRows are hidden from
     * the returned bars and folded into the touched days' overflow counts
     * instead.
     *
     * @param GridEvent[] $weekEvents
     * @param array<int, array{date: string, day_number: int, in_month: bool, is_today: bool, overflow_count: int, overflow_labels: array<int, string>}> $days 7 entries, Monday first
     * @return array{0: array<int, array{event: GridEvent, col_start: int, col_span: int, row: int, continues_before: bool, continues_after: bool}>, 1: array<int, array{date: string, day_number: int, in_month: bool, is_today: bool, overflow_count: int, overflow_labels: array<int, string>}>}
     */
    private function layoutWeekBars(array $weekEvents, array $days, int $maxVisibleRows): array
    {
        usort($weekEvents, function (GridEvent $a, GridEvent $b) {
            if ($a->startDate !== $b->startDate) {
                return $a->startDate <=> $b->startDate;
            }
            // Same start date: longer duration first == later end date
            // first (start is fixed, so comparing end dates directly is
            // equivalent to comparing durations, no date arithmetic needed).
            $endA = $a->endDate ?? $a->startDate;
            $endB = $b->endDate ?? $b->startDate;
            return $endB <=> $endA;
        });

        $weekStart = $days[0]['date'];
        $weekEnd = $days[6]['date'];
        $dateToColumn = array_flip(array_column($days, 'date')); // date => 0..6

        /** @var array<int, array<int, array{0: int, 1: int}>> $rowRanges row => list of [colStart, colEnd] (1-indexed) */
        $rowRanges = [];
        $bars = [];

        foreach ($weekEvents as $event) {
            $eventEnd = $event->endDate ?? $event->startDate;
            $clampedStart = max($event->startDate, $weekStart);
            $clampedEnd = min($eventEnd, $weekEnd);

            $colStart = $dateToColumn[$clampedStart] + 1;
            $colEndIdx = $dateToColumn[$clampedEnd] + 1;

            $row = 0;
            while (true) {
                $collision = false;
                foreach ($rowRanges[$row] ?? [] as $range) {
                    if ($colStart <= $range[1] && $colEndIdx >= $range[0]) {
                        $collision = true;
                        break;
                    }
                }
                if (!$collision) {
                    break;
                }
                $row++;
            }
            $rowRanges[$row][] = [$colStart, $colEndIdx];

            if ($row < $maxVisibleRows) {
                $bars[] = [
                    'event' => $event,
                    'col_start' => $colStart,
                    'col_span' => $colEndIdx - $colStart + 1,
                    'row' => $row,
                    'continues_before' => $event->startDate < $weekStart,
                    'continues_after' => $eventEnd > $weekEnd,
                ];
                continue;
            }

            // Hidden by the cap: fold into every day this event touches.
            for ($col = $colStart; $col <= $colEndIdx; $col++) {
                $dayIndex = $col - 1;
                $days[$dayIndex]['overflow_count']++;
                $days[$dayIndex]['overflow_labels'][] = $event->label;
            }
        }

        return [$bars, $days];
    }
}
