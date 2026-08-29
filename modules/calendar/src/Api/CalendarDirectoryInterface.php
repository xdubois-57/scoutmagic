<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public READ contract for consuming modules (ARCHITECTURE.md §7.5):
 * which calendars exist to be pointed at. Introduced for the rental
 * module's per-asset "publish occupancy onto…" picker, generic to any
 * future "choose a calendar" integration. Read-only by design — a
 * module that wants to PUT something on a calendar contributes virtual
 * events through Api\VirtualEventProviderInterface (§7.6), never writes.
 */
interface CalendarDirectoryInterface
{
    /**
     * Every calendar an administrator may select as a target — the
     * sections' own calendars (labelled by section) and the
     * supplementary ones.
     *
     * @return list<SelectableCalendar>
     */
    public function selectableCalendars(): array;
}
