<?php

declare(strict_types=1);

namespace Core\Database;

class SchemaIntrospector
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Get the list of all tables in the database.
     *
     * @return array<string>
     */
    public function getTables(): array
    {
        $stmt = $this->pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get the column definitions for a table.
     *
     * @return array<ColumnDefinition>
     */
    public function getColumns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$table]);

        $columns = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $columns[] = new ColumnDefinition(
                name: $row['COLUMN_NAME'],
                type: $row['COLUMN_TYPE'],
                nullable: $row['IS_NULLABLE'] === 'YES',
                default: $row['COLUMN_DEFAULT'],
                autoIncrement: str_contains($row['EXTRA'], 'auto_increment'),
                extra: $row['EXTRA'] ?: null
            );
        }

        return $columns;
    }

    /**
     * Get the index definitions for a table.
     *
     * @return array<IndexDefinition>
     */
    public function getIndexes(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        );
        $stmt->execute([$table]);

        /** @var array<string, array{columns: array<string>, unique: bool, primary: bool}> $grouped */
        $grouped = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $name = $row['INDEX_NAME'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'columns' => [],
                    'unique' => $row['NON_UNIQUE'] === '0' || $row['NON_UNIQUE'] === 0,
                    'primary' => $name === 'PRIMARY',
                ];
            }
            $grouped[$name]['columns'][] = $row['COLUMN_NAME'];
        }

        $indexes = [];
        foreach ($grouped as $name => $data) {
            $indexes[] = new IndexDefinition(
                name: $name,
                columns: $data['columns'],
                unique: $data['unique'],
                primary: $data['primary']
            );
        }

        return $indexes;
    }

    /**
     * Get the foreign key definitions for a table.
     *
     * @return array<ForeignKeyDefinition>
     */
    public function getForeignKeys(string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.DELETE_RULE,
                rc.UPDATE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY kcu.CONSTRAINT_NAME"
        );
        $stmt->execute([$table]);

        $foreignKeys = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $foreignKeys[] = new ForeignKeyDefinition(
                name: $row['CONSTRAINT_NAME'],
                column: $row['COLUMN_NAME'],
                referencedTable: $row['REFERENCED_TABLE_NAME'],
                referencedColumn: $row['REFERENCED_COLUMN_NAME'],
                onDelete: $row['DELETE_RULE'] !== 'RESTRICT' ? $row['DELETE_RULE'] : null,
                onUpdate: $row['UPDATE_RULE'] !== 'RESTRICT' ? $row['UPDATE_RULE'] : null
            );
        }

        return $foreignKeys;
    }

    /**
     * Get a full table definition including columns, indexes, and foreign keys.
     */
    public function getTableDefinition(string $table): TableDefinition
    {
        return new TableDefinition(
            name: $table,
            columns: $this->getColumns($table),
            indexes: $this->getIndexes($table),
            foreignKeys: $this->getForeignKeys($table)
        );
    }

    /**
     * Get all table definitions in the database.
     *
     * @return array<TableDefinition>
     */
    public function getAllTableDefinitions(): array
    {
        $tables = [];
        foreach ($this->getTables() as $tableName) {
            $tables[] = $this->getTableDefinition($tableName);
        }
        return $tables;
    }
}
