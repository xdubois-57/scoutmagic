<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * One booking on « À traiter », and what put it there (§22.5).
 *
 * **The single definition of "à traiter" for the whole module.** It used to
 * be `$booking->status->needsAttention()`, written out at four call sites:
 * the asset overview's list, the same page's « À traiter » figure, the
 * bookings list's own filter, and the per-asset badge on « Mes locations ».
 * Four copies of one rule is four chances for the tile and the list under it
 * to disagree — and widening one of them without the others would have
 * guaranteed it.
 *
 * What it answers: **is somebody waiting on this booking?** Three things
 * make that true, and the status is only the first. A confirmed booking
 * carrying a change request the renter sent yesterday appeared on no list at
 * all, because its status is `confirmed` and nothing about a status knows
 * what is pending against it.
 *
 * Pure: no database, no clock. The pending change requests are handed in,
 * already loaded — see `RentalChangeRequestRepository::findPendingForBookings()`,
 * which exists precisely so that a page showing thirty bookings does not ask
 * thirty questions.
 */
final class BookingAttention
{
    /**
     * @param list<AttentionReason> $reasons never empty — a booking with no
     *   reason is not on the list at all
     */
    private function __construct(
        public readonly RentalBooking $booking,
        public readonly array $reasons
    ) {
    }

    /**
     * Whichever of the three apply, or null when nobody is waiting.
     *
     * @param ChangeRequest[] $pending this booking's pending change requests
     */
    public static function of(RentalBooking $booking, array $pending): ?self
    {
        $reasons = [];

        if ($booking->status->needsAttention()) {
            $reasons[] = AttentionReason::STATUS;
        }

        // A final booking is nobody's work any more, whatever is still
        // recorded against it: `RentalBookingService` refuses every request
        // left pending the moment a booking closes, and a row that survived
        // that must not resurrect a closed file on somebody's list.
        if (!$booking->status->isFinal()) {
            foreach ($pending as $request) {
                if (!$request->isPending()) {
                    continue;
                }

                $reason = $request->origin === ChangeRequestOrigin::RENTER
                    ? AttentionReason::RENTER_REQUEST
                    : AttentionReason::UNIT_PROPOSAL;

                if (!in_array($reason, $reasons, true)) {
                    $reasons[] = $reason;
                }
            }
        }

        return $reasons === [] ? null : new self($booking, $reasons);
    }

    /**
     * @param RentalBooking[] $bookings
     * @param array<int, ChangeRequest[]> $pendingByBooking keyed by booking id
     * @return list<self>
     */
    public static function from(array $bookings, array $pendingByBooking): array
    {
        $attention = [];
        foreach ($bookings as $booking) {
            $one = self::of($booking, $pendingByBooking[$booking->id] ?? []);
            if ($one !== null) {
                $attention[] = $one;
            }
        }

        return $attention;
    }

    /**
     * The same list, counted — what the « À traiter » figure shows.
     *
     * Named rather than left to `count()` at each call site, so the figure
     * and the list cannot be computed from two different sets.
     *
     * @param RentalBooking[] $bookings
     * @param array<int, ChangeRequest[]> $pendingByBooking
     */
    public static function countIn(array $bookings, array $pendingByBooking): int
    {
        return count(self::from($bookings, $pendingByBooking));
    }
}
