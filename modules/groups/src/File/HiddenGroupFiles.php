<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\File;

use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostMediaRepository;

/**
 * Which of a group's `files` rows belong to content moderation hid (issue
 * #1119) — the question File\GroupFileOwnershipChecker asks before
 * `/files/{id}` serves one of them.
 *
 * A group owns two kinds of file:
 *
 * - **a link preview's image** (Service\PostLinkService), whose address
 *   the post's markup carries — the one a member who saw the post before
 *   it was hidden still holds;
 * - **the original of a photo** in the group's delegated album (Service\
 *   PostMediaService), never addressed by any page but owned by the group
 *   all the same. The gallery says which media it is the original of
 *   (Api\DelegatedAlbumManager::findMediaIdForOriginal()) — this module
 *   never reads the gallery's tables — and the media is hidden exactly
 *   when the gallery's own per-media rule says so
 *   (Repository\PostMediaRepository::isMediaOfHiddenContent()).
 */
class HiddenGroupFiles
{
    public function __construct(
        private PostLinkRepository $postLinkRepository,
        private PostMediaRepository $postMediaRepository,
        private DelegatedAlbumManager $delegatedAlbumManager
    ) {
    }

    public function isFileOfHiddenContent(DiscussionGroup $group, int $fileId): bool
    {
        if ($this->postLinkRepository->isImageOfHiddenPost($group->id, $fileId)) {
            return true;
        }
        if ($group->galleryAlbumId === null) {
            return false;
        }

        $mediaId = $this->delegatedAlbumManager->findMediaIdForOriginal($group->galleryAlbumId, $fileId);

        return $mediaId !== null && $this->postMediaRepository->isMediaOfHiddenContent($group->id, $mediaId);
    }
}
