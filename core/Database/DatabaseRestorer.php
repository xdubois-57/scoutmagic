<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * Pure-PHP counterpart to DatabaseDumper: restores a .sql dump (as
 * produced by DatabaseDumper::dump(), i.e. ifsnop/mysqldump-php's output —
 * no DELIMITER changes, no stored routines/triggers in this schema) by
 * executing it over PDO, never shelling out to a `mysql` binary. Same
 * motivation as DatabaseDumper's own docblock: a shared host can lack the
 * `mysql` CLI entirely (disable_functions, or simply not installed) even
 * though PDO's own mysqlnd connection to the same server works fine — this
 * is what a failed dev-branch update's automatic rollback hit in practice
 * (BackupService::restoreDatabase() previously required the `mysql`
 * binary, so a rollback could fail with "mysql n'est pas disponible sur ce
 * serveur" on exactly the hosts most likely to need PHP-only restore).
 */
final class DatabaseRestorer
{
    /**
     * @throws \RuntimeException on any connection, read, or SQL failure
     */
    public static function restore(
        string $host,
        int $port,
        string $dbName,
        string $user,
        string $password,
        string $sqlFilePath
    ): void {
        $sql = file_get_contents($sqlFilePath);
        if ($sql === false) {
            throw new \RuntimeException("Impossible de lire le fichier de sauvegarde : {$sqlFilePath}");
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        foreach (self::splitStatements($sql) as $statement) {
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    /**
     * Splits a mysqldump-style .sql file into individual statements on
     * `;` — but only outside of quoted strings and comments, since a
     * naive `explode(';', ...)` breaks the moment any dumped text column
     * (a member's remark, an address, ...) contains a semicolon.
     * Backslash-escaped and doubled quotes are both honoured (MySQL
     * accepts both; PDO::quote()/mysqldump-php emit backslash-escaping,
     * but doubled quotes are cheap to also support defensively). `--` and
     * `#` line comments and `/* ... *\/` block comments are tracked so a
     * quote character inside one of them can't desynchronize the scanner
     * — this includes mysqldump's own `/*!40101 ... *\/;` version-gated
     * directives, which are left as their own single "statement" (a
     * comment-only string) and simply produce a no-op PDO::exec() call
     * rather than needing special-case handling.
     *
     * @return array<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $mode = 'none'; // none|single|double|line_comment|block_comment
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($mode === 'line_comment') {
                $buffer .= $char;
                if ($char === "\n") {
                    $mode = 'none';
                }
                continue;
            }

            if ($mode === 'block_comment') {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= $next;
                    $i++;
                    $mode = 'none';
                }
                continue;
            }

            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
                continue;
            }

            if ($mode === 'single' || $mode === 'double') {
                $quoteChar = $mode === 'single' ? '\'' : '"';
                if ($char === '\\') {
                    $buffer .= $char;
                    $escaped = true;
                    continue;
                }
                if ($char === $quoteChar) {
                    if ($next === $quoteChar) {
                        // Doubled quote: a literal quote, not the closing one.
                        $buffer .= $char . $next;
                        $i++;
                        continue;
                    }
                    $mode = 'none';
                }
                $buffer .= $char;
                continue;
            }

            // $mode === 'none'
            if ($char === '\'') {
                $mode = 'single';
                $buffer .= $char;
                continue;
            }
            if ($char === '"') {
                $mode = 'double';
                $buffer .= $char;
                continue;
            }
            if ($char === '-' && $next === '-') {
                $mode = 'line_comment';
                $buffer .= $char;
                continue;
            }
            if ($char === '#') {
                $mode = 'line_comment';
                $buffer .= $char;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $mode = 'block_comment';
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $buffer .= $char;
                $statements[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trailing = trim($buffer);
        if ($trailing !== '') {
            $statements[] = $trailing;
        }

        return array_values(array_filter($statements, static fn(string $s): bool => $s !== ''));
    }
}
