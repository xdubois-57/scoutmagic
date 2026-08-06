<?php

declare(strict_types=1);

namespace Core\Database;

use Core\System\ExecutableLocator;

class MigrationRunner
{
    public function __construct(
        private Connection $connection,
        private SchemaIntrospector $introspector,
        private SchemaComparator $comparator,
        private SqlParser $parser
    ) {
    }

    /**
     * Run the full migration:
     * 0. Skip everything if schema.sql/drops.sql haven't changed since the
     *    last clean run (see computeSchemaHash()/getStoredHash()) — this
     *    runs on every single page load in production, and re-introspecting
     *    the entire live schema (several INFORMATION_SCHEMA queries per
     *    table) to conclude "nothing to do" is pure waste on every request
     *    that isn't immediately after a real schema change.
     * 1. Backup the database (mysqldump via exec, skip if not available).
     * 2. Parse all schema files.
     * 3. Introspect the current database.
     * 4. Compare and generate DDL.
     * 5. Execute DDL statements.
     * 6. Apply explicit, reviewed column/constraint drops (see applyExplicitDrops()).
     * 7. Return a MigrationResult.
     *
     * @param array<string> $schemaFiles
     */
    public function migrate(array $schemaFiles): MigrationResult
    {
        $hashKey = $this->schemaHashSettingKey($schemaFiles);
        $currentHash = $this->computeSchemaHash($schemaFiles);

        if ($this->getStoredHash($hashKey) === $currentHash) {
            return new MigrationResult(executedStatements: [], warnings: [], backupCreated: false);
        }

        $warnings = [];

        // Step 1: Attempt backup
        $backupCreated = $this->attemptBackup($warnings);
        $backupWarningCount = count($warnings);

        // Step 2: Parse all schema files
        $declaredTables = [];
        foreach ($schemaFiles as $file) {
            $tables = $this->parser->parseFile($file);
            array_push($declaredTables, ...$tables);
        }

        // Step 3: Introspect current database
        $actualTables = $this->introspector->getAllTableDefinitions();

        // Step 4: Compare and generate DDL
        $statements = $this->comparator->compare($declaredTables, $actualTables);
        $comparatorWarnings = $this->comparator->getWarnings();
        array_push($warnings, ...$comparatorWarnings);

        // Step 5: Execute DDL statements
        $executedStatements = [];
        $pdo = $this->connection->getPdo();

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $executedStatements[] = $statement;
            } catch (\PDOException $e) {
                $warnings[] = "Failed to execute: {$statement} — Error: {$e->getMessage()}";
            }
        }

        // Step 6: Apply explicit, reviewed column drops
        $dropStatements = $this->applyExplicitDrops($schemaFiles, $pdo, $warnings);
        array_push($executedStatements, ...$dropStatements);

        // Step 7: Return result
        $result = new MigrationResult(
            executedStatements: $executedStatements,
            warnings: $warnings,
            backupCreated: $backupCreated
        );

        // Only schema-related warnings (comparator + failed statements/
        // drops) should block caching "nothing left to do" — a backup
        // hiccup doesn't mean the schema itself is inconsistent, and
        // blocking the cache on it would defeat the optimization on
        // exactly the hosts where mysqldump is flaky. A crash between here
        // and the next request just leaves the old hash in place, so the
        // next request re-checks in full — self-healing, never masked.
        $schemaWarningCount = count($warnings) - $backupWarningCount;
        if (!$result->hasChanges() && $schemaWarningCount === 0) {
            $this->saveHash($hashKey, $currentHash);
        }

        return $result;
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
     * Raw PDO, not SettingRepository/SettingService: this runs before the
     * `settings` table necessarily exists (a genuinely fresh install has
     * no tables at all yet, including this one), and SettingService's own
     * setInternal() requires the setting to already be register()'d, which
     * nothing here has occasion to do. Same "raw PDO before the usual
     * abstractions exist" precedent public/index.php already uses for its
     * one-time notifications_v2_migrated check.
     */
    private function getStoredHash(string $key): ?string
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
        try {
            $stmt = $this->connection->getPdo()->prepare(
                'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description, editable, sort_order)
                 VALUES (NULL, ?, ?, ?, \'text\', ?, ?, 0, 999)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $stmt->execute([
                $key,
                $hash,
                $hash,
                'Empreinte du schéma migré (interne)',
                'Utilisée pour éviter de revérifier le schéma de la base de données à chaque page tant que schema.sql/drops.sql n\'ont pas changé depuis la dernière migration réussie.',
            ]);
        } catch (\PDOException) {
            // Best-effort: worst case the next request re-checks in full,
            // never worth failing an otherwise-successful migration over.
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
     * Attempt to create a database backup using mysqldump.
     *
     * @param array<string> $warnings
     */
    private function attemptBackup(array &$warnings): bool
    {
        if (!ExecutableLocator::isExecAvailable()) {
            $warnings[] = 'No PHP shell-execution function (exec, shell_exec, system, passthru) is available on this server (disable_functions) — skipping backup. Proceed with caution.';
            return false;
        }
        $mysqldumpBin = ExecutableLocator::find('mysqldump');
        if ($mysqldumpBin === null) {
            $warnings[] = 'mysqldump not available — skipping backup. Proceed with caution.';
            return false;
        }

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

        // Some hosts silently drop the outbound TCP connection a shell-exec'd
        // mysqldump process opens even though PHP's own PDO connection to
        // the same database is instant — bounded so a stuck backup attempt
        // fails fast instead of making "Installer" hang for minutes. `timeout`
        // is standard on Linux (the only place this runs in production) but
        // missing on macOS/BSD dev machines, so it's only used when present.
        $timeoutBin = ExecutableLocator::find('timeout');
        $timeoutPrefix = $timeoutBin !== null ? escapeshellarg($timeoutBin) . ' 30 ' : '';

        // `2>&1 1>path` (not `>path 2>&1`) keeps stderr in exec()'s $output
        // capture while stdout — the actual dump content — still goes to
        // the file.
        $command = sprintf(
            '%s%s -h %s -P %s -u %s %s %s 2>&1 1>%s',
            $timeoutPrefix,
            escapeshellarg($mysqldumpBin),
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($user),
            $password !== '' ? '-p' . escapeshellarg($password) : '',
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );

        $result = \Core\System\ShellExecutor::run($command);
        $returnCode = $result['returnCode'];

        if ($returnCode !== 0) {
            $detail = trim($result['output']);
            $warnings[] = $returnCode === 124
                ? 'Database backup timed out — proceeding without backup.'
                : 'Database backup failed — proceeding without backup.' . ($detail !== '' ? ' (' . $detail . ')' : '');
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
