<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): « this
 * evening no longer exists, forget what you recorded about it ».
 *
 * `presences_records` carries no foreign key into `calendar_events` —
 * a module never constrains another module's table (schema.sql says so on
 * the column itself) — so nothing at the database level erases a sheet
 * when its evening is deleted. Without this call the states and the
 * encrypted comments of a deleted evening would sit in the table for
 * ever: unreachable, since every screen starts from an event that no
 * longer exists, and therefore never re-read and never erased. Personal
 * data outliving the thing it describes, with no way left to notice it.
 *
 * The mirror of `CalendarRetroAutoCreateService::cancelAutoCreateForEvent()`,
 * called one line above it in `CalendarEventService::deleteEvent()`, and
 * optional in exactly the same way: with `presences` disabled there is
 * nothing to forget and the calendar deletes as it always did.
 */
interface PresenceEventCleanupInterface
{
    /**
     * Erase every trace of one evening: its recorded states, its
     * comments, and the short code its sheet was reached by.
     *
     * Called before the event row itself goes. Safe on an evening nobody
     * ever pointed — deleting nothing is the ordinary case.
     */
    public function forgetEvent(int $eventId): void;
}
