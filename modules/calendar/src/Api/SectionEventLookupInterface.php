<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): the events
 * that belong to a section, and to a section only.
 *
 * Introduced for `presences`, whose whole model hangs off "an evening a
 * section held" — `calendar_calendars.section_id` is what makes a calendar
 * a section's, so it is what decides whether an event opens an attendance
 * sheet. `CalendarEventLookupInterface::findEventsInWindow()` cannot answer
 * that: it deliberately folds the supplementary (section-less) calendars in
 * beside the section's own, which is right for a picker and wrong here — a
 * unit-wide "Animateurs" evening is not a section's meeting and must never
 * open a sheet.
 *
 * **Real events only.** A virtual event contributed through
 * Api\VirtualEventProviderInterface (§7.6) never appears here: it has no
 * `calendar_events.id` to hang anything off and is read-only by
 * construction, so a consumer that stores rows against an event id must not
 * be handed one.
 *
 * **It takes no viewer, and that is deliberate.** Every other lookup on
 * this module filters by `Core\Security\Role` because it answers "what may
 * this visitor SEE of the calendar". This one answers a structural
 * question — which events exist on a given section's calendar — for a
 * consumer whose own boundary is stricter and different: being an
 * animateur of that section (`Core\Member\SectionStaffAuthorizationService`),
 * which the calendar module has no way to evaluate. A consumer therefore
 * decides *before* calling that the reader may act on $sectionId; a
 * calendar's `visibility` is not that decision and would be the wrong one
 * (an animateur staffing a section still staffs it when its calendar is
 * hidden from their role).
 */
interface SectionEventLookupInterface
{
    /**
     * Every event of $sectionId's own calendar whose effective end date
     * (end_date, falling back to start_date) falls within
     * [$windowStart, $windowEnd], oldest first.
     *
     * One call per window, never one per day (§7.6's first rule): a caller
     * building a whole scout year's register asks once.
     *
     * @return list<SectionEvent>
     */
    public function findSectionEventsInWindow(
        int $sectionId,
        \DateTimeInterface $windowStart,
        \DateTimeInterface $windowEnd
    ): array;

    /**
     * One event by id, with the section its calendar belongs to. Null when
     * the event does not exist, or when it sits on a supplementary
     * calendar — which is the same answer on purpose: a consumer must not
     * be able to tell "no such event" from "an event that is not a
     * section's".
     */
    public function findSectionEvent(int $eventId): ?SectionEvent;

    /**
     * The same lookup for several ids at once, keyed by event id and
     * skipping every id that resolves to nothing. For a consumer holding a
     * set of stored event ids — an attendance history, an export — which
     * would otherwise call findSectionEvent() in a loop.
     *
     * @param list<int> $eventIds
     * @return array<int, SectionEvent>
     */
    public function findSectionEvents(array $eventIds): array;
}
