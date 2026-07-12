<?php

declare(strict_types=1);

namespace Core\Database;

class SqlParser
{
    /**
     * Parse a SQL file containing CREATE TABLE statements.
     * Returns an array of TableDefinition objects.
     *
     * @return array<TableDefinition>
     */
    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("SQL file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Cannot read SQL file: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * Parse SQL content string.
     *
     * @return array<TableDefinition>
     */
    public function parse(string $sql): array
    {
        // Remove comments
        $sql = $this->removeComments($sql);

        $tables = [];

        // Find CREATE TABLE statements and extract their bodies handling nested parentheses
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`]?(\w+)[`]?\s*\(/si';
        $offset = 0;

        while (preg_match($pattern, $sql, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $tableName = $match[1][0];
            $bodyStart = (int) $match[0][1] + strlen($match[0][0]);

            // Find the matching closing parenthesis
            $body = $this->extractBalancedParentheses($sql, $bodyStart);

            if ($body !== null) {
                $tables[] = $this->parseTableBody($tableName, $body);
            }

            $offset = $bodyStart + strlen($body ?? '');
        }

        return $tables;
    }

    /**
     * Extract content between balanced parentheses starting at the given position.
     * The position should be right after the opening parenthesis.
     */
    private function extractBalancedParentheses(string $sql, int $start): ?string
    {
        $depth = 1;
        $len = strlen($sql);

        for ($i = $start; $i < $len; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $start, $i - $start);
                }
            }
        }

