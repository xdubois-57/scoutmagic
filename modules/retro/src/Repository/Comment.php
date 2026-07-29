<?php

declare(strict_types=1);

namespace Modules\Retro\Repository;

class Comment
{
    /** @var string[] */
    public const COLUMNS = ['good', 'improve', 'suggestion'];

    public function __construct(
        public readonly int $id,
        public readonly int $boardId,
        public readonly string $columnKey,
        public readonly string $body,
        public readonly int $votes,
        public readonly bool $hidden,
        public readonly string $createdAt
    ) {
    }
}
