<?php

declare(strict_types=1);

namespace Modules\Banner\Repository;

class Banner
{
    /** @var string[] */
    public const ROLE_MINS = ['public', 'identified', 'chief'];

    public function __construct(
        public readonly int $id,
        public readonly bool $isActive,
        public readonly int $sortOrder,
        public readonly string $roleMin
    ) {
    }
}
