<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;

/**
 * One row of the group list, with everything the card needs already
 * resolved — so the template never asks a service a question per group.
 */
final class GroupListItem
{
    /**
     * @param bool $isArchived Service\GroupArchiveRule::isArchived() —
     *        by hand, for inactivity, or because its scout year is over
     * @param int[] $sectionIds
     * @param bool $hasUnread whether somebody else has published a visible
     *        message or reply in the group since this account last opened
     *        it (issue #1349) — never a reaction or a reopening, which only
     *        move the activity clock. A group never opened is measured from
     *        its creation, so an empty one is never "new"; an archived
     *        group and a group the account only reads as a site admin
     *        never carry it (Service\GroupListService::hasUnread()).
     * @param int $memberCount how many people are in the group, invited
     *        and section-derived taken together and counted once. Not the
     *        number of rows in discussion_group_members: on a section
     *        group that table is nearly empty and the real membership
     *        comes from the sections, so the row count would read as « 1
     *        membre » for a group of forty.
     */
    public function __construct(
        public readonly DiscussionGroup $group,
        public readonly bool $isModerator,
        public readonly bool $isArchived,
        public readonly array $sectionIds,
        public readonly bool $hasUnread = false,
        public readonly int $memberCount = 0
    ) {
    }
}
