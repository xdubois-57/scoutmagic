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
     *
     * MySQL resolves BOOLEAN/BOOL to TINYINT(1) at CREATE TABLE time and
     * always reports the resolved form back via INFORMATION_SCHEMA — a
     * declared "boolean" column never round-trips as "boolean" through
     * introspection, so without this normalization SchemaComparator would
     * treat every boolean column as perpetually different from the
     * declared schema and re-issue a no-op MODIFY COLUMN on every run.
     * Similarly, MySQL's introspected ENUM/SET column type always strips
     * the space after each comma, even when the original DDL had one.
     */
    public function getNormalizedType(): string
    {
        $type = strtolower(trim($this->type));

        if ($type === 'boolean' || $type === 'bool') {
            return 'tinyint(1)';
        }

        if (str_starts_with($type, 'enum(') || str_starts_with($type, 'set(')) {
            return str_replace(', ', ',', $type);
        }

        return $type;
    }
}
