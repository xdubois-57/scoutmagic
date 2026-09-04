<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

use Core\Journal\JournalService;

class MigrationRunner
{
    /**
     * How many consecutive passes may fail in exactly the same way before
     * the attempt is abandoned.
     *
     * Three, not one: a first failure can be a race with another process,
     * and a second can be the same race. Three identical passes is not bad
     * luck, it is a statement the database will not accept — and going
     * round a fourth time only keeps the site on the progress page.
     */
    public const CONVERGENCE_ATTEMPTS = 3;

    /**
     * Internal setting recording an abandoned migration, so the failure is
     * visible on the Maintenance page rather than only in the journal.
     */
    public const ABANDONED_SETTING = 'schema_migration_abandoned';

    /**
     * Shortest interval between two checkpoint WRITES, in seconds.
     *
     * checkpoint() is called after every statement, which used to mean one
     * UPSERT into `settings` per DDL statement. On the reference
     * installation that is 139 writes per pass, for the cosmetic MODIFY
     * COLUMNs MariaDB reports as needed on every run — 139 round trips to
     * persist a value nothing reads until the pass ends.
     *
     * Spacing them is only safe because of what a checkpoint now carries.
     * Since the re-diff landed, it holds no queue: the live schema is the
     * state, and every pass regenerates exactly the statements still
     * missing. What a skipped write loses is the accumulated list of
     * executed statements and the progress-bar denominator — reporting,
     * not correctness. A pass killed mid-way still resumes correctly,
     * because it resumes from the database, not from this row.
     */
    private const CHECKPOINT_MIN_INTERVAL_SECONDS = 0.5;

    /**
     * When saveProgress() last actually wrote, as a microtime(true).
     * Kept on the instance rather than per-migrate() call, for the same
     * reason as $introspectionCache: ModuleManager shares one runner
     * across the core schema and every module's, so the write rate that
     * matters is the one across all of them, not within each.
     */
    private float $lastCheckpointAt = 0.0;

    /**
     * @param JournalService|null $journal Used for the one thing this class
     *   does that a `security`-level journal entry has to record: an
     *   explicit drop from a drops.sql actually removing a column, a
     *   foreign key or a table (see applyExplicitDrops()). Nullable
     *   because migrate() also runs where no journal can exist yet — a
     *   fresh install, whose `event_log` table is created by the very
     *   migration being run.
     */
    /**
     * Every table's introspected definition, read in bulk and kept on the
     * instance — deliberately BEYOND a single migrate() call, because
     * Core\Module\ModuleManager shares one MigrationRunner across the
     * core schema and every module's, and re-reading the live schema for
     * each was most of the cost of a migration. Null means "not read
     * yet"; invalidated the moment any DDL executes, since the schema it
     * describes has just changed.
     *
     * @var array<string, TableDefinition>|null
     */
    private ?array $introspectionCache = null;

