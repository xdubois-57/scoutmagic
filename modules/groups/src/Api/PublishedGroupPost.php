<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

/**
 * A post another module published in a group: its id, and the path that
 * opens it in the group's feed.
 */
final class PublishedGroupPost
{
    public function __construct(
        public readonly int $postId,
        public readonly string $path,
    ) {
    }
}
