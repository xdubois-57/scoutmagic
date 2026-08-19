<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * The result of resolving one URL's Open Graph metadata, as seen from
 * outside the gallery module — mirrors Api\DelegatedMedia's own "just
 * enough to render, nothing module-internal" shape. $imageBytes is the
 * raw, already SSRF-checked and size-capped download of og:image (or null
 * when there was none, or it failed to download) — deliberately not a URL:
 * the caller stores it as its own `files` row, scoped to whatever
 * access-control domain it owns (Api\LinkPreviewFetcher's own docblock
 * explains why gallery never stores or serves this image itself).
 */
final class LinkPreview
{
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $imageBytes
    ) {
    }
}
