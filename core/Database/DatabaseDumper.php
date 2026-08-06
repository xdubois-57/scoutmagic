<?php

declare(strict_types=1);

namespace Core\Database;

use Ifsnop\Mysqldump\Mysqldump;

/**
 * Thin wrapper around ifsnop/mysqldump-php — a pure-PHP mysqldump
 * reimplementation that dumps over PDO, never shelling out to a
 * `mysqldump` binary. That sidesteps every shared-hosting failure mode a
 * shelled-out mysqldump used to hit here: the binary missing from $PATH,
 * disable_functions blocking exec()/shell_exec()/system()/passthru()
 * entirely, and — the one that finally forced this migration — a
 * libmysqlclient build unable to load the mysql_native_password auth
 * plugin even though PHP's own PDO connection to the same server works
 * fine (PDO's mysqlnd driver doesn't depend on that plugin at all).
 *
 * Every caller that used to shell out to mysqldump (Core\Maintenance\
 * BackupService, Core\Database\MigrationRunner's pre-migration safety
 * backup) now goes through this instead.
 */
final class DatabaseDumper
{
    /**
     * @param string[]|null $skipDataForTables null dumps every table's
     *        structure and row data; a list dumps every table's structure
     *        but skips row data for only the tables named here — used for
     *        the "config only" backup scope, where every other table's
     *        schema is still worth keeping but its rows are member/business
     *        data that scope must never include
     * @throws \Exception on any connection or dump failure — callers wrap
     *         this into their own domain exception (BackupException, etc.)
     */
    public static function dump(
        string $host,
        int $port,
        string $dbName,
        string $user,
        string $password,
        string $outputPath,
        ?array $skipDataForTables = null
    ): void {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

        $dumper = new Mysqldump($dsn, $user, $password, [
            // Matches real mysqldump's default (--opt implies
            // --add-drop-table) so a restore over an already-migrated
            // schema doesn't fail on "table already exists".
            'add-drop-table' => true,
            'no-data' => $skipDataForTables ?? false,
        ]);

        $dumper->start($outputPath);
    }
}
