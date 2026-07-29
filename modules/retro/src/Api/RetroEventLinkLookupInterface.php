<?php

declare(strict_types=1);

namespace Modules\Retro\Api;

use Core\Security\Role;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5). Mirror of
 * Modules\Calendar\Api\CalendarEventLookupInterface in the opposite
 * direction — introduced for the calendar module's event GUI/ICS export to
 * show a linked retrospective's link, audience-gated per the board's own
 * configurable link_visibility.
 */
interface RetroEventLinkLookupInterface
{
    /**
     * The linked board's public link for this event, or null when either
     * no board is linked at all, or one is linked but $viewerRole (and,
     * for the 'unit_chief' audience level, $viewerEmail) doesn't meet its
     * configured link_visibility. $viewerEmail/$scoutYearId may be null
     * when the caller has no identified viewer at all (e.g. an anonymous
     * ICS feed) — such a viewer never qualifies for any audience level.
     */
    public function findLinkedBoardLink(int $eventId, Role $viewerRole, ?string $viewerEmail, ?int $scoutYearId): ?RetroLinkSummary;

    /**
     * Whether ANY board is linked to this event, regardless of the
     * viewer or the board's link_visibility — used only to decide whether
     * the calendar event form should still offer the "create a
     * retrospective automatically" checkbox (pointless once one already
     * exists), never to gate the link's own visibility.
     */
    public function hasLinkedBoard(int $eventId): bool;
}
