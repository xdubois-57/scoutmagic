<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * What another module may publish of an article.
 *
 * - `url` is the address to share — the short one when the article has it
 *   — absolute, built from the site's own address; empty when the site does
 *   not know it yet.
 * - `imageFileId` is the cover's `files` id: a picture the author chose
 *   and uploaded, not a gallery photo.
 * - `shareable` says whether the article's visibility lets it leave the
 *   site at all (Repository\Article::SOCIALLY_SHAREABLE_VISIBILITIES).
 */
final class SharedArticle
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $url,
        public readonly ?int $imageFileId,
        public readonly bool $shareable,
    ) {
    }
}
