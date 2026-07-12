<?php

declare(strict_types=1);

namespace Core\Database;

class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly ?string $default,
        public readonly bool $autoIncrement,
        public readonly ?string $extra
    ) {
    }

    /**
     * Get the normalized type string for comparison purposes.
     */
    public function getNormalizedType(): string
    {
        return strtolower(trim($this->type));
    }
}
