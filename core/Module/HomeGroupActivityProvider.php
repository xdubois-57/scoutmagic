<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to surface "there is something new
 * in your groups" on the homepage (Core\Http\Controller\PageController::
 * home()) — without core depending on the module directly. Same precedent
 * as Core\Module\HomeNewsProvider / HomeBannerProvider (ARCHITECTURE.md
 * §7.4).
 *
 * This exists because the notification mechanism alone does not reach
 * everyone: it needs either a granted push permission or a member who
 * thinks to look at the bell. The homepage is the one surface every
 * visitor passes through, so it is where a member who has neither still
 * finds out that a group has been active.
 */
interface HomeGroupActivityProvider
{
    /**
     * The caller's own groups with unread activity, most recently active
     * first, capped at $limit.
     *
     * Returns [] for a visitor with no groups, and for an anonymous one —
     * the implementation resolves the caller from the session itself,
     * because the answer is entirely per-member and there is nothing
     * meaningful to return without one. Never returns a group the caller
     * is not a member of, which is the same rule every other route in the
     * groups module enforces.
     *
     * @return array<int, array{id: int, name: string, last_activity_at: string}>
     */
    public function getUnreadGroupsForCurrentUser(int $limit): array;
}
