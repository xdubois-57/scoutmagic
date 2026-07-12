<?php

declare(strict_types=1);

namespace Core\Database;

class ForeignKeyDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly string $referencedTable,
        public readonly string $referencedColumn,
        public readonly ?string $onDelete,
        public readonly ?string $onUpdate
    ) {
    }
}
