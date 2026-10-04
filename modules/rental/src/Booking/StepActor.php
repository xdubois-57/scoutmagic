<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Who has to act for a step of the journey to be done (#708, IT-12).
 *
 * What « À traiter » rests on: a booking whose next step is the unit's is
 * work waiting on the unit; one whose next step is the renter's is only the
 * unit's business once the renter is late. Every key `BookingMilestones`
 * produces is classified explicitly — `BookingMilestones::ACTORS`, checked
 * by a test — never by a default that would quietly put a new step on the
 * wrong side.
 */
enum StepActor: string
{
    case UNIT = 'unit';
    case RENTER = 'renter';
}
