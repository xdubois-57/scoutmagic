<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

class MigrationRunner
{
    public function __construct(
        private Connection $connection,
        private SchemaIntrospector $introspector,
        private SchemaComparator $comparator,
        private SqlParser $parser,
        private int $timeBudgetSeconds = 20
    ) {
    }

    /**
     * Run the migration, chunked and resumable:
     * 0. Skip everything if schema.sql/drops.sql haven't changed since the
     *    last clean run (see computeSchemaHash()/getStoredSetting()) — this
     *    runs on every single page load in production, and re-introspecting
     *    the entire live schema (several INFORMATION_SCHEMA queries per
     *    table) to conclude "nothing to do" is pure waste on every request
     *    that isn't immediately after a real schema change.
     * 1. Backup the database (Core\Database\DatabaseDumper, skip if it fails).
     * 2. Build the queue of declared tables to diff (cheap: one
     *    INFORMATION_SCHEMA.TABLES query, no per-table introspection yet).
     * 3. Diff one declared table at a time, introspecting it only as it's
     *    dequeued — this is the expensive part (3 INFORMATION_SCHEMA
     *    queries per table) that used to run for the whole database in one
     *    uninterruptible pass.
     * 4. Execute one generated DDL statement at a time.
     * 5. Apply explicit, reviewed column/constraint drops (see
     *    applyExplicitDrops()) — kept atomic, see that method's doc comment.
     * 6. Return a MigrationResult.
     *
     * After every unit of work in steps 1–4, progress is checkpointed to
     * the `settings` table (Core\Database\MigrationProgress) and the
     * elapsed time is checked against $timeBudgetSeconds. If the budget is
     * exceeded, migrate() returns immediately with an incomplete
     * MigrationResult (complete: false) WITHOUT saving the schema hash —
     * the next migrate() call for the same schema files resumes from
     * exactly the checkpointed state instead of restarting. This is what
     * keeps a large or slow schema change from ever making a single
     * request run long enough to hit PHP's max_execution_time: each
     * request only ever does up to ~$timeBudgetSeconds of work.
     *
     * @param array<string> $schemaFiles
     */
    public function migrate(array $schemaFiles): MigrationResult
    {
        $hashKey = $this->schemaHashSettingKey($schemaFiles);
        $currentHash = $this->computeSchemaHash($schemaFiles);

        if ($this->getStoredSetting($hashKey) === $currentHash) {
            return new MigrationResult(executedStatements: [], warnings: [], backupCreated: false);
        }

        // Mutual exclusion: with the migration-in-progress page (Core\Http\
        // Controller\SystemController) driving repeated short migrate()
        // calls from every visitor's browser while a migration is pending,
        // several requests can easily race in to do real work at once. Only
        // one is allowed to actually work at a time — the rest report "not
        // done yet" immediately (cheap) rather than concurrently
        // introspecting/diffing/executing DDL against the same tables,
        // which could otherwise double-apply (or lose track of) statements.
        // Degrades to "no mutual exclusion" when GET_LOCK() isn't available
        // (this class's local, non-@group-database orchestration tests run
        // against SQLite) — safe there since those tests never call
        // migrate() concurrently against the same connection.
        if (!$this->acquireMigrationLock()) {
            return new MigrationResult(executedStatements: [], warnings: [], backupCreated: false, complete: false, progressFraction: 0.0);
        }

        try {
            return $this->runMigration($schemaFiles, $hashKey, $currentHash);
        } finally {
            $this->releaseMigrationLock();
        }
    }

    /**
     * Cheap check — the same hash comparison migrate() itself starts with,
     * without acquiring the lock or doing any work. For a caller (the
     * migration-in-progress page's bootstrap check in public/index.php)
     * that just needs to decide whether to show that page at all.
     *
     * @param array<string> $schemaFiles
     */
    public function isPending(array $schemaFiles): bool
    {
        return $this->getStoredSetting($this->schemaHashSettingKey($schemaFiles)) !== $this->computeSchemaHash($schemaFiles);
    }

