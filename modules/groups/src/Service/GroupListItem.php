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
     */
    public function __construct(
        public readonly DiscussionGroup $group,
        public readonly bool $isModerator,
        public readonly bool $isArchived,
        public readonly array $sectionIds
    ) {
    }
}