        return null;
    }

    private function removeComments(string $sql): string
    {
        // Remove single-line comments (-- ...)
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

        // Remove multi-line comments (/* ... */)
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;

        return $sql;
    }

    private function parseTableBody(string $tableName, string $body): TableDefinition
    {
        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        // Split body into definitions, respecting parentheses
        $definitions = $this->splitDefinitions($body);

        foreach ($definitions as $def) {
            $def = trim($def);

            if ($def === '') {
                continue;
            }

            if ($this->isPrimaryKeyDefinition($def)) {
                $index = $this->parsePrimaryKeyDefinition($def);
                if ($index !== null) {
                    $indexes[] = $index;
                }
            } elseif ($this->isUniqueIndexDefinition($def)) {
                $index = $this->parseUniqueIndexDefinition($def);
                if ($index !== null) {
                    $indexes[] = $index;
                }
            } elseif ($this->isIndexDefinition($def)) {
                $index = $this->parseIndexDefinition($def);
                if ($index !== null) {
                    $indexes[] = $index;
                }
            } elseif ($this->isForeignKeyDefinition($def)) {
                $fk = $this->parseForeignKeyDefinition($def);
                if ($fk !== null) {
                    $foreignKeys[] = $fk;
                }
            } elseif ($this->isConstraintDefinition($def)) {
                // Could be a named constraint (FK or UNIQUE)
                $this->parseConstraintDefinition($def, $indexes, $foreignKeys);
            } else {
                $column = $this->parseColumnDefinition($def);
                if ($column !== null) {
                    $columns[] = $column;

                    // Check for inline PRIMARY KEY
                    if (preg_match('/\bPRIMARY\s+KEY\b/i', $def)) {
                        $indexes[] = new IndexDefinition(
                            name: 'PRIMARY',
                            columns: [$column->name],
                            unique: true,
                            primary: true
                        );
                    } elseif (preg_match('/\bUNIQUE\b/i', $def) && !preg_match('/\bUNIQUE\s+(INDEX|KEY)\b/i', $def)) {
                        $indexes[] = new IndexDefinition(
                            name: 'idx_' . $tableName . '_' . $column->name,
                            columns: [$column->name],
                            unique: true,
                            primary: false
                        );
                    }
                }
            }
        }

        return new TableDefinition(
            name: $tableName,
            columns: $columns,
            indexes: $indexes,
            foreignKeys: $foreignKeys
        );
    }

    /**
     * Split table body into individual definitions, handling nested parentheses.
     *
     * @return array<string>
     */
    private function splitDefinitions(string $body): array
    {
        $definitions = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $definitions[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $definitions[] = trim($current);
        }

        return $definitions;
    }

    private function isPrimaryKeyDefinition(string $def): bool
    {
        return (bool) preg_match('/^\s*PRIMARY\s+KEY\b/i', $def);
    }

    private function isUniqueIndexDefinition(string $def): bool
    {
        return (bool) preg_match('/^\s*UNIQUE\s+(?:INDEX|KEY)\b/i', $def);
    }

    private function isIndexDefinition(string $def): bool
    {
        return (bool) preg_match('/^\s*(?:INDEX|KEY)\b/i', $def);
    }

    private function isForeignKeyDefinition(string $def): bool
    {
        return (bool) preg_match('/^\s*FOREIGN\s+KEY\b/i', $def);
    }

    private function isConstraintDefinition(string $def): bool
    {
        return (bool) preg_match('/^\s*CONSTRAINT\b/i', $def);
    }

    private function parsePrimaryKeyDefinition(string $def): ?IndexDefinition
    {
        if (preg_match('/PRIMARY\s+KEY\s*\(([^)]+)\)/i', $def, $m)) {
            $columns = $this->parseColumnList($m[1]);
            return new IndexDefinition(
                name: 'PRIMARY',
                columns: $columns,
                unique: true,
                primary: true
            );
        }
        return null;
    }

    private function parseUniqueIndexDefinition(string $def): ?IndexDefinition
    {
        if (preg_match('/UNIQUE\s+(?:INDEX|KEY)\s+[`]?(\w+)[`]?\s*\(([^)]+)\)/i', $def, $m)) {
            return new IndexDefinition(
                name: $m[1],
                columns: $this->parseColumnList($m[2]),
                unique: true,
                primary: false
            );
        }
        return null;
    }

    private function parseIndexDefinition(string $def): ?IndexDefinition
    {
        if (preg_match('/(?:INDEX|KEY)\s+[`]?(\w+)[`]?\s*\(([^)]+)\)/i', $def, $m)) {
            return new IndexDefinition(
                name: $m[1],
                columns: $this->parseColumnList($m[2]),
                unique: false,
                primary: false
            );
        }
        return null;
    }

    private function parseForeignKeyDefinition(string $def): ?ForeignKeyDefinition
    {
        $pattern = '/FOREIGN\s+KEY\s*\([`]?(\w+)[`]?\)\s*REFERENCES\s+[`]?(\w+)[`]?\s*\([`]?(\w+)[`]?\)(?:\s+ON\s+DELETE\s+(CASCADE|SET\s+NULL|NO\s+ACTION|RESTRICT))?(?:\s+ON\s+UPDATE\s+(CASCADE|SET\s+NULL|NO\s+ACTION|RESTRICT))?/i';

        if (preg_match($pattern, $def, $m)) {
            $onDelete = isset($m[4]) && $m[4] !== '' ? strtoupper($m[4]) : null;
            $onUpdate = isset($m[5]) && $m[5] !== '' ? strtoupper($m[5]) : null;

            // Generate a constraint name
            $name = 'fk_' . $m[1] . '_' . $m[2];

            return new ForeignKeyDefinition(
                name: $name,
                column: $m[1],
                referencedTable: $m[2],
                referencedColumn: $m[3],
                onDelete: $onDelete !== 'RESTRICT' ? $onDelete : null,
                onUpdate: $onUpdate !== 'RESTRICT' ? $onUpdate : null
            );
        }
        return null;
    }

    /**
     * Parse a CONSTRAINT definition (named FK or UNIQUE).
     *
     * @param array<IndexDefinition> $indexes
     * @param array<ForeignKeyDefinition> $foreignKeys
     */
    private function parseConstraintDefinition(string $def, array &$indexes, array &$foreignKeys): void
    {
        // Named foreign key constraint
        $fkPattern = '/CONSTRAINT\s+[`]?(\w+)[`]?\s+FOREIGN\s+KEY\s*\([`]?(\w+)[`]?\)\s*REFERENCES\s+[`]?(\w+)[`]?\s*\([`]?(\w+)[`]?\)(?:\s+ON\s+DELETE\s+(CASCADE|SET\s+NULL|NO\s+ACTION|RESTRICT))?(?:\s+ON\s+UPDATE\s+(CASCADE|SET\s+NULL|NO\s+ACTION|RESTRICT))?/i';

        if (preg_match($fkPattern, $def, $m)) {
            $onDelete = isset($m[5]) && $m[5] !== '' ? strtoupper($m[5]) : null;
            $onUpdate = isset($m[6]) && $m[6] !== '' ? strtoupper($m[6]) : null;

            $foreignKeys[] = new ForeignKeyDefinition(
                name: $m[1],
                column: $m[2],
                referencedTable: $m[3],
                referencedColumn: $m[4],
                onDelete: $onDelete !== 'RESTRICT' ? $onDelete : null,
                onUpdate: $onUpdate !== 'RESTRICT' ? $onUpdate : null
            );
            return;
        }

        // Named unique constraint
        $uniquePattern = '/CONSTRAINT\s+[`]?(\w+)[`]?\s+UNIQUE\s*\(([^)]+)\)/i';
        if (preg_match($uniquePattern, $def, $m)) {
            $indexes[] = new IndexDefinition(
                name: $m[1],
                columns: $this->parseColumnList($m[2]),
                unique: true,
                primary: false
            );
        }
    }

    private function parseColumnDefinition(string $def): ?ColumnDefinition
    {
        // Pattern: column_name TYPE[(size)] [UNSIGNED] [NOT NULL] [DEFAULT value] [AUTO_INCREMENT] ...
        $pattern = '/^[`]?(\w+)[`]?\s+(.+)$/i';

        if (!preg_match($pattern, $def, $m)) {
            return null;
        }

        $name = $m[1];
        $rest = trim($m[2]);

        // Extract the type (up to the first known keyword or end)
        $type = $this->extractType($rest);
        $hasPrimaryKey = (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $rest);
        $nullable = !preg_match('/\bNOT\s+NULL\b/i', $rest) && !$hasPrimaryKey;
        $autoIncrement = (bool) preg_match('/\bAUTO_INCREMENT\b/i', $rest);
        $default = $this->extractDefault($rest);
        $extra = $autoIncrement ? 'auto_increment' : null;

        return new ColumnDefinition(
            name: $name,
            type: $type,
            nullable: $nullable,
            default: $default,
            autoIncrement: $autoIncrement,
            extra: $extra
        );
    }

    private function extractType(string $definition): string
    {
        // Match ENUM/SET types with quoted string values: ENUM('a', 'b', 'c')
        if (preg_match('/^((?:enum|set)\s*\([^)]+\))/i', $definition, $m)) {
            return strtolower(trim($m[1]));
        }

        // Match type with optional size/precision and UNSIGNED
        $pattern = '/^(\w+(?:\s+UNSIGNED)?(?:\(\s*[\d,\s]+\s*\))?(?:\s+UNSIGNED)?)/i';

        if (preg_match($pattern, $definition, $m)) {
            return strtolower(trim($m[1]));
        }

        // Fallback: just take the first word
        $parts = preg_split('/\s+/', $definition);
        return strtolower($parts[0] ?? 'varchar(255)');
    }

    private function extractDefault(string $definition): ?string
    {
        if (preg_match('/\bDEFAULT\s+(\'[^\']*\'|"[^"]*"|\w+(?:\(\))?|CURRENT_TIMESTAMP|NULL)/i', $definition, $m)) {
            $value = $m[1];
            // Remove quotes if present
            if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
                || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
                $value = substr($value, 1, -1);
            }
            return $value;
        }
        return null;
    }

    /**
     * Parse a comma-separated list of column names.
     *
     * @return array<string>
     */
    private function parseColumnList(string $list): array
    {
        $columns = [];
        foreach (explode(',', $list) as $col) {
            $col = trim($col, " \t\n\r\0\x0B`");
            if ($col !== '') {
                $columns[] = $col;
            }
        }
        return $columns;
    }
}
