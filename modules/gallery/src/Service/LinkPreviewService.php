<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Http\LinkPreview;
use Core\Http\LinkPreviewFetcher;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Repository\LinkPreviewCacheRepository;

/**
 * The only implementation of Api\LinkPreviewFetcher — see that interface's
 * docblock for why nothing outside gallery is allowed a second one.
 * Wraps OgScraperService with Repository\LinkPreviewCacheRepository's
 * metadata cache: a cache hit skips the HTML fetch/parse entirely, but the
 * image is always re-downloaded (see the cache table's own schema.sql
 * comment for why it never stores the image itself).
 */
class LinkPreviewService implements LinkPreviewFetcher
{
    public function __construct(
        private OgScraperService $ogScraperService,
        private LinkPreviewCacheRepository $cache
    ) {
    }

    public function fetch(string $url): ?LinkPreview
    {
        $hash = LinkPreviewCacheRepository::hashUrl($url);
        $cached = $this->cache->findFresh($hash);

        if ($cached !== null) {
            $title = $cached['title'];
            $description = $cached['description'];
            $imageUrl = $cached['imageUrl'];
        } else {
            try {
                $tags = $this->ogScraperService->fetch($url);
            } catch (GalleryException) {
                // Nothing at all could be resolved — the caller's own
                // fallback (a plain link) applies. Not cached: a
                // transiently-unreachable page should not be remembered
                // as "no metadata" for TTL_HOURS.
                return null;
            }

            $title = $tags['title'];
            $description = $tags['description'];
            $imageUrl = $tags['image'];
            $this->cache->store($hash, $title, $description, $imageUrl);
        }

        $imageBytes = $imageUrl !== null ? $this->ogScraperService->fetchImageBytes($imageUrl) : null;

        return new LinkPreview($title, $description, $imageBytes);
    }
}
