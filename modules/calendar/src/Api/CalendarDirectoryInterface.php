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

    /**
     * The id of the default "Animateurs" supplementary calendar, or null
     * when it has not been created yet (it is created lazily the first
     * time the calendar module renders its own pages).
     *
     * Read-only on purpose: a consumer that needs the default calendar to
     * exist must NOT get a way to create it — it publishes virtual events
     * onto it (§7.6), and a calendar nobody has ever opened is a calendar
     * nobody is looking at, so having nothing to publish onto is the
     * correct degraded answer, not an error.
     */
    public function defaultCalendarId(): ?int;
}
