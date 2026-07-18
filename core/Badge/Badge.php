<?php

declare(strict_types=1);

namespace Core\Badge;

class Badge
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isDefault,
        public readonly bool $isActive
    ) {
    }
}
