<?php

declare(strict_types=1);

namespace Modules\Banner\Repository;

class Banner
{
    public function __construct(
        public readonly int $id,
        public readonly bool $isActive,
        public readonly int $sortOrder
    ) {
    }
}
