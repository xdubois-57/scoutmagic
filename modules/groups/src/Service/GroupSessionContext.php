<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Security\Role;

/**
 * The already-resolved session facts GroupAccessService decides on. Built
 * by a Controller from AuthSession/MemberService and passed in as a plain
 * value object, so no service in this module ever touches $_SESSION
 * (AGENTS.md) and no personal data reaches the access layer: the profile
 * check arrives as a boolean, never as the account's actual names.
 */
final class GroupSessionContext
{
    /**
     * @param int[] $linkedMemberIds Persistent members.id values the account is linked
     *     to for the effective scout year — the same identity
     *     Core\File\FileAccessGuard owner-scopes on.
     */
    public function __construct(
        public readonly ?int $userAccountId,
        public readonly Role $role,
        public readonly array $linkedMemberIds,
        public readonly int $effectiveScoutYearId,
        public readonly bool $hasCompleteProfile,
        // Trailing and defaulted so every existing construction of this
        // value object keeps working. Display only — how a group tied to
        // the current year is NAMED (Support\GroupLabel); no access
        // decision reads it, they all compare the id above.
        public readonly string $effectiveScoutYearLabel = ''
    ) {
    }

    /**
     * A site admin is an implicit moderator of every group (module spec) —
     * the one deliberate bypass in this module, and it is never widened to
     * chief: a chief who is not a member of a group does not see it.
     */
    public function isSiteAdmin(): bool
    {
        return $this->role->hasAccess(Role::ADMIN);
    }
}
