<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
        $stmt = $this->pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Whether this server is MariaDB rather than MySQL, read once.
     *
     * Asked for one reason only, and it is not a preference: the two
     * engines report column defaults in formats that are individually
     * unambiguous and mutually contradictory. See decodeDefault().
     */
    private ?bool $isMariaDb = null;

    private function isMariaDb(): bool
    {
        if ($this->isMariaDb === null) {
            $version = '';
            try {
                $version = (string) $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (\PDOException) {
                // A driver that will not say is treated as MySQL: that
                // branch changes nothing about what is reported, it only
                // declines to reinterpret it.
            }

            $this->isMariaDb = stripos($version, 'mariadb') !== false;
        }

        return $this->isMariaDb;
    }

    /**
     * What `INFORMATION_SCHEMA.COLUMNS.COLUMN_DEFAULT` reports, turned
     * back into the value the column actually defaults to.
     *
     * On MariaDB 10.2+ that column holds a SQL **expression**, not a
     * value, and the difference is not academic — it is 532 phantom
     * `MODIFY COLUMN` statements on this codebase's schema, regenerated
     * on every migration pass for ever:
     *
     * - a nullable column with no default reports the bare, unquoted text
     *   `NULL`, where MySQL reports a real SQL NULL. Read literally, every
     *   such column looks like it wants a default of the four-character
     *   string "NULL" — 472 of them here;
     * - a string default is reported quoted and escaped (`'public'`,
     *   `'it''s'`), while a schema file declares `DEFAULT 'public'` and
     *   the parser hands back `public` — 60 more.
     *
     * **The two engines are individually unambiguous and mutually
     * contradictory, which is why this has to know which one it is
     * talking to.** MySQL does not quote string literals, so an unquoted
     * `NULL` there means a genuine default of the string "NULL" — the
     * exact opposite of what it means on MariaDB — while "no default" is
     * a real SQL NULL it has already reported as such. Applying MariaDB's
     * reading to MySQL erases a real default; applying MySQL's to MariaDB
     * invents 472 of them. CI runs MySQL 8 and production runs MariaDB
     * 10.11, so both readings are live at once, and a test caught this
     * with the assertion that matters most: `DEFAULT 'NULL'` must survive.
     *
     * Normalised here rather than in `SchemaComparator` — unlike
     * `current_timestamp()` versus `CURRENT_TIMESTAMP`, which are two
     * spellings of one real default and so only need reconciling when
     * comparing, these are the introspector reporting something the
     * column does not have. Every reader deserves the truth, not only the
     * one that happens to diff.
     */
    private function decodeDefault(?string $reported): ?string
    {
        if ($reported === null) {
            return null;
        }

        // Quoted: a string literal, and only MariaDB writes them this way.
        // Checked first, so a MariaDB column that genuinely defaults to
        // the string "NULL" — reported `'NULL'`, with quotes — keeps it.
        if (strlen($reported) >= 2 && str_starts_with($reported, "'") && str_ends_with($reported, "'")) {
            return str_replace(["''", '\\\\'], ["'", '\\'], substr($reported, 1, -1));
        }

        if ($reported === 'NULL' && $this->isMariaDb()) {
            return null;
        }

        return $reported;
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
                default: $this->decodeDefault($row['COLUMN_DEFAULT']),
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
     * Every named table's full definition, in three queries instead of
     * three per table.
     *
     * The per-table methods above cost 3 INFORMATION_SCHEMA round trips
     * each, and MigrationRunner called them once per declared table on
     * every pass — 167 tables on the reference installation (44 core +
     * 123 module) means around 500 round trips to answer a question that
     * is three queries wide. This reads COLUMNS, STATISTICS and
     * KEY_COLUMN_USAGE (joined to REFERENTIAL_CONSTRAINTS) once each,
     * filtered to the tables asked for, and groups the rows in PHP.
     *
     * **The production database is MariaDB 10.11, not MySQL 8.** There is
     * no data dictionary behind INFORMATION_SCHEMA there: every query
     * genuinely opens table definitions. Three wide queries still beat
     * ~500 narrow ones by a wide margin, but the win is not the same one
     * MySQL 8 would give — see CHANGELOG-migration.md for the measured
     * figures rather than assuming.
     *
     * `CARDINALITY` is deliberately absent from the STATISTICS select:
     * reading it makes the storage engine collect index statistics, which
     * is exactly the cost this method exists to avoid. Only INDEX_NAME,
     * COLUMN_NAME, NON_UNIQUE and SEQ_IN_INDEX are needed.
     *
     * @param array<string> $tables
     * @return array<string, TableDefinition> keyed by table name; a table
     *   that does not exist in the database is simply absent from the
     *   result, exactly as getTables() would have told the caller.
     */
    public function getTableDefinitions(array $tables): array
    {
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            return [];
        }

        $columns = $this->bulkColumns($tables);
        $indexes = $this->bulkIndexes($tables);
        $foreignKeys = $this->bulkForeignKeys($tables);

        $definitions = [];
        foreach ($tables as $table) {
            // A table with no columns is a table that does not exist:
            // every real one has at least one.
            if (!isset($columns[$table])) {
                continue;
            }

            $definitions[$table] = new TableDefinition(
                name: $table,
                columns: $columns[$table],
                indexes: $indexes[$table] ?? [],
                foreignKeys: $foreignKeys[$table] ?? []
            );
        }

        return $definitions;
    }

    /**
     * @param array<string> $tables
     * @return array<string, array<ColumnDefinition>>
     */
    private function bulkColumns(array $tables): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . self::placeholders($tables) . ')
             ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $stmt->execute($tables);

        $byTable = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $byTable[$row['TABLE_NAME']][] = new ColumnDefinition(
                name: $row['COLUMN_NAME'],
                type: $row['COLUMN_TYPE'],
                nullable: $row['IS_NULLABLE'] === 'YES',
                default: $this->decodeDefault($row['COLUMN_DEFAULT']),
                autoIncrement: str_contains($row['EXTRA'], 'auto_increment'),
                extra: $row['EXTRA'] ?: null
            );
        }

        return $byTable;
    }

    /**
     * @param array<string> $tables
     * @return array<string, array<IndexDefinition>>
     */
    private function bulkIndexes(array $tables): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . self::placeholders($tables) . ')
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute($tables);

        /** @var array<string, array<string, array{columns: array<string>, unique: bool, primary: bool}>> $grouped */
        $grouped = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $table = $row['TABLE_NAME'];
            $name = $row['INDEX_NAME'];
            if (!isset($grouped[$table][$name])) {
                $grouped[$table][$name] = [
                    'columns' => [],
                    'unique' => $row['NON_UNIQUE'] === '0' || $row['NON_UNIQUE'] === 0,
                    'primary' => $name === 'PRIMARY',
                ];
            }
            $grouped[$table][$name]['columns'][] = $row['COLUMN_NAME'];
        }

        $byTable = [];
        foreach ($grouped as $table => $indexes) {
            foreach ($indexes as $name => $data) {
                $byTable[$table][] = new IndexDefinition(
                    name: (string) $name,
                    columns: $data['columns'],
                    unique: $data['unique'],
                    primary: $data['primary']
                );
            }
        }

        return $byTable;
    }

    /**
     * @param array<string> $tables
     * @return array<string, array<ForeignKeyDefinition>>
     */
    private function bulkForeignKeys(array $tables): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                kcu.TABLE_NAME,
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
                AND kcu.TABLE_NAME IN (' . self::placeholders($tables) . ')
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME'
        );
        $stmt->execute($tables);

        $byTable = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $byTable[$row['TABLE_NAME']][] = new ForeignKeyDefinition(
                name: $row['CONSTRAINT_NAME'],
                column: $row['COLUMN_NAME'],
                referencedTable: $row['REFERENCED_TABLE_NAME'],
                referencedColumn: $row['REFERENCED_COLUMN_NAME'],
                onDelete: $row['DELETE_RULE'] !== 'RESTRICT' ? $row['DELETE_RULE'] : null,
                onUpdate: $row['UPDATE_RULE'] !== 'RESTRICT' ? $row['UPDATE_RULE'] : null
            );
        }

        return $byTable;
    }

    /**
     * @param array<string> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Get all table definitions in the database.
     *
     * @return array<TableDefinition>
     */
    public function getAllTableDefinitions(): array
    {
        return array_values($this->getTableDefinitions($this->getTables()));
    }
}
