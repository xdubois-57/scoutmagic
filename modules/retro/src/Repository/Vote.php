<?php

declare(strict_types=1);

namespace Modules\Retro\Repository;

class Vote
{
    public function __construct(
        public readonly int $id,
        public readonly int $commentId,
        public readonly int $boardId,
        public readonly string $voterHash,
        public readonly string $voterBoardHash,
        public readonly int $weight
    ) {
    }
}
