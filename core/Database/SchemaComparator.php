<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * - New index → CREATE INDEX or ADD INDEX — matched by NAME only: an
     *   index that already exists under the declared name is left exactly
     *   as it is, its columns are never compared. Changing an existing
     *   index's column list in schema.sql is therefore a silent no-op on
     *   every installed site (it only converges on fresh installs) —
     *   redefine an index by declaring it under a NEW name instead.
     *   Documented in ARCHITECTURE.md §10 and the AGENTS.md schema rules.
     * - New FK → ADD CONSTRAINT (same name-only matching)
     * - PRIMARY KEY changes on an existing table → skipped entirely
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

    /**
     * DDL for ONE declared table against its introspected counterpart
     * ($actual null when the table doesn't exist yet) — the per-table
     * unit MigrationRunner's chunked, resumable migrate() uses so it can
     * checkpoint between tables instead of introspecting and diffing the
     * entire database in one uninterruptible pass. compare() above stays
     * the bulk, all-at-once entry point (existing behavior, existing
     * tests, unchanged); this is an additional, narrower entry point
     * reusing the exact same private diff logic — never a second
     * implementation of it.
     *
     * @return array<string>
     */
    public function compareOneDeclaredTable(TableDefinition $declared, ?TableDefinition $actual): array
    {
        if ($actual === null) {
            return [$this->generateCreateTable($declared)];
        }

        return $this->compareTable($declared, $actual);
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
            $fkLine = "    CONSTRAINT `{$fk->name}` FOREIGN KEY (`{$fk->column}`) REFERENCES "
                . "`{$fk->referencedTable}` (`{$fk->referencedColumn}`)";
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
                $this->warnings[] = "Column '{$declared->name}.{$name}' exists in database but not in declared "
                    . "schema. Skipping (never auto-drop).";
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

        // Compare indexes (excluding PRIMARY which is usually handled with
        // the table). Name-only matching, per the class doc comment: a
        // declared name already present is skipped without looking at its
        // columns — redefining an index requires a new name.
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
                $fkSql = "ALTER TABLE `{$declared->name}` ADD CONSTRAINT `{$fk->name}` FOREIGN KEY (`{$fk->column}`) "
                    . "REFERENCES `{$fk->referencedTable}` (`{$fk->referencedColumn}`)";
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

        // Compare defaults (normalized) — a boolean column's TRUE/FALSE
        // default is declared that way in schema.sql but MySQL always
        // reports it back as 1/0 via INFORMATION_SCHEMA, since the column
        // itself is really TINYINT(1) (see ColumnDefinition::
        // getNormalizedType()); normalize both sides the same way so this
        // doesn't perpetually look like a real difference.
        $isBoolean = $declared->getNormalizedType() === 'tinyint(1)';
        $declaredDefault = $this->normalizeDefaultForComparison($declared->default, $isBoolean);
        $actualDefault = $this->normalizeDefaultForComparison($actual->default, $isBoolean);

        if ($declaredDefault !== $actualDefault) {
            return true;
        }

        return false;
    }

    private function normalizeDefaultForComparison(?string $default, bool $isBoolean): ?string
    {
        if ($default === null) {
            return null;
        }

        $upper = strtoupper($default);

        if ($isBoolean) {
            if ($upper === 'TRUE') {
                return '1';
            }
            if ($upper === 'FALSE') {
                return '0';
            }
        }

        // MariaDB reports a CURRENT_TIMESTAMP-family default back through
        // INFORMATION_SCHEMA with trailing parentheses (e.g.
        // "current_timestamp()", or "current_timestamp(3)" for a
        // fractional-seconds column) where MySQL reports the bare
        // function name declared in the DDL — without this, every
        // `DEFAULT CURRENT_TIMESTAMP` column looks perpetually different
        // on MariaDB, and MigrationRunner's hash-cache never settles
        // (schema/core.sql alone declares this on ~30 columns), making
        // every request pay the full migration cost forever instead of
        // just once.
        if (str_starts_with($upper, 'CURRENT_TIMESTAMP')) {
            return (string) preg_replace('/\(\d*\)$/', '', $upper);
        }

        return $upper;
    }
}
