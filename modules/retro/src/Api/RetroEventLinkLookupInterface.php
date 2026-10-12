<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Api;

use Core\Security\Role;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5). Mirror of
 * Modules\Calendar\Api\CalendarEventLookupInterface in the opposite
 * direction — introduced for the calendar module's event GUI/ICS export to
 * show a linked retrospective's link, to the readers the board's access
 * mode lets in (Modules\Retro\Service\BoardAccess, issue #833).
 */
interface RetroEventLinkLookupInterface
{
    /**
     * The linked board's public link for this event, or null when no board
     * is linked, when the linked board is closed or archived, when it is a
     * public board — whose link is never published — or when $viewerRole
     * could not open it. A reader with no identity at all (Role::PUBLIC, an
     * anonymous page or feed) never qualifies.
     *
     * $viewerEmail and $scoutYearId are kept for callers' sake and no
     * longer read: no access mode depends on a section any more.
     */
    public function findLinkedBoardLink(
        int $eventId,
        Role $viewerRole,
        ?string $viewerEmail,
        ?int $scoutYearId
    ): ?RetroLinkSummary;

    /**
     * Whether ANY board is linked to this event, regardless of the
     * viewer, the board's status or its access mode — used only to decide whether
     * the calendar event form should still offer the "create a
     * retrospective automatically" checkbox (pointless once one already
     * exists), never to gate the link's own visibility.
     */
    public function hasLinkedBoard(int $eventId): bool;
}
