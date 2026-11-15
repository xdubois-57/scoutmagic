<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

use Core\Security\Role;

/**
 * A finer rule an ownership checker may add for ONE file of its owner, on
 * top of the owner-wide {@see FileOwnershipCheckerInterface::isAllowed()} —
 * for an owner whose files are not all readable by everyone who may read
 * the owner.
 *
 * The case it exists for (issue #1119): a discussion group owns the images
 * of every post, and moderation can hide a post. Its link-preview image
 * and the originals of its photos must then leave with it for everyone
 * but a moderator — while every other file of the group stays readable by
 * every member, and the address a member saw before the hiding no longer
 * serves anything.
 *
 * Optional, like {@see FileIndexingPolicyInterface} and
 * {@see ReducedRenditionPolicyInterface}: a checker that does not
 * implement it answers for every file of an owner it allows, exactly as
 * before. {@see FileAccessGuard} asks it only once the owner-wide answer
 * was yes — the reduced-rendition shortcut included, since a thumbnail of
 * hidden content is hidden content — so an implementation never repeats
 * the owner-wide rule.
 */
interface FileLevelAccessPolicyInterface
{
    /**
     * Whether the current requester may access the file $fileId of the
     * owner $ownerId, knowing they may access that owner's files. The
     * login ($userAccountId, null when there is none) is there for a rule
     * that depends on who exactly is asking, not only on which members they
     * reach — a group's moderator flag belongs to one login.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isFileAllowed(
        int $ownerId,
        int $fileId,
        Role $currentRole,
        array $linkedMemberIds,
        ?int $userAccountId
    ): bool;
}
