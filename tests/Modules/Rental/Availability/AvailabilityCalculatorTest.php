<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Availability;

use Core\View\MonthGrid\DayState;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Availability\BookingConstraints;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Pricing\BillingUnit;
use PHPUnit\Framework\TestCase;

/**
 * The module spec's §6.8 warns that the case which breaks implementations is
 * **two stays that touch**: one ending on the 20th and one starting on the
 * 20th are compatible in a nights model and in conflict in a full-days one.
 * Both directions are pinned here, along with the confidentiality rule that
 * "occupé" never says why, and the separation between *unavailable* and
 * *merely unselectable* that §6.7 insists on.
 *
 * `$today` is always injected, so nothing here depends on when it runs.
 */
class AvailabilityCalculatorTest extends TestCase
{
    private AvailabilityCalculator $calculator;

    private const TODAY = '2027-06-01';

    protected function setUp(): void
    {
        $this->calculator = new AvailabilityCalculator();
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    private function date(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd);
    }

    // ── The half-open interval, and stays that touch ────────────────────

    public function testANightsStayFreesItsDepartureDay(): void
    {
        // "Une location du 17 au 20 occupe les nuits du 17, 18 et 19."
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];

        foreach (['2027-07-17', '2027-07-18', '2027-07-19'] as $taken) {
            $this->assertSame(
                0,
                $this->calculator->remainingUnitsOn($this->date($taken), 1, $occupancies, BillingUnit::PER_NIGHT),
                $taken . ' should be taken.'
            );
        }

