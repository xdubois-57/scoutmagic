<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

/**
 * One rendered page of a group feed: the pinned posts (first page only),
 * the paginated stream, and the cursor for the next page — null when
 * there is nothing more to load.
 */
final class FeedPage
{
    /**
     * @param array<int, array<string, mixed>> $pinned
     * @param array<int, array<string, mixed>> $posts
     */
    public function __construct(
        public readonly array $pinned,
        public readonly array $posts,
        public readonly ?string $nextCursor
    ) {
    }
}
