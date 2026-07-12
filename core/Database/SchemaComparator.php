<?php

declare(strict_types=1);

namespace Core\Database;

class SchemaComparator
{
    /** @var array<string> */
    private array $warnings = [];

    /**
     * Compare declared tables to actual tables and produce DDL statements.
     *
     * Rules:
     * - New table → CREATE TABLE
     * - New column → ALTER TABLE ADD COLUMN
     * - Modified column → ALTER TABLE MODIFY COLUMN
     * - Column in actual but not in declared → WARNING only (never DROP)
     * - Table in actual but not in declared → WARNING only (never DROP)
     * - New index → CREATE INDEX or ADD INDEX
     * - New FK → ADD CONSTRAINT
     *
     * @param array<TableDefinition> $declaredTables
     * @param array<TableDefinition> $actualTables
     * @return array<string>
     */
    public function compare(array $declaredTables, array $actualTables): array
    {
        $this->warnings = [];
        $statements = [];

        // Index actual tables by name
        $actualByName = [];
        foreach ($actualTables as $table) {
            $actualByName[$table->name] = $table;
        }

        // Index declared tables by name
        $declaredByName = [];
        foreach ($declaredTables as $table) {
            $declaredByName[$table->name] = $table;
        }

        // Check for tables in actual but not in declared
        foreach ($actualByName as $name => $table) {
            if (!isset($declaredByName[$name])) {
                $this->warnings[] = "Table '{$name}' exists in database but not in declared schema. Skipping (never auto-drop).";
            }
        }

        // Process declared tables
        foreach ($declaredTables as $declared) {
            if (!isset($actualByName[$declared->name])) {
                // New table: generate CREATE TABLE
                $statements[] = $this->generateCreateTable($declared);
            } else {
                // Existing table: compare columns, indexes, foreign keys
                $actual = $actualByName[$declared->name];
                $alterStatements = $this->compareTable($declared, $actual);
                array_push($statements, ...$alterStatements);
            }
        }

        return $statements;
    }

