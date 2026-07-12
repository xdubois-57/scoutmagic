<?php

declare(strict_types=1);

namespace Core\Database;

class TableDefinition
{
    /**
     * @param array<ColumnDefinition> $columns
     * @param array<IndexDefinition> $indexes
     * @param array<ForeignKeyDefinition> $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $indexes,
        public readonly array $foreignKeys
    ) {
    }

    /**
     * Get a column by name, or null if not found.
     */
    public function getColumn(string $name): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Get an index by name, or null if not found.
     */
    public function getIndex(string $name): ?IndexDefinition
    {
        foreach ($this->indexes as $index) {
            if ($index->name === $name) {
                return $index;
            }
        }
        return null;
    }
}
