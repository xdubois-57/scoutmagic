<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * One row of discussion_group_members — an explicitly invited member, or
 * a derived member whose row exists purely to carry $isModerator.
 * $memberId is the persistent Core member identity (members.id).
 */
class GroupMember
{
    public function __construct(
        public readonly int $id,
        public readonly int $groupId,
        public readonly int $memberId,
        public readonly bool $isModerator,
        public readonly ?int $invitedByMemberId,
        public readonly string $createdAt
    ) {
    }
}
