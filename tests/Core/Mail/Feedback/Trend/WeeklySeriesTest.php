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
    /**
     * The ordinary case, and the one thing it pins: **rows are summed into
     * their week before the threshold is applied**, not judged one by one.
     * Two reports in the same week carry ten between them and the week is
     * drawn; either alone would be under the five asked for.
     */
    public function testAWeekWithEnoughEvidenceIsDrawnAsItsRatio(): void
    {
        $series = WeeklySeries::build(
            [
                ['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 8, 'hits' => 6, 'total' => 8],
                ['at' => new \DateTimeImmutable('2026-09-23 10:00:00'), 'sample' => 2, 'hits' => 2, 'total' => 2],
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
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 4, 'hits' => 0, 'total' => 4]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertNull($series->points[0]['value'], 'four measurements cannot carry a rate');
        $this->assertSame(4, $series->points[0]['sample'], 'and the evidence is still reported');
    }

    /**
     * **The evidence and the denominator are separate figures**, and the seed
     * boxes are the case: the threshold counts mailings while the share is
     * over answered copies. One mailing to eight boxes is one piece of
     * evidence, so it stays under a threshold of five however many copies it
     * produced.
     */
    public function testTheThresholdCountsEvidenceAndTheRatioCountsSomethingElse(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 1, 'hits' => 6, 'total' => 8]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertNull(
            $series->points[0]['value'],
            'eight copies of one mailing are one observation, not eight'
        );
    }

    /** And with the evidence there, the share is over the denominator. */
    public function testTheRatioDividesByTheDenominatorAndNotByTheEvidence(): void
    {
        $series = WeeklySeries::build(
            [
                ['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 3, 'hits' => 4, 'total' => 6],
                ['at' => new \DateTimeImmutable('2026-09-23 10:00:00'), 'sample' => 2, 'hits' => 2, 'total' => 4],
            ],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertSame(5, $series->points[0]['sample'], 'five mailings of evidence');
        $this->assertSame(0.6, $series->points[0]['value'], 'six of ten answered copies, not six of five');
    }

    /**
     * **Enough evidence and nothing answered yet is a hole, not a division by
     * zero.** A week can carry its mailings while every copy is still
     * pending, and that is not the same state as « nothing landed ».
     */
    public function testAWeekWithEvidenceButNoAnsweredMeasurementIsAHole(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 7, 'hits' => 0, 'total' => 0]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertNull($series->points[0]['value']);
        $this->assertSame(7, $series->points[0]['sample']);
    }

    /** Exactly the threshold is enough — the guard is « fewer than », not « at most ». */
    public function testTheThresholdItselfIsEnough(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 5, 'hits' => 5, 'total' => 5]],
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
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 9, 'hits' => 9, 'total' => 9]],
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
            [['at' => new \DateTimeImmutable('2027-01-01 10:00:00'), 'sample' => 5, 'hits' => 5, 'total' => 5]],
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
        // A Thursday edge, and a row from the Friday AFTER it. **Shaped the
        // way a caller can actually shape it**: every repository filters
        // `>= $edge` and the purge has deleted the rest, so a row from earlier
        // in the edge's week cannot reach this class. An earlier version of
        // this test fed exactly such a row and asserted it was counted — it
        // passed for a reason production could not produce, and it is what
        // hid the truncation asserted below.
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-25 10:00:00'), 'sample' => 6, 'hits' => 3, 'total' => 6]],
            5,
            new \DateTimeImmutable('2026-09-24 13:45:00'),
            new \DateTimeImmutable('2026-10-01 09:00:00')
        );

        $this->assertSame(
            ['2026-W39', '2026-W40'],
            array_column($series->points, 'week'),
            'the edge week is drawn, from its Monday'
        );
        $this->assertSame(
            '2026-09-21',
            $series->points[0]['from']->format('Y-m-d'),
            'and the point is dated by that Monday, not by the edge'
        );
        $this->assertSame(
            0.5,
            $series->points[0]['value'],
            'the rows that DO reach it are counted in that week'
        );
    }

    /**
     * **The first week is short, and says so** — the other end of the same
     * problem `partial` solves. The walk starts on the Monday of the edge's
     * week while the rows start at the edge, so a Thursday edge leaves Monday
     * to Wednesday out of a bucket labelled « the week of the 21st ». Drawn
     * unmarked it understates its own week: a short sample can fall under the
     * threshold, and the ratio covers fewer days than the label claims.
     */
    public function testTheFirstWeekIsFlaggedTruncatedWhenTheEdgeIsNotAMonday(): void
    {
        $series = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-25 10:00:00'), 'sample' => 6, 'hits' => 3, 'total' => 6]],
            5,
            new \DateTimeImmutable('2026-09-24 13:45:00'),
            new \DateTimeImmutable('2026-10-01 09:00:00')
        );

        $this->assertTrue($series->points[0]['truncated'], 'Monday to Wednesday were purged');
        $this->assertFalse($series->points[0]['partial'], 'and that week is long over — it will not grow');
        $this->assertFalse($series->points[1]['truncated'], 'the following week is whole');
        $this->assertTrue($series->points[1]['partial'], 'and is the one still filling up');
    }

    /**
     * **An edge exactly on a Monday midnight truncates nothing**, which is
     * what keeps the flag from being permanently on: it marks a real loss of
     * days, not the mere fact of being first.
     */
    public function testAnEdgeOnAMondayMidnightIsNotTruncated(): void
    {
        $series = WeeklySeries::build(
            [],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-10-01 09:00:00')
        );

        $this->assertFalse($series->points[0]['truncated']);
    }

    /**
     * A window entirely inside one week is both: still filling AND missing
     * its earlier days. Two flags rather than one precisely so neither has to
     * win — a screen can say both.
     */
    public function testAWindowInsideASingleWeekIsBothPartialAndTruncated(): void
    {
        $series = WeeklySeries::build(
            [],
            5,
            new \DateTimeImmutable('2026-09-23 08:00:00'),
            new \DateTimeImmutable('2026-09-25 17:00:00')
        );

        $this->assertCount(1, $series->points);
        $this->assertTrue($series->points[0]['partial']);
        $this->assertTrue($series->points[0]['truncated']);
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
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 1, 'hits' => 1, 'total' => 1]],
            5,
            new \DateTimeImmutable('2026-09-21 00:00:00'),
            new \DateTimeImmutable('2026-09-25 12:00:00')
        );

        $this->assertTrue($empty->isEmpty(), 'one measurement under the threshold draws nothing');
    }

    /**
     * The other side of `isEmpty()`, and it is a ratio of **zero** on
     * purpose: a week where nothing landed is a measurement, not an absence.
     * Reading that as empty would hide the very week somebody needs to see.
     */
    public function testASeriesWithOneDrawablePointIsNotEmpty(): void
    {
        $drawn = WeeklySeries::build(
            [['at' => new \DateTimeImmutable('2026-09-21 10:00:00'), 'sample' => 5, 'hits' => 0, 'total' => 5]],
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
