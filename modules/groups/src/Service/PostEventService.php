<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;

/**
 * "Ce message parle de la réunion de samedi" — the optional link between
 * a post and a calendar event.
 *
 * The whole module-to-module surface is the one nullable interface below
 * (ARCHITECTURE.md §7.5, the same shape Modules\Retro's board picker
 * uses). With calendar disabled this class is constructed with null and
 * every method answers "nothing": the composer offers no picker, a post
 * that still carries an id from when calendar WAS enabled simply renders
 * without the line, and nothing anywhere in groups fails. That is why
 * schema.sql deliberately has no foreign key on the column.
 *
 * Visibility is the calendar's to decide, not this module's: both the
 * picker and the render pass the viewer's own Role through, so a post
 * cannot become a way to read the title of an event its reader would not
 * be shown on /calendar.
 */
class PostEventService
{
    /**
     * How far around today the picker looks. Wide enough to cover "the
     * outing we just came back from" and "the camp we are preparing",
     * narrow enough that the list stays a list rather than a year.
     */
    private const WINDOW_DAYS_BEFORE = 30;
    private const WINDOW_DAYS_AFTER = 120;

    public function __construct(private ?CalendarEventLookupInterface $eventLookup = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->eventLookup !== null;
    }

    /**
     * What the composer's picker offers. Empty — and therefore the picker
     * is not rendered at all — when calendar is disabled.
     *
     * @return EventSummary[]
     */
    public function options(GroupSessionContext $context): array
    {
        if ($this->eventLookup === null) {
            return [];
        }

        $today = new \DateTimeImmutable('today');

        // Null section: a group's audience is its membership, which
        // routinely spans sections (an invitation group has no section at
        // all), so narrowing the picker to one section would hide exactly
        // the unit-wide events a group is most likely to be talking
        // about. The viewer's Role is what actually restricts the list.
        return $this->eventLookup->findEventsInWindow(
            $today->modify('-' . self::WINDOW_DAYS_BEFORE . ' days'),
            $today->modify('+' . self::WINDOW_DAYS_AFTER . ' days'),
            null,
            $context->role
        );
    }

    /**
     * Re-resolves an id a member submitted. Returns null for anything the
     * calendar will not confirm to this viewer — an unknown id, a deleted
     * event, or one on a calendar they may not read — so an id typed into
     * a form by hand cannot attach an event the member cannot see.
     */
    public function resolveSubmitted(?int $eventId, Role $viewerRole): ?int
    {
        if ($eventId === null || $eventId <= 0 || $this->eventLookup === null) {
            return null;
        }

        return $this->eventLookup->findEventById($eventId, $viewerRole)?->id;
    }

    /**
     * The events behind a feed page's posts, keyed by event id.
     *
     * One lookup per DISTINCT event id, and only for posts that carry
     * one — which on an ordinary page is zero or one. The interface has
     * no batch method and this module does not get to add one to
     * another module's public contract; the alternative, a join, is
     * exactly the coupling §7.5 exists to prevent.
     *
     * @param array<int, ?int> $eventIds
     * @return array<int, EventSummary>
     */
    public function summariesFor(array $eventIds, Role $viewerRole): array
    {
        if ($this->eventLookup === null) {
            return [];
        }

        $summaries = [];
        foreach (array_unique(array_filter($eventIds, fn(?int $id) => $id !== null && $id > 0)) as $eventId) {
            $summary = $this->eventLookup->findEventById((int) $eventId, $viewerRole);
            if ($summary !== null) {
                $summaries[$summary->id] = $summary;
            }
        }

        return $summaries;
    }
}
