<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

/**
 * The configurable rules that decide whether a requested range may be asked
 * for at all — as opposed to whether the asset is free, which is
 * AvailabilityCalculator's separate concern.
 *
 * **Keeping the two apart is the point.** A day inside the minimum-notice
 * window is *free*; it is merely too late to request. Module spec §6.7 is
 * explicit that such a day must be shown like the past and never like
 * "occupé" — a visitor told a free day is taken concludes the asset is
 * booked and gives up on it, which is a worse failure than showing nothing
 * at all. That distinction only survives if a constraint can never produce
 * an "occupied" state, which it cannot here because constraints and
 * occupancy are computed from different inputs.
 */
final class BookingConstraints
{
    /**
     * @param int $minNights Shortest stay accepted. 0 means no minimum.
     * @param int $maxNights Longest stay accepted. 0 means no maximum.
     * @param int $minNoticeDays How far ahead a request must be made. 0 means "today is fine".
     * @param int $maxHorizonDays How far into the future requests are accepted. 0 means no horizon.
     * @param int[] $allowedArrivalWeekdays ISO weekdays (1 = Monday … 7 = Sunday) a stay may start on. Empty means any day.
     * @param int|null $maxPersons Hard capacity ceiling for a request. Null means the asset's own capacity governs.
     * @param int $bufferNights Nights of breathing space left after each occupancy — cleaning, a caretaker's round. 0 means back-to-back rentals are fine.
     */
    public function __construct(
        public readonly int $minNights = 0,
        public readonly int $maxNights = 0,
        public readonly int $minNoticeDays = 0,
        public readonly int $maxHorizonDays = 0,
        public readonly array $allowedArrivalWeekdays = [],
        public readonly ?int $maxPersons = null,
        public readonly int $bufferNights = 0
    ) {
    }

    /**
     * The earliest date a stay may start on, given $today. Days before it
     * are unselectable — not occupied.
     */
    public function earliestArrival(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $earliest = $today->setTime(0, 0);

        return $this->minNoticeDays > 0
            ? $earliest->modify('+' . $this->minNoticeDays . ' days')
            : $earliest;
    }

    /**
     * The latest date a stay may start on, or null when no horizon is set.
     */
    public function latestArrival(\DateTimeImmutable $today): ?\DateTimeImmutable
    {
        return $this->maxHorizonDays > 0
            ? $today->setTime(0, 0)->modify('+' . $this->maxHorizonDays . ' days')
            : null;
    }

    public function allowsArrivalOn(\DateTimeImmutable $date): bool
    {
        return $this->allowedArrivalWeekdays === []
            || in_array((int) $date->format('N'), $this->allowedArrivalWeekdays, true);
    }

    /**
     * Whether $date is outside the bookable window entirely — in the past,
     * inside the notice period, or beyond the horizon.
     */
    public function isOutsideBookableWindow(\DateTimeImmutable $date, \DateTimeImmutable $today): bool
    {
        $day = $date->setTime(0, 0);

        if ($day < $this->earliestArrival($today)) {
            return true;
        }

        $latest = $this->latestArrival($today);

        return $latest !== null && $day > $latest;
    }
}