    /**
     * @param array<string> $schemaFiles
     */
    private function runMigration(array $schemaFiles, string $hashKey, string $currentHash): MigrationResult
    {
        $deadline = microtime(true) + $this->timeBudgetSeconds;
        $progressKey = $this->progressSettingKey($schemaFiles);
        $progress = $this->loadOrStartProgress($progressKey, $currentHash);
        $pdo = $this->connection->getPdo();

        // Step 1: attempt backup — once per attempt, not chunk-able (see
        // attemptBackup()'s doc comment).
        if (!$progress->backupDone) {
            $warnings = $progress->warnings;
            $progress->backupCreated = $this->attemptBackup($warnings);
            $progress->warnings = $warnings;
            $progress->backupDone = true;

            if (($incomplete = $this->checkpoint($progressKey, $progress, $deadline)) !== null) {
                return $incomplete;
            }
        }

        // Parsing schema files is cheap, local, and deterministic given
        // the same (unchanged, per the hash check above) file content — no
        // need to persist the parsed result, just reparse every call.
        $declaredTables = [];
        foreach ($schemaFiles as $file) {
            array_push($declaredTables, ...$this->parser->parseFile($file));
        }
        $declaredByName = [];
        foreach ($declaredTables as $table) {
            $declaredByName[$table->name] = $table;
        }

        // Step 2: build the table queue — one cheap INFORMATION_SCHEMA
        // query (no per-table introspection), so this never needs to be
        // interrupted mid-way.
        if (!$progress->tableQueueBuilt) {
            $progress->actualTableNames = $this->introspector->getTables();

            foreach ($progress->actualTableNames as $name) {
                if (!isset($declaredByName[$name])) {
                    $progress->warnings[] = "Table '{$name}' exists in database but not in declared schema. Skipping (never auto-drop).";
                }
            }

            $progress->remainingTableNames = array_keys($declaredByName);
            $progress->totalTableCount = count($progress->remainingTableNames);
            $progress->tableQueueBuilt = true;

            if (($incomplete = $this->checkpoint($progressKey, $progress, $deadline)) !== null) {
                return $incomplete;
            }
        }

        // Step 3: diff one declared table at a time.
        while (!empty($progress->remainingTableNames)) {
            $tableName = array_shift($progress->remainingTableNames);
            $declared = $declaredByName[$tableName];

            $actual = in_array($tableName, $progress->actualTableNames, true)
                ? $this->introspector->getTableDefinition($tableName)
                : null;

            // compareOneDeclaredTable() accumulates onto the comparator's
            // internal warning list rather than resetting it (that reset
            // only happens in compare(), the bulk entry point this class
            // no longer calls) — take just the slice this table added.
            $warningCountBefore = count($this->comparator->getWarnings());
            $statements = $this->comparator->compareOneDeclaredTable($declared, $actual);
            $newWarnings = array_slice($this->comparator->getWarnings(), $warningCountBefore);

            array_push($progress->pendingStatements, ...$statements);
            array_push($progress->warnings, ...$newWarnings);

            if (($incomplete = $this->checkpoint($progressKey, $progress, $deadline)) !== null) {
                return $incomplete;
            }
        }

        // The diff phase (which just finished, whether just now or in a
        // previous call) queued every statement this attempt will ever
        // execute — a stable denominator for the execute phase's progress.
        // Guarded so it's only ever captured once: on a resumed call where
        // the diff phase already finished earlier, the while loop above
        // does zero iterations (remainingTableNames is already empty), and
        // this must NOT re-snapshot a shrunken pendingStatements as if it
        // were the original total.
        if ($progress->totalStatementCount === 0) {
            $progress->totalStatementCount = count($progress->pendingStatements);
        }

        // Step 4: execute one DDL statement at a time.
        while (!empty($progress->pendingStatements)) {
            $statement = array_shift($progress->pendingStatements);

            try {
                $pdo->exec($statement);
                $progress->executedStatements[] = $statement;
            } catch (\PDOException $e) {
                $progress->failedCount++;
                $progress->warnings[] = "Failed to execute: {$statement} — Error: {$e->getMessage()}";
            }

            if (($incomplete = $this->checkpoint($progressKey, $progress, $deadline)) !== null) {
                return $incomplete;
            }
        }

        // Step 5: apply explicit, reviewed column/constraint drops.
        if (!$progress->dropsApplied) {
            $dropWarnings = [];
            $dropStatements = $this->applyExplicitDrops($schemaFiles, $pdo, $dropWarnings);
            array_push($progress->executedStatements, ...$dropStatements);
            array_push($progress->warnings, ...$dropWarnings);
            $progress->dropsApplied = true;
        }

        // Step 6: return the result.
        //
        // Hash caching: save the hash so the next request skips migration
        // entirely. The hash is saved when all generated DDL executed
        // without error (no PDOException) — this is critical for MariaDB
        // hosts where type-reporting differences (e.g. INT(11) vs INT,
        // CURRENT_TIMESTAMP() vs CURRENT_TIMESTAMP) cause SchemaComparator
        // to generate the same cosmetic MODIFY COLUMN statements on every
        // single request. Those ALTERs succeed (no PDOException) but
        // introspection still reports the same "different" type back,
        // creating an infinite loop of DDL statements on every page load.
        // By caching the hash after a successful run regardless of whether
        // changes were applied, the next request short-circuits at the
        // hash check.
        //
        // Informational warnings ("column exists in DB but not in schema")
        // never block caching — they're expected when modules add columns
        // not declared in core.sql. Only actual execution failures should
        // prevent caching, since those mean the schema didn't converge.
        if ($progress->failedCount === 0) {
            $this->saveHash($hashKey, $currentHash);
        }
        $this->clearProgress($progressKey);

        return new MigrationResult(
            executedStatements: $progress->executedStatements,
            warnings: $progress->warnings,
            backupCreated: $progress->backupCreated,
            complete: true,
            progressFraction: 1.0
        );
    }

