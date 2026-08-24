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
use Core\Http\LinkPreviewFetcher;
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
 * Every fetch goes through Core\Http\LinkPreviewFetcher — never
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
     * The live card groups.js shows while composing, before anything is
     * saved (module spec: no manual "Lien" field — the first URL typed in
     * the body is detected automatically, and this is what shows a
     * preview for it as-you-type). Nothing here is written: no
     * discussion_group_post_links row, no stored files row — only
     * attach() (called again, independently, once the post is actually
     * submitted — Controller\PostController::create()) persists anything.
     * The image travels back as a data: URI instead of a stored file, so
     * there is nothing to clean up if the draft is abandoned, the URL is
     * edited again, or the post is never submitted at all.
     *
     * Same throttle bucket as attach() — SECURITY.md §17's protection is
     * about the total outbound-fetch rate, not which caller triggered it,
     * so a live preview and the post's own final fetch both draw from the
     * same per-member allowance (module spec's Q4 answer: groups.js
     * debounces this on pause/blur/paste specifically so a live preview
     * cannot race a member's own submit for the window's last slot).
     *
     * @return array{url: string, title: ?string, description: ?string, image_data_uri: ?string}
     */
    public function livePreview(string $url, int $memberId): array
    {
        if (!$this->throttleService->allowFetch($memberId)) {
            return ['url' => $url, 'title' => null, 'description' => null, 'image_data_uri' => null];
        }

        $preview = $this->linkPreviewFetcher->fetch($url);

        return [
            'url' => $url,
            'title' => $preview?->title,
            'description' => $preview?->description,
            'image_data_uri' => $preview?->imageBytes !== null ? self::dataUri($preview->imageBytes) : null,
        ];
    }

    /**
     * The first http(s) URL substring in free text, already validated
     * (isValidUrl()), or null — the automatic detection this whole
     * feature replaces the old manual "Lien" field with: reads the way a
     * person would pick "the link" out of a sentence, trimming trailing
     * punctuation a SENTENCE carries but a URL never does (a closing
     * parenthesis, a full stop, a comma) the same way most chat apps
     * linkify text.
     */
    public static function firstUrlIn(string $text): ?string
    {
        if (!preg_match('/https?:\/\/[^\s<>"\']+/u', $text, $matches)) {
            return null;
        }

        $url = rtrim($matches[0], ".,;:!?)]}'\"");

        return self::isValidUrl($url) ? $url : null;
    }

    /**
     * Removes $url from $text, once a preview card is going to represent
     * it instead — Facebook-style: the link never appears twice, once as
     * plain text and once as the card underneath it. Collapses the gap
     * left behind (trailing spaces on a line, a run of blank lines) rather
     * than leaving whitespace artifacts where the URL used to sit.
     */
    public static function stripUrl(string $text, string $url): string
    {
        $stripped = str_replace($url, '', $text);
        $stripped = preg_replace('/[ \t]{2,}/', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/[ \t]*\n[ \t]*/', "\n", $stripped) ?? $stripped;
        $stripped = preg_replace('/\n{3,}/', "\n\n", $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * A data: URI for a live preview's image — never stored, so the MIME
     * type has to be sniffed from the bytes themselves (Core\File\
     * UploadHandler's own finfo-based check, applied here to an in-memory
     * buffer instead of an uploaded file) rather than trusted from
     * whatever content-type the remote server happened to send. Returns
     * null for anything outside the same allow-list a stored preview
     * image is already held to.
     */
    private static function dataUri(string $bytes): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mime === false || !in_array($mime, self::IMAGE_ALLOWED_MIMES, true)) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
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
