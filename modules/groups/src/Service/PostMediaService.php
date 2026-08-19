<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Service\GalleryException;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\ReplyRepository;

/**
 * Attaches gallery media to a post through Api\DelegatedAlbumManager —
 * the only entry point this module is allowed to call into gallery
 * through (ARCHITECTURE.md §7.5); nothing here touches a
 * Modules\Gallery\Service or Repository class directly. Every group's
 * post media lives in one delegated album per group (owner_type
 * File\GroupFileOwnershipChecker::OWNER_TYPE, owner_id = the group's id),
 * created lazily on the group's first media post and cached on
 * discussion_groups.gallery_album_id.
 *
 * Authorisation (may this session post/delete here at all) is entirely
 * the Controller's job, exactly like Service\PostService — nothing here
 * is a security boundary on its own; every method trusts the caller
 * already decided the action is allowed. Read access to the resulting
 * media is a separate concern, enforced by Gallery\
 * GroupDelegatedAlbumAccessChecker on every /gallery/media/{id}/{size}
 * request, not by anything in this class.
 */
class PostMediaService
{
    /**
     * Server-side ceiling — the composer's own counter is a convenience,
     * never the limit. A post over this is rejected whole by
     * Controller\PostController::create(), never truncated to the first
     * four (module spec).
     */
    public const MAX_MEDIA_PER_POST = 4;

    public function __construct(
        private DelegatedAlbumManager $delegatedAlbumManager,
        private PostMediaRepository $postMediaRepository,
        private GroupRepository $groupRepository,
        private ReplyRepository $replyRepository
    ) {
    }

    /**
     * The group's delegated album, created on first use. gallery's own
     * ensureAlbum() is safe under concurrency (a UNIQUE key on
     * (owner_type, owner_id), gallery's schema.sql) — two members posting
     * the group's first media at the same moment both land here, both
     * call ensureAlbum(), and both get back the same album id; the
     * second write to gallery_album_id below is a harmless no-op onto
     * the same value.
     *
     * $createdByAccountId is a user_accounts.id, not a members.id —
     * gallery_albums.created_by has a FK to user_accounts (schema.sql),
     * the same "human who actually acted" identity Repository\Album's
     * own createdBy already carries for an ordinary album.
     */
    public function ensureAlbumId(DiscussionGroup $group, int $createdByAccountId): int
    {
        if ($group->galleryAlbumId !== null) {
            return $group->galleryAlbumId;
        }

        $album = $this->delegatedAlbumManager->ensureAlbum(
            GroupFileOwnershipChecker::OWNER_TYPE,
            $group->id,
            $group->name,
            date('Y-m-d'),
            $createdByAccountId
        );
        $this->groupRepository->setGalleryAlbumId($group->id, $album->id);

        return $album->id;
    }

    /**
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int, type: string}> $uploadedFiles
     * @return int[] the gallery media ids attached, in attachment order
     * @throws GalleryException on the first file that fails — the caller
     *         (Controller\PostController::create()) rolls back the whole
     *         post via deleteAllForPost() rather than leaving a partial
     *         attachment (module spec: "reject the whole post rather than
     *         silently truncating").
     */
    public function addMedia(DiscussionGroup $group, int $postId, array $uploadedFiles, int $accountId): array
    {
        $albumId = $this->ensureAlbumId($group, $accountId);

        $mediaIds = [];
        foreach (array_values($uploadedFiles) as $index => $file) {
            $media = $this->delegatedAlbumManager->addMedia($albumId, $file, $accountId);
            $this->postMediaRepository->attach($postId, $media->id, $index);
            $mediaIds[] = $media->id;
        }

        return $mediaIds;
    }

    /**
     * Stores ONE media in the group's delegated album and returns its
     * gallery media id, without attaching it to any post — how a reply's
     * single optional image gets in (Service\ReplyService). Deliberately
     * the same album, the same Api\DelegatedAlbumManager and therefore the
     * same ownership checker as post media: a reply image is group media
     * like any other and shows up in "Galerie du groupe" for free, which a
     * second storage path would have broken (module spec).
     *
     * @param array{name: string, tmp_name: string, error: int, size: int, type: string} $uploadedFile
     * @throws GalleryException on an invalid file, a disabled type or over-quota
     */
    public function addOne(DiscussionGroup $group, array $uploadedFile, int $accountId): int
    {
        $albumId = $this->ensureAlbumId($group, $accountId);

        return $this->delegatedAlbumManager->addMedia($albumId, $uploadedFile, $accountId)->id;
    }

    /**
     * Removes one gallery media (row and stored object) from the group's
     * album — the counterpart of addOne(), used when a reply carrying an
     * image is deleted, and when a post's deletion sweeps its replies'
     * images (Service\ReplyService::deleteAllMediaForPost()).
     *
     * Re-reads the album id fresh from the repository for the same reason
     * deleteAllForPost() does, and never throws for the same reason: a
     * media row already gone must not block the deletion that triggered
     * this.
     */
    public function deleteOne(DiscussionGroup $group, int $mediaId): void
    {
        $albumId = $this->groupRepository->findById($group->id)?->galleryAlbumId;
        if ($albumId === null) {
            return;
        }

        try {
            $this->delegatedAlbumManager->deleteMedia($albumId, $mediaId);
        } catch (GalleryException) {
            // Already gone (or the album vanished) — nothing left to clean
            // up either way.
        }
    }

