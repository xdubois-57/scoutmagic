<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Category
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly int $sortOrder,
        public readonly ?int $accountId = null
    ) {
    }
}
