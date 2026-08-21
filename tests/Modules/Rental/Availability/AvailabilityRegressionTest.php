<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Availability;

use Core\View\MonthGrid\DayState;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Availability\BookingConstraints;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Pricing\BillingUnit;
use PHPUnit\Framework\TestCase;

/**
 * The four availability defects a full review of the module turned up, each
 * pinned by the case that used to give the wrong answer.
 *
 * They are gathered here rather than scattered through
 * AvailabilityCalculatorTest because what they have in common is worth
 * saying out loud: every one of them was a rule applied in one direction, or
 * to one kind of caller, when it should have applied to both.
 *
 * `$today` is always injected, so nothing here depends on when it runs.
 */
class AvailabilityRegressionTest extends TestCase
{
    private AvailabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AvailabilityCalculator();
    }

    private function date(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd);
    }

    // ── The buffer bites in both directions ─────────────────────────────

    public function testTheBufferHoldsOffACandidateThatEndsWhereAStayBegins(): void
    {
        // The mirror of the case below. With one buffer night configured,
        // the answer for an identical PAIR of stays must not depend on which
        // of the two happened to be created first — and it used to: only a
        // stored occupancy was pushed forward, so a candidate ENDING on the
        // existing arrival day met no buffer at all.
        $existing = [new Occupancy('2027-07-14', '2027-07-17')];

        $this->assertFalse(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-10'),
                $this->date('2027-07-14'),
                1,
                1,
                $existing,
                BillingUnit::PER_NIGHT,
                bufferNights: 1
            ),
            'Departing on the 14th leaves no free night before the arrival on the 14th.'
        );
    }

    public function testTheBufferStillHoldsOffACandidateThatBeginsWhereAStayEnds(): void
    {
        $existing = [new Occupancy('2027-07-10', '2027-07-14')];

        $this->assertFalse(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-14'),
                $this->date('2027-07-17'),
                1,
                1,
                $existing,
                BillingUnit::PER_NIGHT,
                bufferNights: 1
            )
        );
    }

    public function testOneFreeNightIsEnoughOnceTheBufferIsSatisfiedInEitherDirection(): void
    {
        // The symmetric fix must not turn into a blanket refusal: a genuine
        // gap of one night is exactly what one buffer night asks for.
        $existing = [new Occupancy('2027-07-14', '2027-07-17')];

        $this->assertTrue(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-10'),
                $this->date('2027-07-13'),
                1,
                1,
                $existing,
                BillingUnit::PER_NIGHT,
                bufferNights: 1
            ),
            'Departing on the 13th leaves the night of the 13th free before the 14th.'
        );
    }

    public function testWithoutABufferTwoNightsStaysMayStillTouch(): void
    {
        // §6.8's headline case, unchanged: the departure day frees up.
        $existing = [new Occupancy('2027-07-14', '2027-07-17')];

        $this->assertTrue(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-10'),
                $this->date('2027-07-14'),
                1,
                1,
                $existing,
                BillingUnit::PER_NIGHT
            )
        );
    }

    // ── A block's end date is the last day blocked ──────────────────────

    public function testABlocksEndDateIsHeldEvenOnANightBasedAsset(): void
    {
        // "Du 10 au 11" for two days of cleaning must block two days. A
        // stay's departure day frees up; a block's end date does not, and
        // reading it as a departure quietly gave the caretaker one day and
        // left the second bookable.
        $block = [new Occupancy('2027-07-10', '2027-07-11', endDateIsHeld: true)];

        foreach (['2027-07-10', '2027-07-11'] as $blocked) {
            $this->assertSame(
                0,
                $this->calculator->remainingUnitsOn($this->date($blocked), 1, $block, BillingUnit::PER_NIGHT),
                $blocked . ' is inside the block.'
            );
        }

        $this->assertSame(
            1,
            $this->calculator->remainingUnitsOn($this->date('2027-07-12'), 1, $block, BillingUnit::PER_NIGHT),
            'The day after the end date is free again.'
        );
    }

    public function testASingleDayBlockHoldsThatDay(): void
    {
        $block = [new Occupancy('2027-07-10', '2027-07-10', endDateIsHeld: true)];

        $this->assertSame(
            0,
            $this->calculator->remainingUnitsOn($this->date('2027-07-10'), 1, $block, BillingUnit::PER_NIGHT)
        );
    }

    public function testAStayIsStillHalfOpenAlongsideABlock(): void
    {
        // The flag must not leak into ordinary occupancies.
        $stay = [new Occupancy('2027-07-10', '2027-07-11')];

        $this->assertSame(
            1,
            $this->calculator->remainingUnitsOn($this->date('2027-07-11'), 1, $stay, BillingUnit::PER_NIGHT)
        );
    }

    // ── A range covering no day is not "available" ──────────────────────

    public function testAZeroNightRangeIsNeverAvailable(): void
    {
        // The loop used to report "available" by never running, which is how
        // a zero-night stay was written onto a fully booked asset.
        $occupied = [new Occupancy('2027-07-01', '2027-08-01')];

        $this->assertFalse(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-15'),
                $this->date('2027-07-15'),
                1,
                1,
                $occupied,
                BillingUnit::PER_NIGHT
            )
        );
    }

    public function testAnInvertedRangeIsNeverAvailable(): void
    {
        $this->assertFalse(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-15'),
                $this->date('2027-07-10'),
                1,
                1,
                [],
                BillingUnit::PER_NIGHT
            )
        );
    }

    public function testASameDayRangeIsStillFineOnAFullDaysAsset(): void
    {
        // A trailer taken and returned the same day IS a day's letting.
        $this->assertTrue(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-15'),
                $this->date('2027-07-15'),
                1,
                1,
                [],
                BillingUnit::PER_DAY
            )
        );
    }

    public function testAZeroNightRangeIsBlamedOnItsDurationNotOnTheAsset(): void
    {
        $errors = $this->calculator->validateRange(
            $this->date('2027-07-15'),
            $this->date('2027-07-15'),
            1,
            1,
            [],
            BillingUnit::PER_NIGHT,
            new BookingConstraints(),
            $this->date('2027-06-01')
        );

        $this->assertContains('Le séjour doit couvrir au moins une nuit.', $errors);
        $this->assertNotContains(
            'Cette période n\'est pas disponible.',
            $errors,
            'The asset is not to blame for a request covering no night.'
        );
    }

    // ── A manager's grid shows occupancy the public rules would hide ────

    public function testAManagersGridShowsOccupancyInAPastMonth(): void
    {
        $occupancies = [new Occupancy('2027-07-10', '2027-07-15')];
        $today = $this->date('2027-08-21');

        $public = $this->calculator->monthDayStates(
            2027,
            7,
            1,
            $occupancies,
            BillingUnit::PER_NIGHT,
            new BookingConstraints(),
            $today
        );
        $managed = $this->calculator->monthDayStates(
            2027,
            7,
            1,
            $occupancies,
            BillingUnit::PER_NIGHT,
            new BookingConstraints(),
            $today,
            null,
            discloseOccupancy: true
        );

        $this->assertSame(DayState::STATE_UNSELECTABLE, $public['2027-07-12']->state);
        $this->assertSame(
            DayState::STATE_OCCUPIED,
            $managed['2027-07-12']->state,
            'A manager paging back to last month must still see what was booked.'
        );
    }

    public function testAManagersGridShowsOccupancyInsideTheNoticePeriod(): void
    {
        $occupancies = [new Occupancy('2027-06-05', '2027-06-10')];
        $today = $this->date('2027-06-01');
        $constraints = new BookingConstraints(minNoticeDays: 14);

        $managed = $this->calculator->monthDayStates(
            2027,
            6,
            1,
            $occupancies,
            BillingUnit::PER_NIGHT,
            $constraints,
            $today,
            null,
            discloseOccupancy: true
        );

        $this->assertSame(DayState::STATE_OCCUPIED, $managed['2027-06-06']->state);
    }

    public function testAFreeDayInAPastMonthIsStillUnselectableForAManager(): void
    {
        // Disclosing occupancy must not turn the whole past into a bookable
        // window: only a day something is actually on changes label.
        $managed = $this->calculator->monthDayStates(
            2027,
            7,
            1,
            [],
            BillingUnit::PER_NIGHT,
            new BookingConstraints(),
            $this->date('2027-08-21'),
            null,
            discloseOccupancy: true
        );

        $this->assertSame(DayState::STATE_UNSELECTABLE, $managed['2027-07-12']->state);
    }

    public function testTheVisitorsGridStillNeverDisclosesWhyADayIsTaken(): void
    {
        $states = $this->calculator->monthDayStates(
            2027,
            7,
            1,
            [new Occupancy('2027-07-10', '2027-07-15', reference: 'LOC-2027-0001')],
            BillingUnit::PER_NIGHT,
            new BookingConstraints(),
            $this->date('2027-06-01')
        );

        $this->assertSame('Occupé', $states['2027-07-12']->accessibleLabel);
        $this->assertStringNotContainsString('LOC', json_encode($states['2027-07-12']) ?: '');
    }
}
