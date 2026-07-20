<?php

declare(strict_types=1);

namespace Tests\Core\View\MonthGrid;

use Core\View\MonthGrid\GridEvent;
use Core\View\MonthGrid\MonthGridBuilder;
use PHPUnit\Framework\TestCase;

/**
 * March 2026 is used throughout as a fixed reference month: 2026-03-01 is a
 * Sunday, so the (Monday-first) grid spans 2026-02-23 .. 2026-04-05 —
 * hand-verified via `date()`, not re-derived per assertion.
 */
class MonthGridBuilderTest extends TestCase
{
    private MonthGridBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new MonthGridBuilder();
    }

    private function event(string $id, string $startDate, ?string $endDate = null, string $label = 'Event'): GridEvent
    {
        return new GridEvent($id, $startDate, $endDate, $label, '#123456');
    }

    private function findBarForEvent(array $weeks, string $eventId): ?array
    {
        foreach ($weeks as $week) {
            foreach ($week['bars'] as $bar) {
                if ($bar['event']->id === $eventId) {
                    return $bar;
                }
            }
        }
        return null;
    }

    public function testBuildReturnsFullWeeksSpanningTheMonth(): void
    {
        $weeks = $this->builder->build(2026, 3, []);

        $this->assertSame('2026-02-23', $weeks[0]['days'][0]['date']);
        $this->assertSame('2026-04-05', $weeks[array_key_last($weeks)]['days'][6]['date']);
        foreach ($weeks as $week) {
            $this->assertCount(7, $week['days']);
        }
    }

    public function testBuildMarksInMonthAndPaddingDays(): void
    {
        $weeks = $this->builder->build(2026, 3, []);

        $this->assertFalse($weeks[0]['days'][0]['in_month']); // 2026-02-23
        $this->assertTrue($weeks[0]['days'][6]['in_month']); // 2026-03-01
        $this->assertFalse($weeks[array_key_last($weeks)]['days'][6]['in_month']); // 2026-04-05
    }

    public function testBuildMarksToday(): void
    {
        $today = new \DateTimeImmutable();
        $weeks = $this->builder->build((int) $today->format('Y'), (int) $today->format('n'), []);

        $found = false;
        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                if ($day['date'] === $today->format('Y-m-d')) {
                    $this->assertTrue($day['is_today']);
                    $found = true;
                } else {
                    $this->assertFalse($day['is_today']);
                }
            }
        }
        $this->assertTrue($found);
    }

    public function testBuildPlacesSingleDayEventOnCorrectDayWithSpanOne(): void
    {
        $weeks = $this->builder->build(2026, 3, [$this->event('e1', '2026-03-11')]);

        $bar = $this->findBarForEvent($weeks, 'e1');
        $this->assertNotNull($bar);
        $this->assertSame(3, $bar['col_start']); // Wed = column 3
        $this->assertSame(1, $bar['col_span']);
        $this->assertSame(0, $bar['row']);
        $this->assertFalse($bar['continues_before']);
        $this->assertFalse($bar['continues_after']);
    }

    public function testBuildSpansMultiDayEventAcrossColumns(): void
    {
        $weeks = $this->builder->build(2026, 3, [$this->event('e1', '2026-03-11', '2026-03-13')]);

        $bar = $this->findBarForEvent($weeks, 'e1');
        $this->assertNotNull($bar);
        $this->assertSame(3, $bar['col_start']);
        $this->assertSame(3, $bar['col_span']); // Wed-Thu-Fri
    }

    public function testBuildMarksContinuesAcrossWeekBoundary(): void
    {
        // 2026-03-13 (Fri) to 2026-03-17 (Tue) crosses the week boundary.
        $weeks = $this->builder->build(2026, 3, [$this->event('e1', '2026-03-13', '2026-03-17')]);

        $bars = [];
        foreach ($weeks as $week) {
            foreach ($week['bars'] as $bar) {
                if ($bar['event']->id === 'e1') {
                    $bars[] = $bar;
                }
            }
        }

        $this->assertCount(2, $bars);
        $this->assertFalse($bars[0]['continues_before']);
        $this->assertTrue($bars[0]['continues_after']);
        $this->assertTrue($bars[1]['continues_before']);
        $this->assertFalse($bars[1]['continues_after']);
    }

    public function testBuildStacksOverlappingEventsInDifferentRows(): void
    {
        $weeks = $this->builder->build(2026, 3, [
            $this->event('e1', '2026-03-11', '2026-03-13'),
            $this->event('e2', '2026-03-12'),
        ]);

        $bar1 = $this->findBarForEvent($weeks, 'e1');
        $bar2 = $this->findBarForEvent($weeks, 'e2');
        $this->assertNotSame($bar1['row'], $bar2['row']);
    }

    public function testBuildPacksNonOverlappingEventsInTheSameRow(): void
    {
        $weeks = $this->builder->build(2026, 3, [
            $this->event('e1', '2026-03-09'), // Mon
            $this->event('e2', '2026-03-11'), // Wed, doesn't overlap e1
        ]);

        $bar1 = $this->findBarForEvent($weeks, 'e1');
        $bar2 = $this->findBarForEvent($weeks, 'e2');
        $this->assertSame(0, $bar1['row']);
        $this->assertSame(0, $bar2['row']);
    }

    public function testBuildReturnsNoBarsWhenNoEvents(): void
    {
        $weeks = $this->builder->build(2026, 3, []);

        foreach ($weeks as $week) {
            $this->assertSame([], $week['bars']);
            $this->assertSame(0, $week['row_count']);
        }
    }

    public function testBuildCapsVisibleRowsAtDefaultMaxAndTracksOverflow(): void
    {
        // Four same-day events collide, forcing rows 0,1,2,3 — default cap
        // is 3, so the 4th (stable-sort-preserved order) is hidden.
        $weeks = $this->builder->build(2026, 3, [
            $this->event('e1', '2026-03-10', label: 'E1'),
            $this->event('e2', '2026-03-10', label: 'E2'),
            $this->event('e3', '2026-03-10', label: 'E3'),
            $this->event('e4', '2026-03-10', label: 'E4'),
        ]);

        $this->assertNotNull($this->findBarForEvent($weeks, 'e1'));
        $this->assertNotNull($this->findBarForEvent($weeks, 'e2'));
        $this->assertNotNull($this->findBarForEvent($weeks, 'e3'));
        $this->assertNull($this->findBarForEvent($weeks, 'e4'));

        $day = $this->dayFor($weeks, '2026-03-10');
        $this->assertSame(1, $day['overflow_count']);
        $this->assertSame(['E4'], $day['overflow_labels']);
    }

    public function testBuildRowCountReflectsOnlyVisibleCappedRows(): void
    {
        $weeks = $this->builder->build(2026, 3, [
            $this->event('e1', '2026-03-10'),
            $this->event('e2', '2026-03-10'),
            $this->event('e3', '2026-03-10'),
            $this->event('e4', '2026-03-10'),
        ], maxVisibleRows: 3);

        $week = $this->weekFor($weeks, '2026-03-10');
        $this->assertSame(3, $week['row_count']);
    }

    public function testBuildFoldsHiddenMultiDayEventIntoEveryDayItTouches(): void
    {
        // Four events with identical start/end (03-16..03-17) are a pure
        // insertion-order tie under the sort's tie-break rule, so the 4th
        // (e4) is deterministically the one pushed to row 3 (hidden).
        $weeks = $this->builder->build(2026, 3, [
            $this->event('e1', '2026-03-16', '2026-03-17', 'E1'),
            $this->event('e2', '2026-03-16', '2026-03-17', 'E2'),
            $this->event('e3', '2026-03-16', '2026-03-17', 'E3'),
            $this->event('e4', '2026-03-16', '2026-03-17', 'E4'),
        ]);

        $this->assertNull($this->findBarForEvent($weeks, 'e4'));

        $day16 = $this->dayFor($weeks, '2026-03-16');
        $day17 = $this->dayFor($weeks, '2026-03-17');
        $day18 = $this->dayFor($weeks, '2026-03-18');

        $this->assertSame(1, $day16['overflow_count']);
        $this->assertSame(['E4'], $day16['overflow_labels']);
        $this->assertSame(1, $day17['overflow_count']);
        $this->assertSame(['E4'], $day17['overflow_labels']);
        $this->assertSame(0, $day18['overflow_count']);
    }

    public function testBuildRespectsCustomMaxVisibleRows(): void
    {
        $weeks = $this->builder->build(2026, 3, [
            $this->event('e1', '2026-03-10', label: 'E1'),
            $this->event('e2', '2026-03-10', label: 'E2'),
        ], maxVisibleRows: 1);

        $this->assertNotNull($this->findBarForEvent($weeks, 'e1'));
        $this->assertNull($this->findBarForEvent($weeks, 'e2'));

        $day = $this->dayFor($weeks, '2026-03-10');
        $this->assertSame(1, $day['overflow_count']);
    }

    /**
     * @param array<int, array{days: array<int, array<string, mixed>>, bars: array<int, array<string, mixed>>, row_count: int}> $weeks
     * @return array<string, mixed>
     */
    private function dayFor(array $weeks, string $date): array
    {
        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                if ($day['date'] === $date) {
                    return $day;
                }
            }
        }
        $this->fail("Day {$date} not found in grid.");
    }

    /**
     * @param array<int, array{days: array<int, array<string, mixed>>, bars: array<int, array<string, mixed>>, row_count: int}> $weeks
     * @return array{days: array<int, array<string, mixed>>, bars: array<int, array<string, mixed>>, row_count: int}
     */
    private function weekFor(array $weeks, string $dateInWeek): array
    {
        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                if ($day['date'] === $dateInWeek) {
                    return $week;
                }
            }
        }
        $this->fail("Week containing {$dateInWeek} not found in grid.");
    }
}
