<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * One discussion_group_post_links row — at most one per post (module
 * spec, enforced by that table's own UNIQUE index on post_id).
 */
final class PostLink
{
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly string $url,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?int $imageFileId
    ) {
    }

    /**
     * The bare host for display (module spec: render "image/domain/title/
     * description") — url is already isValidUrl()-checked at write time
     * (Service\PostLinkService), so parse_url() here is only ever reading
     * back a URL this module itself validated, never untrusted input in
     * its own right.
     */
    public function host(): string
    {
        return (string) (parse_url($this->url, PHP_URL_HOST) ?? $this->url);
    }
}
