<?php

declare(strict_types=1);

namespace Tests\Core\View\MonthGrid;

use Core\View\MonthGrid\DayState;
use Core\View\MonthGrid\DayStateGridBuilder;
use PHPUnit\Framework\TestCase;

/**
 * March 2026 is the fixed reference month throughout, matching
 * MonthGridBuilderTest: 2026-03-01 is a Sunday, so the (Monday-first) grid
 * spans 2026-02-23 .. 2026-04-05 — hand-verified via `date()`, not
 * re-derived per assertion. Sharing the reference month with the sibling
 * builder's test is deliberate: the two grids must agree on their calendar
 * arithmetic, and a divergence shows up as one suite failing on dates the
 * other still passes.
 */
class DayStateGridBuilderTest extends TestCase
{
    private DayStateGridBuilder $builder;
    private DayState $free;

    protected function setUp(): void
    {
        $this->builder = new DayStateGridBuilder();
        $this->free = new DayState(DayState::STATE_FREE, 'Libre', null, true);
    }

    private function findDay(array $weeks, string $date): ?array
    {
        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                if ($day['date'] === $date) {
                    return $day;
                }
            }
        }

        return null;
    }

    public function testBuildReturnsFullWeeksSpanningTheMonth(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free);

        $this->assertSame('2026-02-23', $weeks[0]['days'][0]['date']);
        $this->assertSame('2026-04-05', $weeks[count($weeks) - 1]['days'][6]['date']);

        foreach ($weeks as $week) {
            $this->assertCount(7, $week['days']);
        }
    }

    public function testGridStartsOnMondayAndEndsOnSunday(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free);

        foreach ($weeks as $week) {
            $this->assertSame('1', (new \DateTimeImmutable($week['days'][0]['date']))->format('N'));
            $this->assertSame('7', (new \DateTimeImmutable($week['days'][6]['date']))->format('N'));
        }
    }

    public function testPaddingDaysOfAdjacentMonthsAreFlagged(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free);

        $this->assertFalse($this->findDay($weeks, '2026-02-23')['in_month']);
        $this->assertTrue($this->findDay($weeks, '2026-03-01')['in_month']);
        $this->assertTrue($this->findDay($weeks, '2026-03-31')['in_month']);
        $this->assertFalse($this->findDay($weeks, '2026-04-05')['in_month']);
    }

    public function testUnmappedDaysFallBackToTheDefaultState(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free);

        $day = $this->findDay($weeks, '2026-03-14');
        $this->assertSame(DayState::STATE_FREE, $day['state']);
        $this->assertSame('Libre', $day['accessible_label']);
        $this->assertTrue($day['selectable']);
    }

    public function testMappedDayOverridesTheDefault(): void
    {
        $weeks = $this->builder->build(2026, 3, [
            '2026-03-14' => new DayState(DayState::STATE_OCCUPIED, 'Occupé', '#aa0000'),
        ], $this->free);

        $day = $this->findDay($weeks, '2026-03-14');
        $this->assertSame(DayState::STATE_OCCUPIED, $day['state']);
        $this->assertSame('Occupé', $day['accessible_label']);
        $this->assertSame('#aa0000', $day['color']);
        $this->assertFalse($day['selectable']);

        // Its neighbours are untouched.
        $this->assertSame(DayState::STATE_FREE, $this->findDay($weeks, '2026-03-13')['state']);
        $this->assertSame(DayState::STATE_FREE, $this->findDay($weeks, '2026-03-15')['state']);
    }

    public function testPaddingDaysCanCarryTheirOwnMappedState(): void
    {
        // The grid's leading/trailing days belong to adjacent months but are
        // still real dates a caller may know something about — a booking
        // running across the month boundary has to render on them.
        $weeks = $this->builder->build(2026, 3, [
            '2026-02-25' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
        ], $this->free);

        $day = $this->findDay($weeks, '2026-02-25');
        $this->assertFalse($day['in_month']);
        $this->assertSame(DayState::STATE_OCCUPIED, $day['state']);
    }

    public function testDepartingStateIsCarriedThroughAsItsOwnState(): void
    {
        // §6.8's "départ le matin": the last day of a half-open range must be
        // distinguishable from both free and occupied, because a new range may
        // start on it. Conflating it with either is the bug this state exists
        // to prevent.
        $weeks = $this->builder->build(2026, 3, [
            '2026-03-17' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
            '2026-03-18' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
            '2026-03-19' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
            '2026-03-20' => new DayState(DayState::STATE_DEPARTING, 'Départ le matin, libre ensuite', null, true),
        ], $this->free);

        $this->assertSame(DayState::STATE_OCCUPIED, $this->findDay($weeks, '2026-03-19')['state']);

        $departure = $this->findDay($weeks, '2026-03-20');
        $this->assertSame(DayState::STATE_DEPARTING, $departure['state']);
        $this->assertNotSame(DayState::STATE_OCCUPIED, $departure['state']);
        $this->assertNotSame(DayState::STATE_FREE, $departure['state']);
        $this->assertTrue($departure['selectable'], 'A departure day stays pickable as a new arrival.');
    }

    public function testUnselectableIsDistinctFromOccupied(): void
    {
        // §6.7: a day inside the minimum-notice window is NOT "occupied".
        // Telling a visitor a free day is taken makes them give up on it.
        $weeks = $this->builder->build(2026, 3, [
            '2026-03-02' => new DayState(DayState::STATE_UNSELECTABLE, 'Trop tôt pour réserver'),
            '2026-03-10' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
        ], $this->free);

        $tooSoon = $this->findDay($weeks, '2026-03-02');
        $occupied = $this->findDay($weeks, '2026-03-10');

        $this->assertNotSame($occupied['state'], $tooSoon['state']);
        $this->assertNotSame($occupied['accessible_label'], $tooSoon['accessible_label']);
        $this->assertFalse($tooSoon['selectable']);
        $this->assertFalse($occupied['selectable']);
    }

    public function testOccupiedStateCarriesNoReason(): void
    {
        // Every private reason — booked, held, manually blocked — maps onto
        // the same state and the same label, so a public visitor cannot tell
        // them apart. This indistinguishability is the confidentiality
        // guarantee of §6.7/§6.14, not an approximation of one.
        $sameLabel = 'Occupé';
        $weeks = $this->builder->build(2026, 3, [
            '2026-03-05' => new DayState(DayState::STATE_OCCUPIED, $sameLabel),
            '2026-03-06' => new DayState(DayState::STATE_OCCUPIED, $sameLabel),
            '2026-03-07' => new DayState(DayState::STATE_OCCUPIED, $sameLabel),
        ], $this->free);

        $rendered = array_map(
            fn(string $date) => $this->findDay($weeks, $date),
            ['2026-03-05', '2026-03-06', '2026-03-07']
        );

        foreach ($rendered as $day) {
            $this->assertSame($rendered[0]['state'], $day['state']);
            $this->assertSame($rendered[0]['accessible_label'], $day['accessible_label']);
            $this->assertSame($rendered[0]['color'], $day['color']);
            $this->assertSame($rendered[0]['data'], $day['data']);
        }
    }

    public function testPartialStateCarriesRemainingQuantityAsOpaqueData(): void
    {
        $weeks = $this->builder->build(2026, 3, [
            '2026-03-14' => new DayState(
                DayState::STATE_PARTIAL,
                '3 disponibles sur 8',
                null,
                true,
                ['remaining' => '3']
            ),
        ], $this->free);

        $day = $this->findDay($weeks, '2026-03-14');
        $this->assertSame(DayState::STATE_PARTIAL, $day['state']);
        $this->assertSame(['remaining' => '3'], $day['data']);
    }

    public function testTodayIsFlaggedAndInjectable(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free, new \DateTimeImmutable('2026-03-14'));

        $this->assertTrue($this->findDay($weeks, '2026-03-14')['is_today']);
        $this->assertFalse($this->findDay($weeks, '2026-03-13')['is_today']);
    }

    public function testNoDayIsFlaggedTodayWhenTodayIsOutsideTheGrid(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free, new \DateTimeImmutable('2025-01-01'));

        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                $this->assertFalse($day['is_today']);
            }
        }
    }

    public function testEveryDayAlwaysHasANonEmptyAccessibleLabel(): void
    {
        // The builder's contract: colour is never the only carrier of
        // meaning, so there is no reachable path to a labelless cell.
        $weeks = $this->builder->build(2026, 3, [
            '2026-03-14' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
        ], $this->free);

        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                $this->assertNotSame('', $day['accessible_label']);
            }
        }
    }

    public function testFebruaryOfALeapYearIncludesThe29th(): void
    {
        $weeks = $this->builder->build(2028, 2, [], $this->free);

        $leapDay = $this->findDay($weeks, '2028-02-29');
        $this->assertNotNull($leapDay);
        $this->assertTrue($leapDay['in_month']);
        $this->assertSame(29, $leapDay['day_number']);
        $this->assertNull($this->findDay($weeks, '2027-02-29'));
    }

    public function testFebruaryOfANonLeapYearHasNo29th(): void
    {
        $weeks = $this->builder->build(2026, 2, [], $this->free);

        $this->assertNull($this->findDay($weeks, '2026-02-29'));
        $this->assertTrue($this->findDay($weeks, '2026-02-28')['in_month']);
    }

    public function testAFiveWeekAndASixWeekMonthBothRenderAsWholeWeeks(): void
    {
        // A month starting on a Sunday with 31 days is the 6-row worst case;
        // February in a non-leap year starting on a Monday is the 4-row best
        // case. Both must still be whole weeks.
        foreach ([[2026, 3], [2026, 2], [2026, 8], [2027, 2]] as [$year, $month]) {
            $weeks = $this->builder->build($year, $month, [], $this->free);

            $this->assertGreaterThanOrEqual(4, count($weeks));
            $this->assertLessThanOrEqual(6, count($weeks));
            $this->assertSame(count($weeks) * 7, array_sum(array_map(
                fn(array $w) => count($w['days']),
                $weeks
            )));
        }
    }

    public function testConsecutiveDaysAreContiguousAcrossWeekBoundaries(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free);

        $dates = [];
        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                $dates[] = $day['date'];
            }
        }

        $this->assertSame(count($dates), count(array_unique($dates)));
        for ($i = 1; $i < count($dates); $i++) {
            $expected = (new \DateTimeImmutable($dates[$i - 1]))->modify('+1 day')->format('Y-m-d');
            $this->assertSame($expected, $dates[$i]);
        }
    }

    public function testDayNumberMatchesTheDateItRendersFor(): void
    {
        $weeks = $this->builder->build(2026, 3, [], $this->free);

        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                $this->assertSame(
                    (int) (new \DateTimeImmutable($day['date']))->format('j'),
                    $day['day_number']
                );
            }
        }
    }
}
