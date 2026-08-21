<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Which status may follow which (§6.15).
 *
 * A single table, in one place, rather than an `if` per action. The reason
 * is not tidiness: a lifecycle scattered across controller actions is one
 * where "may a cancelled booking be confirmed?" has a different answer
 * depending on which button was pressed, and nobody notices until it
 * happens to a real rental.
 *
 * Two rules shape the whole table:
 *
 * - **A final state is final.** Refused, cancelled and expired release the
 *   dates, and closed is history. Reopening one would silently re-take a
 *   period that may since have been let to somebody else. The way back is a
 *   new request, which is honest about what it is.
 * - **Confirmation is not reachable from nowhere.** It is the one
 *   transition that commits the unit to a stay, so it may only follow a
 *   state where somebody actually looked at the booking.
 *
 * Cancellation is deliberately available from every live state including
 * `confirmed`: a rental that falls through falls through, and §6.17 is
 * explicit that there is no automatic scale — the manager records what was
 * refunded by hand.
 */
final class BookingTransition
{
    /**
     * @var array<string, list<BookingStatus>>
     */
    private const ALLOWED = [
        BookingStatus::RECEIVED->value => [
            BookingStatus::REVIEWING,
            BookingStatus::INFO_REQUESTED,
            BookingStatus::PROPOSED,
            BookingStatus::CONFIRMED,
            BookingStatus::REFUSED,
            BookingStatus::CANCELLED,
        ],
        BookingStatus::REVIEWING->value => [
            BookingStatus::INFO_REQUESTED,
            BookingStatus::PROPOSED,
            BookingStatus::CONFIRMED,
            BookingStatus::REFUSED,
            BookingStatus::CANCELLED,
        ],
        BookingStatus::INFO_REQUESTED->value => [
            BookingStatus::REVIEWING,
            BookingStatus::PROPOSED,
            BookingStatus::CONFIRMED,
            BookingStatus::REFUSED,
            BookingStatus::CANCELLED,
        ],
        BookingStatus::PROPOSED->value => [
            BookingStatus::REVIEWING,
            BookingStatus::INFO_REQUESTED,
            BookingStatus::CONFIRMED,
            BookingStatus::REFUSED,
            BookingStatus::CANCELLED,
            // A proposal with a deadline that lapses (§6.14).
            BookingStatus::EXPIRED,
        ],
        BookingStatus::CONFIRMED->value => [
            // Only two ways out of a confirmed booking: it happens and is
            // closed, or it falls through and is cancelled.
            BookingStatus::CLOSED,
            BookingStatus::CANCELLED,
        ],
        BookingStatus::REFUSED->value => [],
        BookingStatus::CANCELLED->value => [],
        BookingStatus::EXPIRED->value => [],
        BookingStatus::CLOSED->value => [],
    ];

    public static function isAllowed(BookingStatus $from, BookingStatus $to): bool
    {
        // No `?? []`: the table covers every case, and PHPStan proves it —
        // a fallback would only hide a status added without a row here.
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * Every status a manager may move this booking to, for rendering the
     * available buttons. Presentation only — `isAllowed()` is re-checked
     * server-side on the action, because a rendered button is never a
     * boundary (ARCHITECTURE.md §12).
     *
     * @return list<BookingStatus>
     */
    public static function allowedFrom(BookingStatus $from): array
    {
        return self::ALLOWED[$from->value];
    }

    /**
     * The French sentence explaining why a transition was refused.
     *
     * Says what the booking's state is rather than naming the rule, because
     * "cette réservation est déjà annulée" is what the manager needs to
     * know and "transition invalide" is not.
     */
    public static function refusalReason(BookingStatus $from, BookingStatus $to): string
    {
        if ($from === $to) {
            return 'Cette réservation est déjà « ' . $from->label() . ' ».';
        }

        if ($from->isFinal()) {
            return 'Cette réservation est ' . mb_strtolower($from->label())
                . " et ne peut plus changer d'état. Une nouvelle demande est nécessaire.";
        }

        return 'Une réservation « ' . $from->label() . ' » ne peut pas passer à « ' . $to->label() . ' ».';
    }
}
