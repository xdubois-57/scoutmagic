<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * What a web page says about itself, as Core\Http\UrlMetadataFetcher read
 * it: its Open Graph title, description and image address, and its own
 * <title>. Every field is null when the page does not carry it.
 *
 * `$imageUrl` is the address the page names, never fetched: a caller that
 * wants the image asks UrlMetadataFetcher::fetchImageBytes() for it, under
 * the same SSRF rules, and only when it is going to store it.
 */
final class UrlMetadata
{
    public function __construct(
        public readonly ?string $ogTitle,
        public readonly ?string $htmlTitle,
        public readonly ?string $description,
        public readonly ?string $imageUrl
    ) {
    }
}
