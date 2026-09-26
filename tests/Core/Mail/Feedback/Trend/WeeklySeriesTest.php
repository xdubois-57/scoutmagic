<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Trend;

use Core\Mail\Feedback\Trend\WeeklySeries;
use PHPUnit\Framework\TestCase;

/**
 * The three arbitrations of #420, each pinned where it is decided: the ISO
 * week, the hole below the threshold, and the left edge that comes from the
 * purge.
 */
class WeeklySeriesTest extends TestCase
{
    public function testAWeekWithEnoughEvidenceIsDrawnAsItsRatio(): void
    {
        $series = WeeklySeries::build(
            [
                ['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 8, 'hits' => 6],
                ['at' => new \DateTimeImmutable('2026-09-23 10:00:00'), 'sample' => 2, 'hits' => 2],
            ],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertCount(1, $series->points, 'the edge and now are in the same ISO week');
        $this->assertSame('2026-W39', $series->points[0]['week']);
        $this->assertSame(10, $series->points[0]['sample'], 'events in one week are summed');
        $this->assertSame(0.8, $series->points[0]['value']);
    }

    /**
     * **Below the threshold there is no point at all**, and the difference
     * from a zero matters: a zero says « nothing passed », a hole says « we
     * cannot tell ». Only one of those is true of a week with four
     * measurements.
     */
    public function testAWeekUnderTheThresholdIsAHoleAndNotAZero(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 4, 'hits' => 0]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertNull($series->points[0]['value'], 'four measurements cannot carry a rate');
        $this->assertSame(4, $series->points[0]['sample'], 'and the evidence is still reported');
    }

    /** Exactly the threshold is enough — the guard is « fewer than », not « at most ». */
    public function testTheThresholdItselfIsEnough(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 5, 'hits' => 5]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertSame(1.0, $series->points[0]['value']);
    }

    /**
     * **A week with no rows at all is a hole too, never a zero**, and this
     * is what makes the fixed left edge honest on a young installation: the
     * curve spans the whole retention window without claiming that nothing
     * was sent before the site existed.
     */
    public function testWeeksWithNoRowsAreHolesAcrossTheWholeWindow(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 9, 'hits' => 9]],
            5,
            new \DateTimeImmutable('2026-08-31 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertSame(
            ['2026-W36', '2026-W37', '2026-W38', '2026-W39'],
            array_column($series->points, 'week'),
            'every week between the edge and now is present, measured or not'
        );
        $this->assertSame(
            [null, null, null, 1.0],
            array_column($series->points, 'value'),
            'and the unmeasured ones are holes'
        );
    }

    /** The current week is flagged, because it is still filling up. */
    public function testTheLastWeekIsMarkedPartialAndTheOthersAreNot(): void
    {
        $series = WeeklySeries::build(
            [],
            5,
            new \DateTimeImmutable('2026-09-07 00:00:00'),
            new \DateTimeImmutable('2026-09-23 12:00:00')
        );

        $this->assertSame(
            [false, false, true],
            array_column($series->points, 'partial'),
            'only the week somebody is looking at is still moving'
        );
    }

    /**
     * **The ISO year, not the calendar year.** The last days of December
     * belong to week 1 of the next ISO year: 2026-12-28 is `2026-W53` under
     * `Y-\WW` and `2026-W53` here only because `o` agrees that week — the
     * case that separates them is 2027-01-01, still week 53 of ISO 2026.
     */
    public function testAWeekStraddlingNewYearKeepsItsIsoYear(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2027-01-01 10:00:00'), 'sample' => 5, 'hits' => 5]],
            5,
            new \DateTimeImmutable('2026-12-28 00:00:00'),
            new \DateTimeImmutable('2027-01-01 12:00:00')
        );

        $this->assertSame(['2026-W53'], array_column($series->points, 'week'));
        $this->assertSame(
            1.0,
            $series->points[0]['value'],
            'a row filed under the calendar year would have landed in a week that is not drawn'
        );
    }

    /**
     * **The edge almost never falls on a Monday**, and this is what pins the
     * normalisation. A retention constant subtracted from « now » lands on
     * whatever weekday that gives: the first bucket still has to be that
     * day's whole ISO week, starting on its Monday, or the days before the
     * edge are filed under a week that is never drawn.
     */
    public function testAnEdgeInTheMiddleOfAWeekStillStartsOnThatWeeksMonday(): void
    {
        // A Thursday, and a row from the Tuesday BEFORE it — inside the same
        // ISO week, outside the 90 days.
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-22 10:00:00'), 'sample' => 6, 'hits' => 3]],
            5,
            new \DateTimeImmutable('2026-09-24 13:45:00'),
            new \DateTimeImmutable('2026-10-01 09:00:00')
        );

        $this->assertSame(
            ['2026-W39', '2026-W40'],
            array_column($series->points, 'week'),
            'the edge week is drawn whole, from its Monday'
        );
        $this->assertSame(
            '2026-09-21',
            $series->points[0]['from']->format('Y-m-d'),
            'and the point is dated by that Monday, not by the edge'
        );
        $this->assertSame(
            0.5,
            $series->points[0]['value'],
            'a row earlier in the edge week counts, rather than falling in an undrawn bucket'
        );
    }

    /**
     * Europe/Brussels moved its clocks on 2026-10-25, and the walk has to
     * cross that without dropping or doubling a week.
     *
     * `modify('+168 hours')` passes this too — measured, because I first
     * wrote that it would not — since `modify()` works in civil time. What
     * this pins is the class of rewrite that WOULD break: stepping the
     * timestamp by 604800 seconds.
     */
    public function testTheWalkSurvivesADaylightSavingChange(): void
    {
        $series = WeeklySeries::build(
            [],
            5,
            new \DateTimeImmutable('2026-10-12 00:00:00', new \DateTimeZone('Europe/Brussels')),
            new \DateTimeImmutable('2026-11-09 12:00:00', new \DateTimeZone('Europe/Brussels'))
        );

        $this->assertSame(
            ['2026-W42', '2026-W43', '2026-W44', '2026-W45', '2026-W46'],
            array_column($series->points, 'week'),
            'no week is dropped or doubled by the hour the clocks moved'
        );
    }

    /** Nothing measured anywhere is a state the screens have to recognise. */
    public function testASeriesWithNoDrawablePointKnowsItIsEmpty(): void
    {
        $empty = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 1, 'hits' => 1]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertTrue($empty->isEmpty(), 'one measurement under the threshold draws nothing');
    }

    public function testASeriesWithOneDrawablePointIsNotEmpty(): void
    {
        $drawn = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 5, 'hits' => 0]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertFalse($drawn->isEmpty(), 'a rate of zero IS a measurement, and is drawn');
    }

    /**
     * A threshold of zero would let « no evidence » satisfy the test and
     * then divide by it. Refused once, here, rather than guarded around at
     * the point of use — where a second check beside the threshold would be
     * one boundary with two mechanisms.
     */
    public function testAThresholdBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WeeklySeries::build(
            [],
            0,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );
    }
}
