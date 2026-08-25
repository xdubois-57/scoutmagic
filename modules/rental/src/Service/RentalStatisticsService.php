<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Service\DateInput;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAggregateRepository;
use Modules\Rental\Repository\RentalBookingRepository;

/**
 * The three figures on an asset's overview (§6.34): requests waiting, days
 * let this year, and this year's revenue.
 *
 * **Both sources, always.** A booking that has been purged is gone from
 * `rental_bookings` and survives only as an anonymous aggregate row
 * (§6.35) — so a count that read only the live bookings would show a
 * unit's yearly revenue dropping to zero on the morning the purge ran, and
 * nobody would know why. Reading both is not an optimisation; it is what
 * makes the number true.
 *
 * There is no double counting because the aggregate is written **as** the
 * booking is deleted, in that order: a crash between the two leaves a
 * visible double count on one page rather than a silent hole in a year's
 * figures.
 *
 * Computed on the fly, and deliberately not cached: three sums over one
 * asset's own rows cost nothing next to the page they sit on, and a cache
 * would spend its life disagreeing with the bookings beside it.
 *
 * **The security deposit is never revenue.** It is somebody else's money
 * held briefly, and counting it would inflate a unit's figures by whatever
 * it happens to be holding today.
 */
class RentalStatisticsService
{
    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalAggregateRepository $aggregateRepository
    ) {
    }

    /**
     * @return array{pending: int, occupied_days: int, revenue_cents: int}
     */
    public function forAsset(int $assetId, \DateTimeImmutable $today): array
    {
        $from = $today->setDate((int) $today->format('Y'), 1, 1);
        $to = $from->modify('+1 year -1 day');

        $pending = 0;
        $occupiedDays = 0;
        $revenueCents = 0;

        foreach ($this->bookingRepository->findAllForAssets([$assetId]) as $booking) {
            if ($booking->status->needsAttention()) {
                $pending++;
            }

            if (!self::countsTowardTheYear($booking, $from, $to)) {
                continue;
            }

            $occupiedDays += self::occupiedDays($booking);
            $revenueCents += $booking->effectiveTotalCents() ?? 0;
        }

        // The half that was purged away.
        $aggregated = $this->aggregateRepository->totalsForAsset(
            $assetId,
            $from->format('Y-m'),
            $to->format('Y-m')
        );

        return [
            'pending' => $pending,
            'occupied_days' => $occupiedDays + $aggregated['days'],
            'revenue_cents' => $revenueCents + $aggregated['amount_cents'],
        ];
    }

    /**
     * A stay counts toward a year if it started in it and actually went
     * ahead.
     *
     * Started, not overlapped: a New Year's stay has to land in one year or
     * the other, and the arrival is the one date every booking has and
     * every manager would name. Splitting it across two years would make
     * the two figures add up to more than the letting.
     */
    private static function countsTowardTheYear(
        RentalBooking $booking,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): bool {
        if ($booking->status !== BookingStatus::CONFIRMED && $booking->status !== BookingStatus::CLOSED) {
            // A request that was refused, cancelled or is still being
            // negotiated let nothing and earned nothing.
            return false;
        }

        $arrival = DateInput::requireFromStorage($booking->arrivalDate, 'rental_bookings.arrival_date');

        return $arrival >= $from && $arrival <= $to;
    }

    private static function occupiedDays(RentalBooking $booking): int
    {
        $arrival = DateInput::requireFromStorage($booking->arrivalDate, 'rental_bookings.arrival_date');
        $departure = DateInput::requireFromStorage($booking->departureDate, 'rental_bookings.departure_date');

        return max(1, (int) $arrival->diff($departure)->format('%a'));
    }
}
