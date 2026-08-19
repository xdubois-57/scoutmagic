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
        public readonly string $createdAt,
        // Trailing and defaulted so every existing positional
        // construction of this DTO keeps working — same append-only
        // discipline as DiscussionGroup::$galleryAlbumId.
        public readonly ?string $hiddenAt = null,
        public readonly bool $moderationCleared = false
    ) {
    }

    public function isEdited(): bool
    {
        return $this->editedAt !== null;
    }

    /**
     * Auto-hidden by reports and not yet reviewed. Only a moderator ever
     * sees such a post at all — the exclusion is applied in
     * Repository\PostRepository's own queries, so this is for rendering
     * the "masqué — en attente de modération" badge, never for deciding
     * visibility.
     */
    public function isHidden(): bool
    {
        return $this->hiddenAt !== null;
    }
}
