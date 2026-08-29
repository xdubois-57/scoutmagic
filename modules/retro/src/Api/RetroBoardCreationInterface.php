<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): create a
 * retrospective board for a calendar event, on this module's own terms.
 *
 * Introduced for the calendar module's auto-create-retro flag: the
 * calendar decides WHEN a board should exist (the flag on the event, the
 * scheduling of the creation task), and this module decides WHAT a board
 * is — its title format, its defaults, its capability token, its
 * auto-close and the scheduling of that close, its journal trail, and the
 * rule that an event carries at most one board. Before this contract
 * existed, the calendar module reached into `Repository\BoardRepository`
 * and re-implemented all of the above by hand.
 *
 * The caller passes display facts it alone knows (the event's title and
 * its calendar's human label); everything normative about the board stays
 * on this side of the line.
 */
interface RetroBoardCreationInterface
{
    /**
     * Create the board for $calendarEventId, unless one is already linked
     * to it — a chief may have created or linked one by hand between the
     * scheduling and the run, and one event never carries two boards.
     * Returns the new board's id, or null when a linked board already
     * existed and nothing was created.
     *
     * $eventStartDate and $eventEndDate are `Y-m-d`, the end inclusive
     * and null for a single-day event. The board is dated the event's
     * last day: a retrospective belongs to when the thing ended.
     */
    public function createBoardForEvent(
        int $calendarEventId,
        string $eventTitle,
        string $calendarLabel,
        string $eventStartDate,
        ?string $eventEndDate
    ): ?int;
}