    public function __construct(
        private Connection $connection,
        private SchemaIntrospector $introspector,
        private SchemaComparator $comparator,
        private SqlParser $parser,
        private int $timeBudgetSeconds = 20,
        private ?JournalService $journal = null
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
     * 1. Build the queue of declared tables to diff (cheap: one
     *    INFORMATION_SCHEMA.TABLES query, no per-table introspection yet).
     * 2. Diff one declared table at a time, introspecting it only as it's
     *    dequeued — this is the expensive part (3 INFORMATION_SCHEMA
     *    queries per table) that used to run for the whole database in one
     *    uninterruptible pass.
     * 3. Execute one generated DDL statement at a time.
     * 4. Apply explicit, reviewed column/constraint drops (see
     *    applyExplicitDrops()) — kept atomic, see that method's doc comment.
     * 5. Return a MigrationResult.
     *
     * This method does NOT back the database up first. It used to, on
     * every call, before even knowing whether there was any DDL to apply —
     * a full ifsnop/mysqldump-php dump of the whole database, repeated
     * once per schema file set. Every path that can change a schema file
     * on disk already takes its own safety backup first (Core\Maintenance\
     * Task\InstallUpdateHandler, Core\Maintenance\Task\
     * RestoreBackupHandler) or has nothing to save yet (bootstrap.php on a
     * fresh install), so the dump here was a duplicate of one taken
     * moments earlier — and the single largest cost of a migration.
     *
     * After every unit of work in steps 1–3, progress is checkpointed to
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
            return new MigrationResult(executedStatements: [], warnings: []);
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
            // Not 0.0: another process is doing the work, and reporting
            // zero would peg the progress bar at nothing for every visitor
            // watching while it advances. Report what was actually
            // checkpointed.
            $stored = $this->getStoredProgress($this->progressSettingKey($schemaFiles));

            return new MigrationResult(
                executedStatements: [],
                warnings: [],
                complete: false,
                progressFraction: $stored !== null ? $this->progressFraction($stored) : 0.0
            );
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
        return $this->getStoredSetting(
            $this->schemaHashSettingKey($schemaFiles)
        ) !== $this->computeSchemaHash($schemaFiles);
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

        // Parsing schema files is cheap, local, and deterministic given
        // the same (unchanged, per the hash check above) file content — no
        // need to persist the parsed result, just reparse every call.
        $declaredByName = [];
        foreach ($schemaFiles as $file) {
            foreach ($this->parser->parseFile($file) as $table) {
                $declaredByName[$table->name] = $table;
            }
        }

        // Step 1: diff the whole declared schema against the live one,
        // from scratch, on EVERY pass.
        //
        // This is what replaced a persisted queue of pending statements,
        // and it is the fix for a defect that could strand a site forever:
        // the queue was popped before the statement ran, so an interrupted
        // pass replayed it, collected "Duplicate column name", and never
        // converged. Re-diffing cannot have that problem — a statement
        // that already ran is simply not generated again, because the
        // column it adds is now there. The database is the checkpoint.
        //
        // Affordable precisely because introspection is now three
        // INFORMATION_SCHEMA queries for the whole schema rather than
        // three per table.
        $pending = $this->diffAgainstLiveSchema($declaredByName, $progress);

        if ($progress->totalStatementCount === 0) {
            $progress->totalStatementCount = count($pending);
        }
        $progress->remainingStatementCount = count($pending);

        // Step 2: execute, one statement at a time, within the budget.
        $failures = [];
        foreach ($pending as $index => $statement) {
            try {
                $pdo->exec($statement);
                $progress->executedStatements[] = $statement;
                // The live schema just changed; anything cached about it
                // is now a description of the past.
                $this->introspectionCache = null;
            } catch (\PDOException $e) {
                if (self::isAlreadyApplied($e)) {
                    // Not a failure: the database already has what this
                    // statement would have added. Reached when the memo
                    // was stale, or when two processes raced — never a
                    // reason to stop the schema converging.
                    $this->introspectionCache = null;
                } else {
                    $failures[] = $statement;
                    $progress->warnings[] = "Failed to execute: {$statement} — Error: {$e->getMessage()}";
                }
            }

            $progress->remainingStatementCount = count($pending) - $index - 1;

            if (($incomplete = $this->checkpoint($progressKey, $progress, $deadline)) !== null) {
                return $incomplete;
            }
        }

        // Step 3: apply explicit, reviewed column/constraint drops.
        if (!$progress->dropsApplied) {
            $dropWarnings = [];
            $dropStatements = $this->applyExplicitDrops($schemaFiles, $pdo, $dropWarnings);
            array_push($progress->executedStatements, ...$dropStatements);
            array_push($progress->warnings, ...$dropWarnings);
            $progress->dropsApplied = true;
        }

        // Step 4: decide whether this attempt is finished.
        $converged = $failures === [];
        $this->recordFailureSignature($progress, $failures);
        $abandoned = !$converged && $progress->sameFailureCount >= self::CONVERGENCE_ATTEMPTS;

        if (!$converged && !$abandoned) {
            // Still failing, but the failures are new or the ceiling is not
            // reached: keep the attempt open so the next pass retries. The
            // hash stays uncached, so isPending() remains true.
            $this->saveProgress($progressKey, $progress);

            return new MigrationResult(
                executedStatements: $progress->executedStatements,
                warnings: $progress->warnings,
                complete: false,
                progressFraction: $this->progressFraction($progress),
                converged: false
            );
        }

        // Hash caching: save it so the next request skips migration
        // entirely. Critical on MariaDB hosts, where type-reporting
        // differences (int(10) unsigned vs int unsigned, COLUMN_DEFAULT
        // coming back as the string 'NULL') make the comparator generate
        // the same cosmetic MODIFY COLUMN statements on every single pass.
        // Those succeed, so they never block caching — but without the
        // cache they would re-run on every page load forever.
        //
        // Saved on abandonment too, deliberately. A migration that cannot
        // converge has to stop asking: a site held on the progress page
        // indefinitely is a worse outcome than a schema missing a column,
        // and the journal entry plus the persistent banner below are what
        // make the second outcome visible instead of silent.
        $this->saveHash($hashKey, $currentHash);
        $this->clearProgress($progressKey);

        if ($abandoned) {
            $this->recordAbandonedMigration($failures);
        } else {
            // A clean pass retires the banner a previous abandonment left
            // behind: whatever was wrong has been fixed, and a warning
            // nobody can clear is a warning everybody learns to ignore.
            $this->clearAbandonedMigration();
        }

        return new MigrationResult(
            executedStatements: $progress->executedStatements,
            warnings: $progress->warnings,
            complete: true,
            progressFraction: 1.0,
            converged: $converged
        );
    }

