<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * One discussion group this member belongs to, as the admin member page
 * draws it.
 *
 * Membership only — never a post, never a reply, never a count of
 * either. What people write to each other is not a fact about a member
 * to be listed on a staff page; that a chef d'unité can tell who is
 * reachable in which group is.
 */
final class MemberDiscussionGroupView
{
    /**
     * @param string $name        the group's name
     * @param string $path        site-absolute path of the group's page
     * @param bool $isModerator   whether they run it, not merely belong to it
     * @param bool $isClosed      a closed group is still part of the journey,
     *        and saying so is what stops it reading as current
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $isModerator,
        public readonly bool $isClosed,
    ) {
    }
}
