<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * The declared schema of a ScoutMagic installation, as one list: the core
 * schema followed by every module's.
 *
 * There used to be no such thing. `schema/core.sql` was migrated by the
 * deploy paths (`Task\InstallUpdateHandler`, `Task\RestoreBackupHandler`,
 * `Http\Controller\SetupController`) and by `public/index.php`, while each
 * module's `schema.sql` was migrated separately and lazily, by
 * `Module\ModuleManager`, the first time a request found the module's
 * manifest version newer than the version in `module_registry`. Two
 * consequences, both of which bit:
 *
 * - A module's schema change only took effect if somebody also remembered
 *   to bump that module's manifest version. Nothing enforced it, and a
 *   forgotten bump is invisible until a query fails against a column that
 *   was never added.
 * - The DDL ran on a visitor's request rather than at deploy time, so the
 *   first person to browse a freshly-updated site paid for it — the exact
 *   cost the deploy was supposed to have already absorbed.
 *
 * Migrating the whole set in one pass removes both. It also removes the
 * ordering hazard that a per-module migration always had: module tables
 * carry foreign keys into core tables, so core must exist first, which is
 * why core.sql is first in this list and why MigrationRunner diffs
 * declared tables in the order it is given them.
 *
 * Every module's schema is included whether or not the module is enabled.
 * A disabled module's tables are empty tables; the alternative is DDL
 * running at the moment somebody enables a module, which is precisely the
 * "schema change on a live request" this exists to stop.
 */
final class SchemaFiles
{
    /**
     * @param string $basePath Installation root — the directory holding
     *   `schema/` and `modules/`.
     * @return array<string> Core first, then module schemas in a stable
     *   (alphabetical) order. Only files that exist.
     */
    public static function all(string $basePath): array
    {
        $basePath = rtrim($basePath, '/');
        $files = [];

        $core = $basePath . '/schema/core.sql';
        if (is_file($core)) {
            $files[] = $core;
        }

        // Sorted, so the identity of the set (MigrationRunner keys its hash
        // cache and its resumable progress row on it) does not depend on
        // the order the filesystem happens to return directory entries in.
        // Safe to sort because no module table references another module's
        // table — an invariant Tests\Architecture\ModuleSchemaBoundariesTest
        // enforces, since ordering module schemas among themselves would
        // otherwise be load-bearing.
        $moduleSchemas = glob($basePath . '/modules/*/schema.sql');
        if ($moduleSchemas !== false) {
            sort($moduleSchemas);
            foreach ($moduleSchemas as $schema) {
                $files[] = $schema;
            }
        }

        return $files;
    }
}