    /**
     * Every statement the live schema still needs, generated fresh.
     *
     * @param array<string, TableDefinition> $declaredByName
     * @return array<string>
     */
    private function diffAgainstLiveSchema(array $declaredByName, MigrationProgress $progress): array
    {
        $live = $this->introspectedTables();
        $pending = [];

        foreach ($declaredByName as $name => $declared) {
            // compareOneDeclaredTable() accumulates onto the comparator's
            // internal warning list rather than resetting it (that reset
            // only happens in compare(), the bulk entry point this class
            // no longer calls) — take just the slice this table added.
            $warningCountBefore = count($this->comparator->getWarnings());
            $statements = $this->comparator->compareOneDeclaredTable($declared, $live[$name] ?? null);
            $newWarnings = array_slice($this->comparator->getWarnings(), $warningCountBefore);

            array_push($pending, ...$statements);
            array_push($progress->warnings, ...$newWarnings);
        }

        return $pending;
    }

    /**
     * Track how many consecutive passes have failed in exactly the same
     * way. A different set of failures means progress of a kind — the
     * previous ones were resolved — so the counter restarts.
     *
     * @param array<string> $failures
     */
    private function recordFailureSignature(MigrationProgress $progress, array $failures): void
    {
        if ($failures === []) {
            $progress->failureSignature = '';
            $progress->sameFailureCount = 0;

            return;
        }

        sort($failures);
        $signature = hash('sha256', implode("\n", $failures));

        if ($signature === $progress->failureSignature) {
            $progress->sameFailureCount++;

            return;
        }

        $progress->failureSignature = $signature;
        $progress->sameFailureCount = 1;
    }

    /**
     * Make an abandoned migration loud rather than silent: a `security`
     * journal entry, and a persistent setting the Maintenance page renders
     * as a banner. Statement text only — a DDL statement names tables and
     * columns, never a row's contents.
     *
     * @param array<string> $failures
     */
    private function recordAbandonedMigration(array $failures): void
    {
        $description = 'Migration de schéma abandonnée : les mêmes instructions échouent à chaque tentative';

        try {
            $this->journal?->log('core', 'schema_migration_abandoned', 'security', $description, [
                'failed_statements' => count($failures),
                'first_failure' => $failures[0] ?? '',
            ]);
        } catch (\Throwable) {
            // Same reasoning as journalDrop(): never turn a bookkeeping
            // problem into a migration failure.
        }

        $this->upsertInternalSetting(
            self::ABANDONED_SETTING,
            (string) json_encode(
                (new AbandonedMigration(
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    array_values($failures)
                ))->toArray()
            ),
            'Migration de schéma non convergée (interne)',
            'Renseigné quand une migration a été abandonnée après plusieurs tentatives identiquement '
            . 'infructueuses ; la page Maintenance en affiche un bandeau. Vidé automatiquement dès '
            . 'qu\'une migration se termine sans échec.'
        );
    }

    private function clearAbandonedMigration(): void
    {
        try {
            $stmt = $this->connection->getPdo()->prepare(
                'DELETE FROM settings WHERE module_id IS NULL AND setting_key = ?'
            );
            $stmt->execute([self::ABANDONED_SETTING]);
        } catch (\PDOException) {
            // Best-effort, like clearProgress(): a leftover row shows a
            // stale banner, never a broken migration.
        }
    }

    /**
     * Whether an abandoned migration is on record — what the Maintenance
     * page's banner is driven by. Null when the last migration converged.
     */
    public function abandonedMigration(): ?AbandonedMigration
    {
        return AbandonedMigration::fromJson($this->getStoredSetting(self::ABANDONED_SETTING));
    }

