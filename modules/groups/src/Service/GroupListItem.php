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
     * @param int[] $sectionIds
     * @param bool $hasUnread whether the group has been active since this
     *        account last opened it. A group never opened at all counts as
     *        unread only when it has genuinely had activity — otherwise a
     *        brand-new empty group would announce itself as "new" forever.
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
