<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * Public contract for a module that wants to show a preview card for a
 * member-supplied link — gallery's own Open Graph scraper
 * (Service\OgScraperService, SECURITY.md §17) is the only piece of code in
 * this codebase allowed to make an outbound HTTP request to a
 * user-supplied URL; this interface is the one way anything outside
 * gallery reaches that capability (ARCHITECTURE.md §7.5, same shape as
 * Api\DelegatedAlbumManager). No consuming module may call
 * Service\OgScraperService directly, and no consuming module may implement
 * a second scraper of its own.
 *
 * Deliberately narrow: this only resolves metadata for a URL. It never
 * stores anything and never enforces authorisation or rate limits of its
 * own — a caller (Modules\Groups\Service\PostLinkService, first consumer)
 * decides whether the current session may post a link at all, whether it
 * has already fetched too many this window, and how/where to persist the
 * result; this interface is stateless from the caller's point of view
 * beyond gallery's own internal, non-authoritative metadata cache (see
 * Repository\LinkPreviewCacheRepository).
 */
interface LinkPreviewFetcher
{
    /**
     * Never throws: an invalid URL, an unreachable page, one with no Open
     * Graph tags at all, or a failed image download all degrade to a
     * result the caller can still use — at minimum, a plain link. Returns
     * null only when nothing at all could be resolved (the URL itself was
     * rejected, or the fetch failed outright) — the caller falls back to a
     * plain link with no title/description/image in that case too.
     */
    public function fetch(string $url): ?LinkPreview;
}
