<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * The result of resolving one URL's Open Graph metadata: just enough to
 * render a card, nothing implementation-internal.
 *
 * `$imageBytes` is the raw, already SSRF-checked and size-capped download
 * of `og:image` (or null when there was none, or it failed to download) —
 * deliberately NOT a URL. The caller stores it as its own `files` row,
 * scoped to whatever access-control domain it owns, which is what stops a
 * preview image from being served under someone else's rules; and a page
 * that hands out a different image on the second request cannot make a
 * post say something it did not say when it was written.
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
