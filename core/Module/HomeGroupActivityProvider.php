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
 *
 * A SUMMARY, not a list. The homepage shows this in the same one-line
 * banner shape as Core\Module\HomeBannerProvider's own output: three
 * numbers and a link, never a card enumerating each group with its own
 * timestamp. That block belongs on the group list, which is one tap away
 * and is the page actually equipped to show it.
 */
interface HomeGroupActivityProvider
{
    /**
     * How much unseen group activity is waiting for the caller, split into
     * the three things a member reacts to differently: a new message, an
     * answer or reaction on the thread, and being named personally.
     *
     * All three are 0 for a visitor with no groups, and for an anonymous
     * one — the implementation resolves the caller from the session
     * itself, because the answer is entirely per-member and there is
     * nothing meaningful to return without one.
     *
     * @return array{posts: int, activity: int, mentions: int}
     */
    public function getUnreadActivitySummaryForCurrentUser(): array;
}
