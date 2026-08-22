<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Gallery;

use Modules\Gallery\Api\DelegatedAlbumDescriber;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\GroupRepository;

/**
 * Tells gallery's administration pages what a group's delegated album is
 * called — "Photos du groupe Chefs d'unité" rather than
 * "discussion_group #7".
 *
 * The read-only twin of Gallery\GroupDelegatedAlbumAccessChecker beside it,
 * keyed by the same owner_type through the same File\
 * GroupFileOwnershipChecker, so the two can never end up claiming different
 * types. It answers a NAME and nothing else: who may see the album is the
 * checker's question, and an administrator reading a list of albums to move
 * between storage locations is not thereby allowed to open one.
 */
class GroupDelegatedAlbumDescriber implements DelegatedAlbumDescriber
{
    public function __construct(
        private GroupFileOwnershipChecker $fileChecker,
        private GroupRepository $groupRepository
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $this->fileChecker->supports($ownerType);
    }

    public function describe(int $ownerId): ?string
    {
        $group = $this->groupRepository->findById($ownerId);

        return $group !== null ? 'Groupe ' . $group->name : null;
    }
}