        $this->assertSame(
            1,
            $this->calculator->remainingUnitsOn($this->date('2027-07-20'), 1, $occupancies, BillingUnit::PER_NIGHT),
            'The departure day frees up in the morning.'
        );
    }

    public function testAFullDaysStayKeepsItsReturnDay(): void
    {
        // "Une location de remorque du 17 au 24 en jours pleins occupe le 24."
        $occupancies = [new Occupancy('2027-07-17', '2027-07-24')];

        $this->assertSame(
            0,
            $this->calculator->remainingUnitsOn($this->date('2027-07-24'), 1, $occupancies, BillingUnit::PER_DAY),
            'The asset only comes back at the end of the return day.'
        );
        $this->assertSame(
            1,
            $this->calculator->remainingUnitsOn($this->date('2027-07-25'), 1, $occupancies, BillingUnit::PER_DAY)
        );
    }

    public function testTwoNightsStaysThatTouchDoNotConflict(): void
    {
        // The case §6.8 singles out. A stay ending on the 20th and one
        // starting on the 20th share no night.
        $existing = [new Occupancy('2027-07-17', '2027-07-20')];

        $this->assertTrue($this->calculator->isRangeAvailable(
            $this->date('2027-07-20'),
            $this->date('2027-07-23'),
            1,
            1,
            $existing,
            BillingUnit::PER_NIGHT
        ));
    }

    public function testTwoFullDaysStaysThatTouchDoConflict(): void
    {
        // The mirror: in a days model the 24th belongs to the first stay.
        $existing = [new Occupancy('2027-07-17', '2027-07-24')];

        $this->assertFalse($this->calculator->isRangeAvailable(
            $this->date('2027-07-24'),
            $this->date('2027-07-26'),
            1,
            1,
            $existing,
            BillingUnit::PER_DAY
        ));

        $this->assertTrue(
            $this->calculator->isRangeAvailable(
                $this->date('2027-07-25'),
                $this->date('2027-07-26'),
                1,
                1,
                $existing,
                BillingUnit::PER_DAY
            ),
            'The day after the return is free.'
        );
    }

    public function testAStayOverlappingAnExistingOneIsRefused(): void
    {
        $existing = [new Occupancy('2027-07-17', '2027-07-20')];

        foreach ([
            ['2027-07-18', '2027-07-19'],
            ['2027-07-15', '2027-07-18'],
            ['2027-07-19', '2027-07-22'],
            ['2027-07-10', '2027-07-30'],
        ] as [$from, $to]) {
            $this->assertFalse(
                $this->calculator->isRangeAvailable($this->date($from), $this->date($to), 1, 1, $existing, BillingUnit::PER_NIGHT),
                "{$from} → {$to} overlaps and must be refused."
            );
        }
    }

    public function testDaysCoveredByStayMatchesTheModel(): void
    {
        $nights = $this->calculator->daysCoveredByStay($this->date('2027-07-17'), $this->date('2027-07-20'), BillingUnit::PER_NIGHT);
        $days = $this->calculator->daysCoveredByStay($this->date('2027-07-17'), $this->date('2027-07-20'), BillingUnit::PER_DAY);

        $this->assertSame(
            ['2027-07-17', '2027-07-18', '2027-07-19'],
            array_map(fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $nights)
        );
        $this->assertSame(
            ['2027-07-17', '2027-07-18', '2027-07-19', '2027-07-20'],
            array_map(fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $days)
        );
    }

    public function testASameDayStayHoldsNothingInNightsAndOneDayInFullDays(): void
    {
        $nights = $this->calculator->daysCoveredByStay($this->date('2027-07-17'), $this->date('2027-07-17'), BillingUnit::PER_NIGHT);
        $days = $this->calculator->daysCoveredByStay($this->date('2027-07-17'), $this->date('2027-07-17'), BillingUnit::PER_DAY);

        $this->assertSame([], $nights);
        $this->assertCount(1, $days);
    }

    public function testASameDayOccupancyStillHoldsItsDay(): void
    {
        // Somebody has the trailer today, whatever the billing model says
        // about nights — the calendar must not show it free.
        $occupancies = [new Occupancy('2027-07-17', '2027-07-17')];

        $this->assertSame(
            0,
            $this->calculator->remainingUnitsOn($this->date('2027-07-17'), 1, $occupancies, BillingUnit::PER_NIGHT)
        );
    }

    // ── Buffer ──────────────────────────────────────────────────────────

    public function testABufferHoldsTheAssetAfterEachStay(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];

        // Without a buffer the 20th is free; with one night of buffer it is not.
        $this->assertSame(1, $this->calculator->remainingUnitsOn($this->date('2027-07-20'), 1, $occupancies, BillingUnit::PER_NIGHT, 0));
        $this->assertSame(0, $this->calculator->remainingUnitsOn($this->date('2027-07-20'), 1, $occupancies, BillingUnit::PER_NIGHT, 1));
        $this->assertSame(1, $this->calculator->remainingUnitsOn($this->date('2027-07-21'), 1, $occupancies, BillingUnit::PER_NIGHT, 1));
    }

    public function testABufferLeavesExactlyTheConfiguredGapNotTwice(): void
    {
        // Extending both ends of an occupancy would leave 2 × buffer between
        // two rentals, which is not what "un battement" means.
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];

        $this->assertFalse($this->calculator->isRangeAvailable(
            $this->date('2027-07-20'),
            $this->date('2027-07-22'),
            1,
            1,
            $occupancies,
            BillingUnit::PER_NIGHT,
            1
        ));
        $this->assertTrue($this->calculator->isRangeAvailable(
            $this->date('2027-07-21'),
            $this->date('2027-07-23'),
            1,
            1,
            $occupancies,
            BillingUnit::PER_NIGHT,
            1
        ), 'One night of buffer means the next stay starts one day later, not two.');
    }

    public function testABufferDoesNotBlockTheDaysBeforeAStay(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];

        $this->assertSame(
            1,
            $this->calculator->remainingUnitsOn($this->date('2027-07-16'), 1, $occupancies, BillingUnit::PER_NIGHT, 2)
        );
    }

    // ── Stock ───────────────────────────────────────────────────────────

    public function testStockDecrementsPerBookedUnitAndRefusesMoreThanRemains(): void
    {
        // Spec §9 scenario C: eight tents, A takes 3, B takes 2 over an
        // overlapping period, the public sees 3 left, and a request for 4
        // cannot be made.
        $occupancies = [
            new Occupancy('2027-07-17', '2027-07-24', 3),
            new Occupancy('2027-07-19', '2027-07-22', 2),
        ];

        $this->assertSame(5, $this->calculator->remainingUnitsOn($this->date('2027-07-18'), 8, $occupancies, BillingUnit::PER_NIGHT));
        $this->assertSame(3, $this->calculator->remainingUnitsOn($this->date('2027-07-20'), 8, $occupancies, BillingUnit::PER_NIGHT));
        $this->assertSame(8, $this->calculator->remainingUnitsOn($this->date('2027-07-25'), 8, $occupancies, BillingUnit::PER_NIGHT));

        $this->assertTrue($this->calculator->isRangeAvailable($this->date('2027-07-19'), $this->date('2027-07-22'), 3, 8, $occupancies, BillingUnit::PER_NIGHT));
        $this->assertFalse(
            $this->calculator->isRangeAvailable($this->date('2027-07-19'), $this->date('2027-07-22'), 4, 8, $occupancies, BillingUnit::PER_NIGHT),
            'Only three of the eight tents are free across that whole range.'
        );
    }

    public function testExhaustedStockReadsAsOccupied(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20', 8)];

        $this->assertSame(0, $this->calculator->remainingUnitsOn($this->date('2027-07-18'), 8, $occupancies, BillingUnit::PER_NIGHT));
    }

    public function testAvailabilityIsCheckedOnEveryDayOfTheRangeNotJustItsEnds(): void
    {
        // A single busy day in the middle must sink the whole range.
        $occupancies = [new Occupancy('2027-07-19', '2027-07-20', 8)];

        $this->assertFalse($this->calculator->isRangeAvailable(
            $this->date('2027-07-17'),
            $this->date('2027-07-24'),
            1,
            8,
            $occupancies,
            BillingUnit::PER_NIGHT
        ));
    }

    // ── Occupied never says why ─────────────────────────────────────────

    public function testABookingAHoldAndAManualBlockAreIndistinguishable(): void
    {
        // §6.7/§6.14: the public must not be able to tell them apart. They
        // are literally the same object here, so there is nothing to leak.
        $constraints = new BookingConstraints();

        $states = [];
        foreach (['une réservation', 'une option', 'un blocage'] as $i => $unusedReason) {
            $occupancies = [new Occupancy('2027-07-17', '2027-07-20', 1, 'internal-' . $i)];
            $month = $this->calculator->monthDayStates(2027, 7, 1, $occupancies, BillingUnit::PER_NIGHT, $constraints, $this->today());
            $states[] = $month['2027-07-18'];
        }

        foreach ($states as $state) {
            $this->assertSame(DayState::STATE_OCCUPIED, $state->state);
            $this->assertSame('Occupé', $state->accessibleLabel);
            $this->assertSame($states[0]->color, $state->color);
            $this->assertSame($states[0]->data, $state->data);
            $this->assertSame([], $state->data, 'No internal reference must reach the rendered cell.');
        }
    }

    // ── Unavailable vs merely unselectable ──────────────────────────────

    public function testADayInsideTheNoticeWindowIsUnselectableNotOccupied(): void
    {
        // §6.7, and the reason this distinction exists: a visitor shown
        // "taken" for a free day concludes the asset is booked and gives up.
        $constraints = new BookingConstraints(minNoticeDays: 14);

        $states = $this->calculator->monthDayStates(2027, 6, 1, [], BillingUnit::PER_NIGHT, $constraints, $this->today());

        $tooSoon = $states['2027-06-10'];
        $this->assertSame(DayState::STATE_UNSELECTABLE, $tooSoon->state);
        $this->assertNotSame(DayState::STATE_OCCUPIED, $tooSoon->state);
        $this->assertSame('Trop tôt pour réserver', $tooSoon->accessibleLabel);
        $this->assertFalse($tooSoon->selectable);

        $this->assertSame(DayState::STATE_FREE, $states['2027-06-20']->state);
    }

    public function testAPastDayIsUnselectableAndLabelledAsPast(): void
    {
        $states = $this->calculator->monthDayStates(2027, 6, 1, [], BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today());

        $this->assertSame(DayState::STATE_UNSELECTABLE, $states['2027-05-30']->state);
        $this->assertSame('Date passée', $states['2027-05-30']->accessibleLabel);
        $this->assertSame(DayState::STATE_FREE, $states[self::TODAY]->state, 'Today itself is bookable with no notice period.');
    }

    public function testADayBeyondTheHorizonIsUnselectable(): void
    {
        $constraints = new BookingConstraints(maxHorizonDays: 30);

        $states = $this->calculator->monthDayStates(2027, 8, 1, [], BillingUnit::PER_NIGHT, $constraints, $this->today());

        $this->assertSame(DayState::STATE_UNSELECTABLE, $states['2027-08-15']->state);
        $this->assertSame('Trop lointain pour réserver', $states['2027-08-15']->accessibleLabel);
    }

    public function testTheUnbookableWindowOutranksOccupancySoNothingLeaksAboutPastDays(): void
    {
        // A day nobody can book anyway must not disclose whether it is taken.
        $constraints = new BookingConstraints(minNoticeDays: 30);
        $occupancies = [new Occupancy('2027-06-05', '2027-06-10')];

        $states = $this->calculator->monthDayStates(2027, 6, 1, $occupancies, BillingUnit::PER_NIGHT, $constraints, $this->today());

        $this->assertSame(DayState::STATE_UNSELECTABLE, $states['2027-06-06']->state);
    }

    // ── Departure day gets its own state ────────────────────────────────

    public function testTheDepartureDayHasItsOwnStateDistinctFromFreeAndOccupied(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];

        $states = $this->calculator->monthDayStates(2027, 7, 1, $occupancies, BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today());

        $this->assertSame(DayState::STATE_OCCUPIED, $states['2027-07-19']->state);
        $this->assertSame(DayState::STATE_DEPARTING, $states['2027-07-20']->state);
        $this->assertTrue($states['2027-07-20']->selectable, 'It is pickable as a new arrival.');
        $this->assertSame(DayState::STATE_FREE, $states['2027-07-21']->state);
    }

    public function testThereIsNoDepartureDayStateInAFullDaysModel(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];

        $states = $this->calculator->monthDayStates(2027, 7, 1, $occupancies, BillingUnit::PER_DAY, new BookingConstraints(), $this->today());

        $this->assertSame(DayState::STATE_OCCUPIED, $states['2027-07-20']->state);
    }

    public function testABufferSuppressesTheDepartureDayStateBecauseItWouldBeALie(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20')];
        $constraints = new BookingConstraints(bufferNights: 1);

        $states = $this->calculator->monthDayStates(2027, 7, 1, $occupancies, BillingUnit::PER_NIGHT, $constraints, $this->today());

        $this->assertSame(DayState::STATE_OCCUPIED, $states['2027-07-20']->state, 'The buffer holds it — it is not free from midday.');
    }

    // ── Partial stock in the grid ───────────────────────────────────────

    public function testPartialStockRendersItsRemainingCountForTheVisitor(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20', 5)];

        $states = $this->calculator->monthDayStates(2027, 7, 8, $occupancies, BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today());

        $partial = $states['2027-07-18'];
        $this->assertSame(DayState::STATE_PARTIAL, $partial->state);
        $this->assertSame('3 disponibles sur 8', $partial->accessibleLabel);
        $this->assertSame(['remaining' => '3'], $partial->data);
        $this->assertTrue($partial->selectable);
    }

    public function testASingleRemainingUnitIsAnnouncedInTheSingular(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20', 7)];

        $states = $this->calculator->monthDayStates(2027, 7, 8, $occupancies, BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today());

        $this->assertSame('1 disponible sur 8', $states['2027-07-18']->accessibleLabel);
    }

    // ── The grid spans whole weeks ──────────────────────────────────────

    public function testStatesCoverThePaddingDaysOfAdjacentMonths(): void
    {
        // The rendered grid spans whole weeks; without states for the
        // padding days a stay crossing the month boundary would appear to
        // stop at the 1st.
        $occupancies = [new Occupancy('2027-06-28', '2027-07-03')];

        $states = $this->calculator->monthDayStates(2027, 7, 1, $occupancies, BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today());

        $this->assertArrayHasKey('2027-06-28', $states);
        $this->assertSame(DayState::STATE_OCCUPIED, $states['2027-06-28']->state);
        $this->assertSame(DayState::STATE_OCCUPIED, $states['2027-07-01']->state);
    }

    public function testEveryStateCarriesANonEmptyAccessibleLabel(): void
    {
        $occupancies = [new Occupancy('2027-07-17', '2027-07-20', 5)];
        $constraints = new BookingConstraints(minNoticeDays: 7, maxHorizonDays: 120);

        $states = $this->calculator->monthDayStates(2027, 7, 8, $occupancies, BillingUnit::PER_NIGHT, $constraints, $this->today());

        $this->assertNotSame([], $states);
        foreach ($states as $date => $state) {
            $this->assertNotSame('', $state->accessibleLabel, $date . ' has no accessible label.');
        }
    }

    // ── Selection ───────────────────────────────────────────────────────

    public function testAFirstTapSelectsOnlyTheArrivalDay(): void
    {
        $states = $this->calculator->monthDayStates(
            2027, 7, 1, [], BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today(),
            ['2027-07-17', null]
        );

        $this->assertSame(DayState::STATE_SELECTED, $states['2027-07-17']->state);
        $this->assertSame(DayState::STATE_FREE, $states['2027-07-18']->state);
    }

    public function testASecondTapSelectsTheWholeRangeIncludingTheDepartureDay(): void
    {
        // The departure day is shown selected even though it is not billed
        // in a nights model: a visitor who picked "17 to 20" expects the 20th
        // highlighted, and a gap there reads as a bug.
        $states = $this->calculator->monthDayStates(
            2027, 7, 1, [], BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today(),
            ['2027-07-17', '2027-07-20']
        );

        foreach (['2027-07-17', '2027-07-18', '2027-07-19', '2027-07-20'] as $day) {
            $this->assertSame(DayState::STATE_SELECTED, $states[$day]->state, $day);
        }
        $this->assertSame(DayState::STATE_FREE, $states['2027-07-21']->state);
    }

    public function testSelectionIsShownEvenOverAnOccupiedDay(): void
    {
        // Rank 1: whatever the visitor is choosing is always visible, so an
        // invalid selection is something they can see and correct.
        $occupancies = [new Occupancy('2027-07-18', '2027-07-19')];

        $states = $this->calculator->monthDayStates(
            2027, 7, 1, $occupancies, BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today(),
            ['2027-07-17', '2027-07-20']
        );

        $this->assertSame(DayState::STATE_SELECTED, $states['2027-07-18']->state);
    }

    // ── Range validation ────────────────────────────────────────────────

    public function testAValidRangePassesWithNoErrors(): void
    {
        $errors = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-20'), 1, 1, [],
            BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today()
        );

        $this->assertSame([], $errors);
    }

    public function testAReversedRangeIsRefusedWithASingleClearReason(): void
    {
        $errors = $this->calculator->validateRange(
            $this->date('2027-07-20'), $this->date('2027-07-17'), 1, 1, [],
            BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today()
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('doit suivre', $errors[0]);
    }

    public function testMinimumAndMaximumDurationAreEnforced(): void
    {
        $constraints = new BookingConstraints(minNights: 2, maxNights: 7);

        $tooShort = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-18'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );
        $tooLong = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-30'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );
        $justRight = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-24'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );

        $this->assertStringContainsString('durée minimum', implode(' ', $tooShort));
        $this->assertStringContainsString('durée maximum', implode(' ', $tooLong));
        $this->assertSame([], $justRight, 'Exactly the maximum is allowed.');
    }

    public function testTheNoticePeriodIsReportedAsTooEarlyNotAsUnavailable(): void
    {
        $constraints = new BookingConstraints(minNoticeDays: 14);

        $errors = $this->calculator->validateRange(
            $this->date('2027-06-05'), $this->date('2027-06-08'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );

        $this->assertStringContainsString("à l'avance", implode(' ', $errors));
        $this->assertStringNotContainsString('disponible', implode(' ', $errors));
    }

    public function testTheHorizonIsEnforced(): void
    {
        $constraints = new BookingConstraints(maxHorizonDays: 30);

        $errors = $this->calculator->validateRange(
            $this->date('2027-09-01'), $this->date('2027-09-03'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );

        $this->assertStringContainsString('trop lointaine', implode(' ', $errors));
    }

    public function testForbiddenArrivalWeekdaysAreEnforced(): void
    {
        // Fridays and Saturdays only.
        $constraints = new BookingConstraints(allowedArrivalWeekdays: [5, 6]);

        // 2027-07-19 is a Monday, 2027-07-16 a Friday.
        $monday = $this->calculator->validateRange(
            $this->date('2027-07-19'), $this->date('2027-07-22'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );
        $friday = $this->calculator->validateRange(
            $this->date('2027-07-16'), $this->date('2027-07-19'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today()
        );

        $this->assertStringContainsString('jour de la semaine', implode(' ', $monday));
        $this->assertSame([], $friday);
    }

    public function testCapacityIsEnforcedWhenAHeadCountIsGiven(): void
    {
        $constraints = new BookingConstraints(maxPersons: 40);

        $tooMany = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-20'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today(), persons: 50
        );
        $exactly = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-20'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today(), persons: 40
        );

        $this->assertStringContainsString('capacité maximum', implode(' ', $tooMany));
        $this->assertSame([], $exactly);
    }

    public function testAnUnavailablePeriodIsRefusedWithoutSayingWhy(): void
    {
        $occupancies = [new Occupancy('2027-07-18', '2027-07-19', 1, 'LOC-2027-0042')];

        $errors = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-20'), 1, 1, $occupancies,
            BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today()
        );

        $this->assertSame(["Cette période n'est pas disponible."], $errors);
        $this->assertStringNotContainsString('LOC-2027-0042', implode(' ', $errors));
    }

    public function testRequestingMoreUnitsThanExistIsRefused(): void
    {
        $errors = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-20'), 12, 8, [],
            BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today()
        );

        $this->assertStringContainsString('exemplaires', implode(' ', $errors));
    }

    public function testEveryFailingRuleIsReportedAtOnce(): void
    {
        // A visitor should learn everything they need to change in one go,
        // not discover the rules one refusal at a time.
        $constraints = new BookingConstraints(minNights: 5, minNoticeDays: 60, maxPersons: 10);

        $errors = $this->calculator->validateRange(
            $this->date('2027-06-10'), $this->date('2027-06-11'), 1, 1, [],
            BillingUnit::PER_NIGHT, $constraints, $this->today(), persons: 50
        );

        $this->assertGreaterThanOrEqual(3, count($errors));
    }

    public function testANightsStayMustCoverAtLeastOneNight(): void
    {
        $errors = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-17'), 1, 1, [],
            BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today()
        );

        $this->assertStringContainsString('au moins une nuit', implode(' ', $errors));
    }

    public function testASameDayFullDaysRentalIsPerfectlyValid(): void
    {
        // A trailer taken and returned the same day.
        $errors = $this->calculator->validateRange(
            $this->date('2027-07-17'), $this->date('2027-07-17'), 1, 1, [],
            BillingUnit::PER_DAY, new BookingConstraints(), $this->today()
        );

        $this->assertSame([], $errors);
    }

    public function testRangeCrossesUnavailabilityIsTheInverseOfAvailability(): void
    {
        $occupancies = [new Occupancy('2027-07-18', '2027-07-19')];

        $this->assertTrue($this->calculator->rangeCrossesUnavailability(
            $this->date('2027-07-17'), $this->date('2027-07-20'), 1, 1, $occupancies, BillingUnit::PER_NIGHT
        ));
        $this->assertFalse($this->calculator->rangeCrossesUnavailability(
            $this->date('2027-07-20'), $this->date('2027-07-22'), 1, 1, $occupancies, BillingUnit::PER_NIGHT
        ));
    }

    // ── Date edge cases ─────────────────────────────────────────────────

    public function testOccupancyAcrossAYearBoundaryIsCountedCorrectly(): void
    {
        $occupancies = [new Occupancy('2027-12-30', '2028-01-02')];

        $this->assertSame(0, $this->calculator->remainingUnitsOn($this->date('2027-12-31'), 1, $occupancies, BillingUnit::PER_NIGHT));
        $this->assertSame(0, $this->calculator->remainingUnitsOn($this->date('2028-01-01'), 1, $occupancies, BillingUnit::PER_NIGHT));
        $this->assertSame(1, $this->calculator->remainingUnitsOn($this->date('2028-01-02'), 1, $occupancies, BillingUnit::PER_NIGHT));
    }

    public function testOccupancyAcrossALeapDayCountsIt(): void
    {
        $occupancies = [new Occupancy('2028-02-27', '2028-03-01')];

        $this->assertSame(0, $this->calculator->remainingUnitsOn($this->date('2028-02-29'), 1, $occupancies, BillingUnit::PER_NIGHT));
        $this->assertSame(1, $this->calculator->remainingUnitsOn($this->date('2028-03-01'), 1, $occupancies, BillingUnit::PER_NIGHT));
    }

    public function testNoOccupanciesMeansEverythingBookableIsFree(): void
    {
        $states = $this->calculator->monthDayStates(2027, 7, 1, [], BillingUnit::PER_NIGHT, new BookingConstraints(), $this->today());

        foreach ($states as $state) {
            $this->assertContains($state->state, [DayState::STATE_FREE, DayState::STATE_UNSELECTABLE]);
        }
    }
}
