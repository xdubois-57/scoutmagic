<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * One row of discussion_group_replies. $body is plain text exactly as the
 * author typed it, escaped by Twig at render time — same contract as
 * Post::$body, and it may be empty when the reply carries an image
 * instead.
 *
 * The author properties are named identically to Post's on purpose:
 * Service\PostAuthorResolver resolves labels for either kind through the
 * same two batched queries, so a reply's author never costs a lookup of
 * its own.
 */
class Reply
{
    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly int $authorUserAccountId,
        public readonly int $authorMemberId,
        public readonly string $body,
        public readonly ?int $galleryMediaId,
        public readonly ?string $editedAt,
        public readonly string $createdAt
    ) {
    }

    public function isEdited(): bool
    {
        return $this->editedAt !== null;
    }
}