    /**
     * Get warnings generated during the last comparison.
     *
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function generateCreateTable(TableDefinition $table): string
    {
        $lines = [];

        foreach ($table->columns as $column) {
            $lines[] = '    ' . $this->columnToSql($column);
        }

        // Add primary key if defined in indexes
        foreach ($table->indexes as $index) {
            if ($index->primary) {
                $cols = implode(', ', array_map(fn(string $c) => "`{$c}`", $index->columns));
                $lines[] = "    PRIMARY KEY ({$cols})";
            }
        }

        // Add unique indexes
        foreach ($table->indexes as $index) {
            if ($index->unique && !$index->primary) {
                $cols = implode(', ', array_map(fn(string $c) => "`{$c}`", $index->columns));
                $lines[] = "    UNIQUE INDEX `{$index->name}` ({$cols})";
            }
        }

        // Add non-unique indexes
        foreach ($table->indexes as $index) {
            if (!$index->unique && !$index->primary) {
                $cols = implode(', ', array_map(fn(string $c) => "`{$c}`", $index->columns));
                $lines[] = "    INDEX `{$index->name}` ({$cols})";
            }
        }

        // Add foreign keys
        foreach ($table->foreignKeys as $fk) {
            $fkLine = "    CONSTRAINT `{$fk->name}` FOREIGN KEY (`{$fk->column}`) REFERENCES `{$fk->referencedTable}` (`{$fk->referencedColumn}`)";
            if ($fk->onDelete !== null) {
                $fkLine .= " ON DELETE {$fk->onDelete}";
            }
            if ($fk->onUpdate !== null) {
                $fkLine .= " ON UPDATE {$fk->onUpdate}";
            }
            $lines[] = $fkLine;
        }

        $body = implode(",\n", $lines);

        return "CREATE TABLE `{$table->name}` (\n{$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    /**
     * Compare an existing table's columns, indexes, and foreign keys.
     *
     * @return array<string>
     */
    private function compareTable(TableDefinition $declared, TableDefinition $actual): array
    {
        $statements = [];

        // Index actual columns by name
        $actualColumns = [];
        foreach ($actual->columns as $col) {
            $actualColumns[$col->name] = $col;
        }

        // Index declared columns by name
        $declaredColumns = [];
        foreach ($declared->columns as $col) {
            $declaredColumns[$col->name] = $col;
        }

        // Check for columns in actual but not in declared
        foreach ($actualColumns as $name => $col) {
            if (!isset($declaredColumns[$name])) {
                $this->warnings[] = "Column '{$declared->name}.{$name}' exists in database but not in declared schema. Skipping (never auto-drop).";
            }
        }

        // Check declared columns
        foreach ($declared->columns as $declaredCol) {
            if (!isset($actualColumns[$declaredCol->name])) {
                // New column
                $statements[] = "ALTER TABLE `{$declared->name}` ADD COLUMN " . $this->columnToSql($declaredCol);
            } else {
                // Compare column properties
                $actualCol = $actualColumns[$declaredCol->name];
                if ($this->columnDiffers($declaredCol, $actualCol)) {
                    $statements[] = "ALTER TABLE `{$declared->name}` MODIFY COLUMN " . $this->columnToSql($declaredCol);
                }
            }
        }

        // Compare indexes (excluding PRIMARY which is usually handled with the table)
        $actualIndexes = [];
        foreach ($actual->indexes as $idx) {
            $actualIndexes[$idx->name] = $idx;
        }

        foreach ($declared->indexes as $idx) {
            if ($idx->primary) {
                continue; // Primary key changes are complex; skip for now
            }
            if (!isset($actualIndexes[$idx->name])) {
                $cols = implode(', ', array_map(fn(string $c) => "`{$c}`", $idx->columns));
                if ($idx->unique) {
                    $statements[] = "ALTER TABLE `{$declared->name}` ADD UNIQUE INDEX `{$idx->name}` ({$cols})";
                } else {
                    $statements[] = "ALTER TABLE `{$declared->name}` ADD INDEX `{$idx->name}` ({$cols})";
                }
            }
        }

        // Compare foreign keys
        $actualFks = [];
        foreach ($actual->foreignKeys as $fk) {
            $actualFks[$fk->name] = $fk;
        }

        foreach ($declared->foreignKeys as $fk) {
            if (!isset($actualFks[$fk->name])) {
                $fkSql = "ALTER TABLE `{$declared->name}` ADD CONSTRAINT `{$fk->name}` FOREIGN KEY (`{$fk->column}`) REFERENCES `{$fk->referencedTable}` (`{$fk->referencedColumn}`)";
                if ($fk->onDelete !== null) {
                    $fkSql .= " ON DELETE {$fk->onDelete}";
                }
                if ($fk->onUpdate !== null) {
                    $fkSql .= " ON UPDATE {$fk->onUpdate}";
                }
                $statements[] = $fkSql;
            }
        }

        return $statements;
    }

    private function columnToSql(ColumnDefinition $column): string
    {
        $sql = "`{$column->name}` {$column->type}";

        if (!$column->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($column->default !== null) {
            // Check if default needs quoting
            $unquotedDefaults = ['CURRENT_TIMESTAMP', 'NULL', 'TRUE', 'FALSE'];
            if (in_array(strtoupper($column->default), $unquotedDefaults, true)
                || is_numeric($column->default)) {
                $sql .= " DEFAULT {$column->default}";
            } else {
                $sql .= " DEFAULT '{$column->default}'";
            }
        }

        if ($column->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }

    private function columnDiffers(ColumnDefinition $declared, ColumnDefinition $actual): bool
    {
        // Compare normalized types
        if ($declared->getNormalizedType() !== $actual->getNormalizedType()) {
            return true;
        }

        // Compare nullability
        if ($declared->nullable !== $actual->nullable) {
            return true;
        }

        // Compare defaults (normalized)
        $declaredDefault = $declared->default !== null ? strtoupper($declared->default) : null;
        $actualDefault = $actual->default !== null ? strtoupper($actual->default) : null;

        if ($declaredDefault !== $actualDefault) {
            return true;
        }

        return false;
    }
}
