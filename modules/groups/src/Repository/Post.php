<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * One row of discussion_group_posts. $body is plain text exactly as the
 * author typed it — never HTML, never sanitized markup: it is escaped by
 * Twig at render time, which is the only correct place to do it.
 */
class Post
{
    public function __construct(
        public readonly int $id,
        public readonly int $groupId,
        public readonly int $authorUserAccountId,
        public readonly int $authorMemberId,
        public readonly string $body,
        public readonly bool $isPinned,
        public readonly ?string $editedAt,
        public readonly string $lastActivityAt,
        public readonly string $createdAt
    ) {
    }

    public function isEdited(): bool
    {
        return $this->editedAt !== null;
    }
}
