<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/** A web page kept next to a stay (schema.sql: camp_links). */
class Link
{
    public function __construct(
        public readonly int $id,
        public readonly int $campId,
        public readonly string $url,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?int $imageFileId,
        public readonly ?string $siteName,
        public readonly ?string $fetchedAt,
        public readonly string $createdAt
    ) {
    }

    /**
     * What to show as the link's heading. A link with no preview — the
     * gallery module absent, the site unreachable, no Open Graph tags at
     * all — falls back to its own URL rather than to nothing: a bare URL
     * is still a usable link, an empty card is not.
     */
    public function heading(): string
    {
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        return $this->siteName ?? $this->url;
    }

    public function hasPreview(): bool
    {
        return $this->title !== null || $this->imageFileId !== null;
    }
}
