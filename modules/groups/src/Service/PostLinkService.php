<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Modules\Gallery\Api\LinkPreviewFetcher;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\PostLinkRepository;

/**
 * Attaches an optional single link preview to a post — the module's other
 * media path, alongside Service\PostMediaService, but a different shape:
 * at most one per post (module spec), and the preview image is this
 * module's OWN files row (owner_type File\GroupFileOwnershipChecker::
 * OWNER_TYPE) rather than a gallery_media row inside the group's
 * delegated album — see discussion_group_post_links' own schema.sql
 * comment for why.
 *
 * Every fetch goes through Modules\Gallery\Api\LinkPreviewFetcher — never
 * a scraper of this module's own (module spec: "no second scraper";
 * SECURITY.md §17) — and NOTHING here is a reason to reject a post: a
 * throttled member, an unreachable page, one with no Open Graph tags, or
 * a failed image download all still produce a saved post, just with a
 * plain link and nothing else resolved (module spec: "degrade to a plain
 * link on any failure"). The one thing that DOES reject the post outright
 * is a URL that fails isValidUrl() — Controller\PostController checks
 * that before this class is ever called, exactly like the media-count
 * ceiling is checked before Service\PostMediaService::addMedia().
 */
class PostLinkService
{
    private const IMAGE_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private LinkPreviewFetcher $linkPreviewFetcher,
        private LinkFetchThrottleService $throttleService,
        private PostLinkRepository $postLinkRepository,
        private UploadHandler $uploadHandler,
        private FileRepository $fileRepository,
        private string $storagePath
    ) {
    }

    /**
     * Rejects anything that is not a well-formed http(s) URL — checked
     * BEFORE anything is stored, not merely before fetching a preview for
     * it: a post's link is rendered as a real `href`, and Twig's
     * autoescaping (which only encodes HTML-special characters) does not
     * by itself stop a stored `javascript:` URI from being clickable.
     * Restricting the scheme here, once, is what makes every later render
     * of `link.url` safe to escape and nothing more.
     */
    public static function isValidUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * $url must already have passed isValidUrl() — see this class's own
     * docblock. $memberId identifies the throttle bucket (module spec:
     * "per member" — a best-effort anti-abuse measure, not a hard
     * boundary, same posture as Modules\Retro\Service\RateLimitService's
     * own session-based one); $accountId is who the stored files row's
     * created_by records, matching every other upload in this module
     * (Service\PostMediaService::addMedia()'s own $accountId).
     */
    public function attach(DiscussionGroup $group, int $postId, string $url, int $memberId, int $accountId): void
    {
        $preview = $this->throttleService->allowFetch($memberId) ? $this->linkPreviewFetcher->fetch($url) : null;

        $imageFileId = null;
        if ($preview !== null && $preview->imageBytes !== null) {
            $imageFileId = $this->storeImage($group, $preview->imageBytes, $accountId);
        }

        $this->postLinkRepository->attach($postId, $url, $preview?->title, $preview?->description, $imageFileId);
    }

    /**
     * Deletes the post's link row and its cached image (row + stored
     * object), if any — called by Controller\PostController::delete()
     * BEFORE the post row itself is removed, exactly like
     * Service\PostMediaService::deleteAllForPost(), and for the same
     * reason: the post's own row is what discussion_group_post_links
     * cascades from, so the image_file_id must be read out while it is
     * still reachable. A post with no link is a silent no-op.
     */
    public function deleteForPost(int $postId): void
    {
        $link = $this->postLinkRepository->findForPost($postId);
        if ($link === null || $link->imageFileId === null) {
            return;
        }

        $file = $this->fileRepository->findById($link->imageFileId);
        if ($file !== null) {
            @unlink($this->storagePath . '/' . $file->relativePath);
            $this->fileRepository->delete($link->imageFileId);
        }
    }

    private function storeImage(DiscussionGroup $group, string $imageBytes, int $accountId): ?int
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'groups_link_preview_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            file_put_contents($tmpPath, $imageBytes);

            return $this->uploadHandler->handle(
                ['tmp_name' => $tmpPath, 'size' => strlen($imageBytes), 'error' => UPLOAD_ERR_OK, 'name' => 'link_preview'],
                "groups/{$group->id}/links",
                self::IMAGE_ALLOWED_MIMES,
                self::IMAGE_MAX_BYTES,
                'identified',
                'groups',
                $accountId,
                GroupFileOwnershipChecker::OWNER_TYPE,
                $group->id
            );
        } catch (UploadException) {
            // Best-effort, same philosophy as gallery's own
            // AlbumService::cacheOgImage() — an unsupported/oversized
            // image just leaves the post with a title/description and no
            // image, never blocks saving it.
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }
}
