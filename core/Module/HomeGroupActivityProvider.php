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
     * One compact summary of everything the caller has not seen yet in
     * their groups — the homepage renders it as a single banner-shaped
     * strip, not a per-group list (a list taller than the welcome text it
     * introduced is what this replaced).
     *
     * Returns null when there is nothing to show: for an anonymous
     * visitor, a visitor with no groups, and a member whose groups have
     * all been read — the banner is hidden at zero, never rendered empty.
     * The implementation resolves the caller from the session itself,
     * because the answer is entirely per-member. It never counts a group
     * the caller is not a member of, which is the same rule every other
     * route in the groups module enforces.
     *
     * `single_group` is set when exactly one group has unread activity,
     * so the banner's action can open it directly instead of the list.
     * The three counters may all be 0 while `group_count` is not — a
     * group can be "active" through an edit that produced nothing
     * countable — and the banner then shows without the counter line.
     *
     * @return null|array{
     *     group_count: int,
     *     single_group: null|array{id: int, name: string},
     *     new_posts: int,
     *     new_replies_or_reactions: int,
     *     new_mentions: int
     * }
     */
    public function getHomeActivitySummaryForCurrentUser(): ?array;
}