    /**
     * Persist $progress and, if the time budget has been exceeded, return
     * the incomplete MigrationResult the caller should return immediately.
     * Returns null when there's still budget left, meaning the caller
     * should carry on to the next unit of work.
     */
    private function checkpoint(string $progressKey, MigrationProgress $progress, float $deadline): ?MigrationResult
    {
        $this->saveProgress($progressKey, $progress);

        if (microtime(true) < $deadline) {
            return null;
        }

        return new MigrationResult(
            executedStatements: $progress->executedStatements,
            warnings: $progress->warnings,
            backupCreated: $progress->backupCreated,
            complete: false,
            progressFraction: $this->progressFraction($progress)
        );
    }

    /**
     * Rough 0.0–1.0 estimate for the migration-in-progress page's progress
     * bar: four equally-weighted phases (backup, table diffing, statement
     * execution, explicit drops), each contributing its own completion
     * fraction. Deliberately approximate — table/statement counts vary
     * wildly in cost (a one-column ALTER vs. a big CREATE TABLE), so this
     * is a "does the bar move" indicator, not a time estimate.
     */
    private function progressFraction(MigrationProgress $progress): float
    {
        $backupFraction = $progress->backupDone ? 1.0 : 0.0;

        $diffFraction = $progress->totalTableCount > 0
            ? ($progress->totalTableCount - count($progress->remainingTableNames)) / $progress->totalTableCount
            : ($progress->tableQueueBuilt ? 1.0 : 0.0);

        $executeFraction = 0.0;
        if ($progress->tableQueueBuilt && empty($progress->remainingTableNames)) {
            $executeFraction = $progress->totalStatementCount > 0
                ? ($progress->totalStatementCount - count($progress->pendingStatements)) / $progress->totalStatementCount
                : 1.0;
        }

        $dropsFraction = $progress->dropsApplied ? 1.0 : 0.0;

        return ($backupFraction + $diffFraction + $executeFraction + $dropsFraction) / 4;
    }

