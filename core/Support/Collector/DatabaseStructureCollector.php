<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Database\DatabaseDumper;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `database-structure.sql` — every table's `CREATE TABLE`, and **not one
 * row of application data**.
 *
 * That constraint is the whole point of the file: a schema mismatch is one
 * of the most common causes of a bug that only reproduces on one
 * installation, and answering it needs the structure and nothing else.
 * Exporting rows would turn a diagnostic attachment into a data export, so
 * it goes through Core\Database\DatabaseDumper's structure-only mode rather
 * than any hand-rolled dump.
 *
 * Where no dump mechanism can run at all (a non-MySQL driver, an
 * unreachable server), the collector reports `unavailable` rather than
 * failing — the package is still worth producing without it.
 */
class DatabaseStructureCollector implements SupportCollectorInterface
{
    public function name(): string
    {
        return 'database_structure';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $credentials = $context->connection()->dumpCredentials();
        if ($credentials['dbName'] === '' || $credentials['host'] === '') {
            $context->markUnavailable('no_mysql_connection_credentials');

            return;
        }

        $temporaryPath = $context->storagePath() . '/temp/support-structure-' . bin2hex(random_bytes(8)) . '.sql';

        try {
            DatabaseDumper::dumpStructure(
                $credentials['host'],
                $credentials['port'],
                $credentials['dbName'],
                $credentials['user'],
                $credentials['password'],
                $temporaryPath
            );

            $sql = @file_get_contents($temporaryPath);
            if ($sql === false || trim($sql) === '') {
                $context->markUnavailable('dump_produced_no_output');

                return;
            }

            $context->addFileFromContent('database-structure.sql', $sql);
        } catch (\Throwable $e) {
            $context->markUnavailable('dump_unavailable: ' . $e->getMessage());
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
