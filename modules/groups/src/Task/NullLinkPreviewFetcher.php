<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Task;

use Modules\Gallery\Api\LinkPreview;
use Modules\Gallery\Api\LinkPreviewFetcher;

/**
 * A fetcher that fetches nothing, for the retention purge.
 *
 * Service\PostLinkService needs one to be constructed, but the purge only
 * ever calls deleteForPost() — a path that reads a stored row and removes
 * a stored file, and never goes near the network. Handing the real
 * scraper to a background task would mean a nightly purge holding a live
 * SSRF-hardened HTTP client it has no reason to own; returning null here
 * is the honest shape of "this collaborator is unreachable on this path",
 * and null is already what the interface documents as "nothing could be
 * resolved".
 */
final class NullLinkPreviewFetcher implements LinkPreviewFetcher
{
    public function fetch(string $url): ?LinkPreview
    {
        return null;
    }
}
