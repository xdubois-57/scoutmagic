<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * The only implementation of Core\Http\LinkPreviewFetcher — see that
 * interface's docblock for why nothing else is allowed a second one.
 * Wraps UrlMetadataFetcher with LinkPreviewCacheRepository's metadata
 * cache: a cache hit skips the HTML fetch/parse entirely, but the image is
 * always re-downloaded (see the cache table's own schema comment, in
 * schema/core.sql, for why it never stores the image itself).
 *
 * Core's, not the gallery's, since issue #840: the rich-text editors ask
 * it for a link's text, and an editor every unit has cannot depend on a
 * module a unit may switch off. It is built unconditionally in
 * public/index.php, so the groups and camps modules get their previews
 * whether or not the gallery is enabled.
 */
class LinkPreviewService implements LinkPreviewFetcher
{
    public function __construct(
        private UrlMetadataFetcher $fetcher,
        private LinkPreviewCacheRepository $cache
    ) {
    }

    public function fetch(string $url): ?LinkPreview
    {
        $metadata = $this->metadata($url);
        if ($metadata === null) {
            return null;
        }

        $imageBytes = $metadata->imageUrl !== null ? $this->fetcher->fetchImageBytes($metadata->imageUrl) : null;

        return new LinkPreview($metadata->ogTitle, $metadata->description, $imageBytes);
    }

    /**
     * What the page at $url says about itself, cached — and nothing
     * downloaded beyond the page: a caller that only needs a title (the
     * link dialog's « baguette magique ») must not make the server fetch
     * an image nobody will store. Null when the page could not be read.
     */
    public function metadata(string $url): ?UrlMetadata
    {
        $hash = LinkPreviewCacheRepository::hashUrl($url);
        $cached = $this->cache->findFresh($hash);
        if ($cached !== null) {
            return $cached;
        }

        $metadata = $this->fetcher->fetch($url);
        if ($metadata === null) {
            // Nothing at all could be resolved — the caller's own fallback
            // (a plain link) applies. Not cached: a transiently-unreachable
            // page should not be remembered as "no metadata" for a day.
            return null;
        }

        $this->cache->store($hash, $metadata);

        return $metadata;
    }
}