    /**
     * @see migrate()'s doc comment for why this exists.
     */
    private function acquireMigrationLock(): bool
    {
        try {
            $stmt = $this->connection->getPdo()->query("SELECT GET_LOCK('scoutmagic_schema_migration', 0)");
            if ($stmt === false) {
                return false;
            }
            $acquired = $stmt->fetchColumn();
            $stmt->closeCursor();
            return (int) $acquired === 1;
        } catch (\PDOException) {
            // GET_LOCK() is MySQL/MariaDB-specific and unavailable on the
            // SQLite connection this class's local tests use — proceeding
            // without mutual exclusion there is safe (see migrate()'s
            // comment above the call site).
            return true;
        }
    }

    private function releaseMigrationLock(): void
    {
        try {
            // ::query(), not ::exec() — RELEASE_LOCK() is a SELECT-style
            // function call that returns a result set, and exec() is only
            // for statements that don't (INSERT/UPDATE/DELETE/DDL). Used
            // via exec() here, the row it returns is never read, leaving
            // its cursor open on the connection — the very next query
            // (the caller's next request, or a test's own follow-up
            // assertion right after migrate() returns) then fails with
            // "Cannot execute queries while other unbuffered queries are
            // active". This runs at the end of every migrate() call, so
            // it broke the very next query almost every time.
            $stmt = $this->connection->getPdo()->query("SELECT RELEASE_LOCK('scoutmagic_schema_migration')");
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
        } catch (\PDOException) {
        }
    }

    /**
     * @param array<string> $schemaFiles
     */
    private function computeSchemaHash(array $schemaFiles): string
    {
        $content = '';
        foreach ($schemaFiles as $file) {
            $content .= (is_file($file) ? file_get_contents($file) : '') . "\x00";
            $dropsFile = dirname($file) . '/drops.sql';
            $content .= (is_file($dropsFile) ? file_get_contents($dropsFile) : '') . "\x00";
        }

        return hash('sha256', $content);
    }

    /**
     * @param array<string> $schemaFiles
     */
    private function schemaHashSettingKey(array $schemaFiles): string
    {
        return 'schema_hash_' . substr(hash('sha256', implode(',', $schemaFiles)), 0, 16);
    }

    /**
     * @param array<string> $schemaFiles
     */
    private function progressSettingKey(array $schemaFiles): string
    {
        return 'schema_migration_progress_' . substr(hash('sha256', implode(',', $schemaFiles)), 0, 16);
    }

    private function loadOrStartProgress(string $key, string $targetHash): MigrationProgress
    {
        $stored = $this->getStoredProgress($key);

        // A stored attempt for a DIFFERENT target hash means schema.sql
        // changed again while the previous attempt was still in progress —
        // that old, partial progress no longer describes a valid path to
        // the new target, so start over rather than resume it.
        return ($stored !== null && $stored->targetHash === $targetHash)
            ? $stored
            : MigrationProgress::start($targetHash);
    }

    private function getStoredProgress(string $key): ?MigrationProgress
    {
        $raw = $this->getStoredSetting($key);
        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true);

