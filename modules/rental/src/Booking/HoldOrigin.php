<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Why a booking is holding its dates temporarily (module spec §6.14).
 *
 * **There is exactly one hold mechanism**, carried by the booking itself
 * with one deadline and this origin — not two parallel systems. That is what
 * gives one expiry task, one availability calculation and one set of tests.
 *
 * The two origins differ in what they *mean*, not in how they work:
 *
 * - `AUTOMATIC` is created by the request itself and is short (48 h by
 *   default). It is told to the renter as "we have 48 hours to reply", and
 *   is **not** a checklist milestone a human crossed — nobody did anything.
 *   When it lapses the request simply goes back to waiting.
 * - `MANAGER` is a deliberate option with a deadline promised to the renter,
 *   and *is* a milestone. When it lapses without confirmation the booking
 *   becomes expired.
 *
 * To the public both are indistinguishable from a confirmed booking: the
 * period is unavailable and the calendar says nothing about why.
 */
enum HoldOrigin: string
{
    case AUTOMATIC = 'automatic';
    case MANAGER = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::AUTOMATIC => 'Dates bloquées le temps de vous répondre',
            self::MANAGER => 'Option posée',
        };
    }

    /**
     * The same fact worded for a manager rather than for a renter.
     *
     * A renter is told what it means for them ("les dates sont bloquées le
     * temps de vous répondre"); a manager needs to know which of the two
     * mechanisms is running, because only one of them ends the booking when
     * it lapses.
     */
    public function managerLabel(): string
    {
        return match ($this) {
            self::AUTOMATIC => 'Dates bloquées automatiquement',
            self::MANAGER => 'Option posée',
        };
    }

    /**
     * Whether lapsing makes the booking expired (a promise not kept) rather
     * than merely returning it to the queue (nothing was ever promised).
     */
    public function expiryEndsTheBooking(): bool
    {
        return $this === self::MANAGER;
    }
}
