<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Availability;

use Core\View\MonthGrid\DayState;
use Modules\Rental\Availability\ManagedCalendarDays;
use Modules\Rental\Repository\RentalBlock;
use PHPUnit\Framework\TestCase;

/**
 * The manager's days, ready to be blocked by hand (#708, IT-07): the unit's
 * blocks laid over the bookings, and every day of the month from today on
 * a button.
 */
class ManagedCalendarDaysTest extends TestCase
{
    /**
     * @return array<string, DayState>
     */
    private function decorate(array $states, array $blocks = []): array
    {
        return (new ManagedCalendarDays())->decorate($states, $blocks, 2027, 7, new \DateTimeImmutable('2027-07-10'));
    }

    private function block(string $start, string $end): RentalBlock
    {
        return new RentalBlock(1, 1, $start, $end, null, null, new \DateTimeImmutable('2027-01-01'));
    }

    public function testDaysOfTheMonthFromTodayOnAreActionableAndNoOtherIs(): void
    {
        $states = $this->decorate([
            '2027-06-30' => new DayState(DayState::STATE_FREE, 'Libre', null, true),
            '2027-07-09' => new DayState(DayState::STATE_UNSELECTABLE, 'Date passée'),
            '2027-07-10' => new DayState(DayState::STATE_UNSELECTABLE, 'Trop tôt pour réserver'),
            '2027-07-20' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
            '2027-08-01' => new DayState(DayState::STATE_FREE, 'Libre', null, true),
        ]);

        $this->assertFalse($states['2027-06-30']->selectable, 'Padding day of the previous month.');
        $this->assertFalse($states['2027-07-09']->selectable, 'Past day.');
        $this->assertTrue($states['2027-07-10']->selectable, 'Today, even inside the notice period.');
        $this->assertTrue($states['2027-07-20']->selectable, 'A booked day can be blocked too.');
        $this->assertFalse($states['2027-08-01']->selectable, 'Padding day of the next month.');
    }

    public function testAUnitBlockIsLaidOverTheBookingsStateAndNamedInTheLabel(): void
    {
        $states = $this->decorate([
            '2027-07-20' => new DayState(DayState::STATE_OCCUPIED, 'Occupé'),
            '2027-07-21' => new DayState(DayState::STATE_FREE, 'Libre', null, true),
            '2027-07-22' => new DayState(DayState::STATE_FREE, 'Libre', null, true),
        ], [$this->block('2027-07-20', '2027-07-21')]);

        $this->assertSame(DayState::STATE_OCCUPIED, $states['2027-07-20']->state);
        $this->assertSame(['unit-block' => '1'], $states['2027-07-20']->data);
        $this->assertSame('Occupé — et réservé par l\'unité', $states['2027-07-20']->accessibleLabel);
        $this->assertSame('Réservé par l\'unité', $states['2027-07-21']->accessibleLabel);
        $this->assertSame([], $states['2027-07-22']->data);
    }

    public function testThePublicDepartureOnlyHintDoesNotReachTheManagedGrid(): void
    {
        $states = $this->decorate([
            '2027-07-20' => new DayState(DayState::STATE_ARRIVING, 'Libre le matin, arrivée ensuite', null, false, ['departure-only' => '1']),
        ]);

        $this->assertSame([], $states['2027-07-20']->data);
    }
}
