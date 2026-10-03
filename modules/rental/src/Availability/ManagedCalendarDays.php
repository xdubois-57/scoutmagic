<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

use Core\View\MonthGrid\DayState;
use Modules\Rental\Repository\RentalBlock;

/**
 * The manager's calendar days, ready to be blocked and released by hand
 * (#708, IT-07).
 *
 * The states come from the bookings ALONE; the unit's own blocks are laid
 * over them as a marker (`data-unit-block`). That is what lets a day show
 * both a booking and a block — the two coexist (§6.18), and a gesture
 * decides its mode from the block, never from the booking.
 *
 * Every day of the displayed month from today on is a button: the shared
 * grid renders a day as a button only when it is selectable, and keyboard
 * use needs one per day. Past days and the padding days of the
 * neighbouring months stay inert. The generic grid is not changed: it only
 * receives states and data attributes, as on every other page using it.
 *
 * Pure: no database, no clock.
 */
final class ManagedCalendarDays
{
    /**
     * @param array<string, DayState> $states Keyed by `Y-m-d`, from the bookings only.
     * @param RentalBlock[] $blocks
     * @return array<string, DayState>
     */
    public function decorate(array $states, array $blocks, int $year, int $month, \DateTimeImmutable $today): array
    {
        $monthPrefix = sprintf('%04d-%02d-', $year, $month);
        $todayKey = $today->format('Y-m-d');
        $decorated = [];

        foreach ($states as $day => $state) {
            $blocked = $this->isBlocked($day, $blocks);
            $actionable = str_starts_with($day, $monthPrefix) && $day >= $todayKey;

            $data = $state->data;
            unset($data['departure-only']);
            if ($blocked) {
                $data['unit-block'] = '1';
            }

            $decorated[$day] = new DayState(
                $state->state,
                $this->label($state, $blocked),
                $state->color,
                $actionable,
                $data
            );
        }

        return $decorated;
    }

    /**
     * @param RentalBlock[] $blocks
     */
    private function isBlocked(string $day, array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block->startDate <= $day && $day <= $block->endDate) {
                return true;
            }
        }

        return false;
    }

    private function label(DayState $state, bool $blocked): string
    {
        if (!$blocked) {
            return $state->accessibleLabel;
        }

        // A day only the unit holds reads as what it is; a day a booking
        // holds too says both.
        return $state->state === DayState::STATE_FREE
            ? 'Réservé par l\'unité'
            : $state->accessibleLabel . ' — et réservé par l\'unité';
    }
}
