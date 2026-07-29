<?php

declare(strict_types=1);

namespace Modules\Calendar\Api;

use Core\Security\Role;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5). Introduced
 * for the retro module's board-creation event picker, but generic to any
 * future "pick an event" integration.
 */
interface CalendarEventLookupInterface
{
    /**
     * Events whose effective end date (end_date, falling back to
     * start_date for single-day events) falls within [$windowStart,
     * $windowEnd], on calendars visible to $viewerRole. When $sectionId is
     * given, only that section's own calendar plus every supplementary
     * (section-less) calendar are included — an admin passing null sees
     * every section's calendar too.
     *
     * @return EventSummary[]
     */
    public function findEventsInWindow(
        \DateTimeInterface $windowStart,
        \DateTimeInterface $windowEnd,
        ?int $sectionId,
        Role $viewerRole
    ): array;

    /**
     * A single event by id, regardless of window — used to authoritatively
     * re-resolve an event's end date server-side (e.g. when a consumer
     * must never trust a client-submitted date alongside a linked event
     * id). Null when the event doesn't exist or isn't visible to
     * $viewerRole.
     */
    public function findEventById(int $eventId, Role $viewerRole): ?EventSummary;
}
