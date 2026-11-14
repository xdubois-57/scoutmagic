<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

use Core\Security\Role;

/**
 * A finer rule a DelegatedAlbumAccessChecker may add for ONE media of its
 * album, on top of the album-wide one — for an owner whose album holds
 * media that some of the album's readers must not see.
 *
 * The case it exists for (issue #1119): a discussion group's album holds
 * the images of every post and reply, and moderation can hide a post. Its
 * images must then leave with it for everyone but a moderator, while the
 * rest of the album stays readable by every member.
 *
 * Optional: a checker that does not implement it leaves every media of
 * an album it allows readable, as before. Consulted by Service\
 * DelegatedAlbumAccessRegistry::isMediaAllowed() only AFTER the album-wide
 * isAllowed() has passed, so an implementation never has to repeat it.
 */
interface DelegatedMediaAccessChecker
{
    /**
     * Whether the current requester may view the media $mediaId of the
     * delegated album whose owner_id is $ownerId — knowing they may read
     * the album itself. $userAccountId is the identified login (null when
     * there is none), for a rule that depends on who exactly is asking,
     * not only on which members they reach.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function isMediaAllowed(
        int $ownerId,
        int $mediaId,
        Role $currentRole,
        array $linkedMemberIds,
        ?int $userAccountId
    ): bool;
}