    /**
     * Removes every gallery media (row and stored object) already
     * attached to a post — used both to roll back a post rejected
     * partway through addMedia(), and on a genuine post deletion
     * (Controller\PostController::delete(), called before
     * Service\PostService::delete() removes the post row itself, since
     * that cascades away this module's own join rows and the media ids
     * would no longer be findable afterward).
     *
     * Re-reads the group's own gallery_album_id fresh from the
     * repository rather than trusting $group->galleryAlbumId: when this
     * runs as addMedia()'s own rollback on the group's very first media
     * post, the album was only just created and persisted mid-request —
     * the immutable $group instance the caller is still holding was
     * fetched before that write and would otherwise read as null,
     * skipping cleanup for exactly the media that needs it.
     *
     * Never throws: a media row already gone (deleteMedia() itself
     * failing) must not block the post's own removal.
     */
    public function deleteAllForPost(DiscussionGroup $group, int $postId): void
    {
        $albumId = $this->groupRepository->findById($group->id)?->galleryAlbumId;
        if ($albumId === null) {
            return;
        }

        foreach ($this->postMediaRepository->findMediaIdsForPost($postId) as $mediaId) {
            try {
                $this->delegatedAlbumManager->deleteMedia($albumId, $mediaId);
            } catch (GalleryException) {
                // Already gone (or the album vanished) — nothing left to
                // clean up either way.
            }
        }
    }

    /**
     * Every DelegatedMedia the group's album currently holds, keyed by
     * id — fetched once per feed page (Service\GroupFeedService) and
     * reused to decorate every post on it, rather than once per post.
     *
     * @return array<int, DelegatedMedia>
     */
    public function albumMediaById(DiscussionGroup $group): array
    {
        if ($group->galleryAlbumId === null) {
            return [];
        }

        $byId = [];
        foreach ($this->delegatedAlbumManager->listMedia($group->galleryAlbumId) as $media) {
            $byId[$media->id] = $media;
        }

        return $byId;
    }

    /**
     * @param int[] $postIds
     * @param array<int, DelegatedMedia> $mediaById the return of albumMediaById()
     * @return array<int, DelegatedMedia[]> postId => its media, in
     *         attachment order. A media id present in the join table but
     *         absent from $mediaById (a race with a concurrent delete) is
     *         silently skipped rather than breaking the whole post's render.
     */
    public function mediaForPosts(array $postIds, array $mediaById): array
    {
        $result = [];
        foreach ($this->postMediaRepository->findMediaIdsForPosts($postIds) as $postId => $mediaIds) {
            foreach ($mediaIds as $mediaId) {
                if (isset($mediaById[$mediaId])) {
                    $result[$postId][] = $mediaById[$mediaId];
                }
            }
        }

        return $result;
    }

    /**
     * Whether the composer should offer video at all — Controller\
     * GroupController::show() passes this to the template so the option
     * is hidden proactively rather than only discovered from a rejected
     * upload. addMedia() still refuses a video server-side regardless
     * (Api\DelegatedAlbumManager::videoUploadAllowed()'s own docblock) —
     * this is UI-only, never the enforcement.
     */
    public function videoUploadAllowed(): bool
    {
        return $this->delegatedAlbumManager->videoUploadAllowed();
    }

    /**
     * "Galerie du groupe" (Controller\GroupController::gallery()): every
     * media of the group's posts, newest first. listMedia() returns them
     * in the album's own display order (oldest first, same as gallery's
     * own grid) — this re-sorts by id (monotonic, always unique) for this
     * page's own newest-first contract.
     *
     * @return DelegatedMedia[]
     */
    public function groupGalleryMedia(DiscussionGroup $group, bool $includeHidden = false): array
    {
        if ($group->galleryAlbumId === null) {
            return [];
        }

        $media = $this->delegatedAlbumManager->listMedia($group->galleryAlbumId);
        usort($media, fn(DelegatedMedia $a, DelegatedMedia $b) => $b->id <=> $a->id);

        if ($includeHidden) {
            return $media;
        }

        // A report-hidden post or reply must disappear from the gallery
        // too, not just from the feed — otherwise the exact photo that
        // got the post hidden stays one click away on another page. The
        // album itself knows nothing about this module's moderation
        // state, so the exclusion is computed here from the join tables
        // and applied to the listing.
        $hiddenIds = array_merge(
            $this->postMediaRepository->findMediaIdsForHiddenPostsInGroup($group->id),
            $this->replyRepository->findMediaIdsForHiddenRepliesInGroup($group->id)
        );
        if ($hiddenIds === []) {
            return $media;
        }

        $hidden = array_flip($hiddenIds);

        return array_values(array_filter($media, fn(DelegatedMedia $m) => !isset($hidden[$m->id])));
    }
}