        // Malformed/corrupted JSON is treated the same as "no progress
        // yet" — never a fatal error over a corrupted cache row.
        return is_array($data) ? MigrationProgress::fromArray($data) : null;
    }

    private function saveProgress(string $key, MigrationProgress $progress): void
    {
        $this->upsertInternalSetting(
            $key,
            (string) json_encode($progress->toArray()),
            'Progression de migration de schéma en cours (interne)',
            'État intermédiaire d\'une migration d\'un ou plusieurs modules interrompue par le budget de temps ; supprimé automatiquement une fois la migration terminée.'
        );
    }

    private function clearProgress(string $key): void
    {
        try {
            $stmt = $this->connection->getPdo()->prepare(
                'DELETE FROM settings WHERE module_id IS NULL AND setting_key = ?'
            );
            $stmt->execute([$key]);
        } catch (\PDOException) {
            // Best-effort: a leftover progress row is harmless — it's keyed
            // to a specific schema-file content hash, and loadOrStartProgress()
            // already discards a stored row whose hash doesn't match, so a
            // stale row left behind here just becomes inert dead weight,
            // never a correctness problem.
        }
    }

    /**
     * Raw PDO, not SettingRepository/SettingService: this runs before the
     * `settings` table necessarily exists (a genuinely fresh install has
     * no tables at all yet, including this one), and SettingService's own
     * setInternal() requires the setting to already be register()'d, which
     * nothing here has occasion to do. Same "raw PDO before the usual
     * abstractions exist" precedent public/index.php already uses for its
     * one-time notifications_v2_migrated check.
     */
    private function getStoredSetting(string $key): ?string
    {
        try {
            $stmt = $this->connection->getPdo()->prepare(
                'SELECT setting_value FROM settings WHERE module_id IS NULL AND setting_key = ?'
            );
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (string) $value;
        } catch (\PDOException) {
            return null;
        }
    }

    private function saveHash(string $key, string $hash): void
    {
        $this->upsertInternalSetting(
            $key,
            $hash,
            'Empreinte du schéma migré (interne)',
            'Utilisée pour éviter de revérifier le schéma de la base de données à chaque page tant que schema.sql/drops.sql n\'ont pas changé depuis la dernière migration réussie.'
        );
    }

    /**
     * Portable SELECT-then-INSERT-or-UPDATE upsert into the internal
     * (module_id IS NULL) slice of `settings` — shared by hash caching and
     * migration-progress checkpointing. Deliberately not MySQL's
     * `INSERT ... ON DUPLICATE KEY UPDATE` (used here previously): that
     * syntax has no SQLite equivalent, and progress checkpointing in
     * particular needs to be exercisable by fast, local, non-`@group
     * database` tests against an in-memory SQLite connection, which
     * SchemaIntrospector's MySQL-only INFORMATION_SCHEMA queries otherwise
     * rule out for this class.
     */
    private function upsertInternalSetting(string $key, string $value, string $label, string $description): void
    {
        try {
            $pdo = $this->connection->getPdo();

            $checkStmt = $pdo->prepare('SELECT 1 FROM settings WHERE module_id IS NULL AND setting_key = ?');
            $checkStmt->execute([$key]);
            $exists = $checkStmt->fetchColumn() !== false;
            // With real (non-emulated) prepares, MySQL keeps this statement's
            // cursor "active" until every row is read or it's explicitly
            // closed — fetchColumn() alone doesn't do that when the result
            // has 0 or 1 rows, and $checkStmt is still in scope (the
            // function hasn't returned) when the INSERT/UPDATE below runs
            // on the same connection. Without this, that next query fails
            // with "Cannot execute queries while other unbuffered queries
            // are active" — MigrationRunner.php's own checkpoint() calls
            // this every unit of work, so this isn't a rare edge case.
            $checkStmt->closeCursor();

            if ($exists) {
                $updateStmt = $pdo->prepare(
                    'UPDATE settings SET setting_value = ? WHERE module_id IS NULL AND setting_key = ?'
                );
                $updateStmt->execute([$value, $key]);
                return;
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description, editable, sort_order)
                 VALUES (NULL, ?, ?, ?, \'text\', ?, ?, 0, 999)'
            );
            $insertStmt->execute([$key, $value, $value, $label, $description]);
        } catch (\PDOException) {
            // Best-effort, same rationale as before this refactor: worst
            // case the next request recomputes/re-checkpoints instead of
            // skipping/resuming — never worth failing an otherwise-
            // successful migration step over.
        }
    }

    /**
     * SchemaComparator deliberately never drops a column, FK constraint, or
     * table it finds in the database but not in the declared schema (a
     * data-loss safety net — see its class doc comment). This is the one
     * narrow, explicit exception: for each schema file, a sibling
     * drops.sql (e.g. schema/core.sql → schema/drops.sql) can declare
     * `ALTER TABLE <table> DROP COLUMN <column>;` or
     * `ALTER TABLE <table> DROP FOREIGN KEY <constraint>;` statements that
     * were hand-written and reviewed as part of the change that stopped
     * declaring that column/constraint. Each drop is only executed if the
     * column/constraint still exists, so this is idempotent and safe to
     * run on every request — a no-op once applied, and a no-op on fresh
     * installs that never had it.
     *
     * Kept atomic (not chunked/checkpointed) deliberately: drops.sql files
     * are small, hand-curated, and rare, unlike the potentially large
     * per-table introspection this class chunks elsewhere.
     *
     * @param array<string> $schemaFiles
     * @param array<string> $warnings
     * @return array<string> executed DROP statements
     */
    private function applyExplicitDrops(array $schemaFiles, \PDO $pdo, array &$warnings): array
    {
        $executed = [];

        foreach ($schemaFiles as $file) {
            $dropsFile = dirname($file) . '/drops.sql';
            foreach ($this->parser->parseDropsFile($dropsFile) as $drop) {
                if (isset($drop['column'])) {
                    $currentColumns = array_map(
                        fn(ColumnDefinition $c) => $c->name,
                        $this->introspector->getColumns($drop['table'])
                    );

                    if (!in_array($drop['column'], $currentColumns, true)) {
                        continue;
                    }

                    $statement = "ALTER TABLE `{$drop['table']}` DROP COLUMN `{$drop['column']}`";
                } else {
                    $currentConstraints = array_map(
                        fn(ForeignKeyDefinition $fk) => $fk->name,
                        $this->introspector->getForeignKeys($drop['table'])
                    );

                    if (!in_array($drop['constraint'], $currentConstraints, true)) {
                        continue;
                    }

                    $statement = "ALTER TABLE `{$drop['table']}` DROP FOREIGN KEY `{$drop['constraint']}`";
                }

                try {
                    $pdo->exec($statement);
                    $executed[] = $statement;
                } catch (\PDOException $e) {
                    $warnings[] = "Failed to execute: {$statement} — Error: {$e->getMessage()}";
                }
            }
        }

        return $executed;
    }

    /**
     * Attempt to create a database backup via Core\Database\DatabaseDumper
     * (ifsnop/mysqldump-php) — a pure-PHP dump over PDO, no `mysqldump`
     * binary or shell-execution function required.
     *
     * @param array<string> $warnings
     */
    private function attemptBackup(array &$warnings): bool
    {
        $backupDir = dirname(__DIR__, 2) . '/storage/temp';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';

        // Get connection details via reflection (they are private)
        $host = $this->getPrivateProperty('host');
        $port = $this->getPrivateProperty('port');
        $dbName = $this->getPrivateProperty('dbName');
        $user = $this->getPrivateProperty('user');
        $password = $this->getPrivateProperty('password');

        try {
            DatabaseDumper::dump($host, $port, $dbName, $user, $password, $backupFile);
        } catch (\Throwable $e) {
            $warnings[] = 'Database backup failed — proceeding without backup. (' . $e->getMessage() . ')';
            @unlink($backupFile);
            return false;
        }

        return true;
    }

    private function getPrivateProperty(string $property): mixed
    {
        $reflection = new \ReflectionClass($this->connection);
        $prop = $reflection->getProperty($property);
        return $prop->getValue($this->connection);
    }
}