    /**
     * MySQL/MariaDB error codes meaning "the database already has this".
     *
     * A statement that is a no-op because its effect is already present is
     * not a failure — it is the normal shape of a resumed migration, and
     * of two processes that raced. Treating it as a failure is exactly
     * what used to stop a schema converging. A syntax error, a bad type, a
     * missing referenced table: those stay failures.
     */
    private static function isAlreadyApplied(\PDOException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [
            1050, // table already exists
            1060, // duplicate column name
            1061, // duplicate key name
            1826, // duplicate foreign key constraint name
        ], true);
    }

    /**
     * Persist $progress and, if the time budget has been exceeded, return
     * the incomplete MigrationResult the caller should return immediately.
     * Returns null when there's still budget left, meaning the caller
     * should carry on to the next unit of work.
     *
     * The write is rate-limited (CHECKPOINT_MIN_INTERVAL_SECONDS) but the
     * one that matters is not: a pass leaving on its budget always writes
     * before it goes, so the row the next pass reads is never stale by
     * more than one skipped interval of purely informational state.
     */
    private function checkpoint(string $progressKey, MigrationProgress $progress, float $deadline): ?MigrationResult
    {
        $now = microtime(true);
        $outOfBudget = $now >= $deadline;

        if ($outOfBudget || ($now - $this->lastCheckpointAt) >= self::CHECKPOINT_MIN_INTERVAL_SECONDS) {
            $this->saveProgress($progressKey, $progress);
        }

        if (!$outOfBudget) {
            return null;
        }

        return new MigrationResult(
            executedStatements: $progress->executedStatements,
            warnings: $progress->warnings,
            complete: false,
            progressFraction: $this->progressFraction($progress)
        );
    }

    /**
     * Rough 0.0–1.0 estimate for the migration-in-progress page's progress
     * bar: two equally-weighted phases (statement execution, explicit
     * drops). The diff is no longer one of them — it is three queries for
     * the whole schema, done in one go at the start of every pass rather
     * than chunked across several.
     *
     * Deliberately approximate: statement costs vary wildly (a one-column
     * ALTER against a big CREATE TABLE), so this is a "does the bar move"
     * indicator, not a time estimate.
     */
    private function progressFraction(MigrationProgress $progress): float
    {
        $executeFraction = $progress->totalStatementCount > 0
            ? ($progress->totalStatementCount - $progress->remainingStatementCount) / $progress->totalStatementCount
            : 1.0;

        $dropsFraction = $progress->dropsApplied ? 1.0 : 0.0;

        return (max(0.0, min(1.0, $executeFraction)) + $dropsFraction) / 2;
    }

    /**
     * @see migrate()'s doc comment for why this exists. The lock's own
     * rules — timeout 0, the SQLite fallback, ::query() to release — live
     * in AdvisoryLock, shared with Maintenance\InstallLock and
     * Scheduler\CronPassLock.
     */
    private const LOCK_NAME = 'scoutmagic_schema_migration';

    private function acquireMigrationLock(): bool
    {
        return AdvisoryLock::acquire($this->connection->getPdo(), self::LOCK_NAME);
    }

    private function releaseMigrationLock(): void
    {
        AdvisoryLock::release($this->connection->getPdo(), self::LOCK_NAME);
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
        return 'schema_hash_' . $this->schemaFilesIdentity($schemaFiles);
    }

    /**
     * @param array<string> $schemaFiles
     */
    private function progressSettingKey(array $schemaFiles): string
    {
        return 'schema_migration_progress_' . $this->schemaFilesIdentity($schemaFiles);
    }

    /**
     * Which schema files this is a migration OF — the identity both the
     * hash cache and the resumable progress row are keyed on.
     *
     * Canonicalised, because the callers do not spell the same file the
     * same way and one file must be one migration. `public/index.php`
     * passes `<root>/public/../schema/core.sql`; `Task\
     * InstallUpdateHandler` and `Task\RestoreBackupHandler` pass
     * `<root>/schema/core.sql` (dirname of the storage path). Keyed on the
     * raw strings, those are two `schema_hash_` rows and two
     * `schema_migration_progress_` rows for one file — so an update would
     * finish migrating, cache its hash, and then the very next page load
     * would ask the other key, still find the old hash, and serve the
     * migration-in-progress page: re-running through the browser the work
     * the update had just done, and resuming neither side's checkpoint.
     *
     * realpath() answers false for a file that does not exist. That is not
     * an error here — computeSchemaHash() already treats a missing schema
     * file as empty content — so the raw string stands in, which keeps the
     * key stable for a caller naming a file that is not there.
     *
     * @param array<string> $schemaFiles
     */
    private function schemaFilesIdentity(array $schemaFiles): string
    {
        $canonical = array_map(
            static function (string $file): string {
                $real = realpath($file);

                return $real !== false ? $real : $file;
            },
            $schemaFiles
        );

        return substr(hash('sha256', implode(',', $canonical)), 0, 16);
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
        $this->lastCheckpointAt = microtime(true);
        $this->upsertInternalSetting(
            $key,
            (string) json_encode($progress->toArray()),
            'Progression de migration de schéma en cours (interne)',
            'État intermédiaire d\'une migration d\'un ou plusieurs modules interrompue par le budget de temps ; '
                . 'supprimé automatiquement une fois la migration terminée.'
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
            'Utilisée pour éviter de revérifier le schéma de la base de données à chaque page tant que '
                . 'schema.sql/drops.sql n\'ont pas changé depuis la dernière migration réussie.'
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
                'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, '
                    . 'description, editable, sort_order)
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
     * `ALTER TABLE <table> DROP COLUMN <column>;`,
     * `ALTER TABLE <table> DROP FOREIGN KEY <constraint>;` or
     * `DROP TABLE <table>;` statements that were hand-written and
     * reviewed as part of the change that stopped declaring that
     * column/constraint/table. Each drop is only executed if the
     * column/constraint/table still exists, so this is idempotent and safe
     * to run on every request — a no-op once applied, and a no-op on fresh
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
                if (isset($drop['drop_table'])) {
                    if (!in_array($drop['table'], $this->introspector->getTables(), true)) {
                        continue;
                    }

                    $statement = "DROP TABLE `{$drop['table']}`";
                } elseif (isset($drop['column'])) {
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
                    $this->introspectionCache = null;
                    $this->journalDrop($drop);
                } catch (\PDOException $e) {
                    $warnings[] = "Failed to execute: {$statement} — Error: {$e->getMessage()}";
                }
            }
        }

        return $executed;
    }

    /**
     * Every table in the database, introspected once and reused until a
     * DDL statement invalidates it.
     *
     * Reads the whole schema rather than only the tables one caller asked
     * about, so a later call for a different set — the next module's
     * schema, on the shared instance — is answered from the same cache
     * instead of paying for another round.
     *
     * @return array<string, TableDefinition>
     */
    private function introspectedTables(): array
    {
        if ($this->introspectionCache === null) {
            $this->introspectionCache = $this->introspector->getTableDefinitions(
                $this->introspector->getTables()
            );
        }

        return $this->introspectionCache;
    }

    /**
     * Record an explicit drop that actually removed something. Only
     * reached after the statement executed: a drop whose column/table is
     * already gone is skipped before this point, so the journal shows
     * what genuinely disappeared from this installation rather than
     * repeating every line of drops.sql on every migration.
     *
     * Table and column/constraint names only — never a value, never a row
     * count (AGENTS.md § Security checklist: no personal data in the
     * journal). `security` level: this is the one place in the whole
     * migration path that destroys data.
     *
     * @param array<string, string> $drop
     */
    private function journalDrop(array $drop): void
    {
        if ($this->journal === null) {
            return;
        }

        if (isset($drop['drop_table'])) {
            $description = 'Suppression de table appliquée depuis drops.sql';
            $context = ['table' => $drop['table']];
        } elseif (isset($drop['column'])) {
            $description = 'Suppression de colonne appliquée depuis drops.sql';
            $context = ['table' => $drop['table'], 'column' => $drop['column']];
        } else {
            $description = 'Suppression de clé étrangère appliquée depuis drops.sql';
            $context = ['table' => $drop['table'], 'constraint' => $drop['constraint']];
        }

        try {
            $this->journal->log('core', 'schema_drop_executed', 'security', $description, $context);
        } catch (\Throwable) {
            // Best-effort, and deliberately so: `event_log` is itself one
            // of the tables this migration may still be creating, and a
            // drop that succeeded must never be turned into a failed
            // migration by the attempt to record it.
        }
    }
}
