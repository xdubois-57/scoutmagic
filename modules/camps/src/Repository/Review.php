<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/** What a staff thought of a stay (schema.sql: camp_reviews). */
class Review
{
    public const MIN_RATING = 1;
    public const MAX_RATING = 5;

    public function __construct(
        public readonly int $id,
        public readonly int $campId,
        public readonly ?int $rating,
        public readonly ?string $comment,
        public readonly ?int $authorMemberId,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    /**
     * A review with neither a rating nor a comment says nothing and is
     * not worth showing — Service\ReviewService refuses to store one.
     */
    public function isEmpty(): bool
    {
        return $this->rating === null && ($this->comment === null || trim($this->comment) === '');
    }
}
