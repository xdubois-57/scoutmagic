<?php

declare(strict_types=1);

namespace Core\Database;

class IndexDefinition
{
    /**
     * @param array<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique,
        public readonly bool $primary
    ) {
    }
}
