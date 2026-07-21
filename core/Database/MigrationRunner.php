<?php

declare(strict_types=1);

namespace Core\Database;

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
        $warnings = [];

        // Step 1: Attempt backup
        $backupCreated = $this->attemptBackup($warnings);

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
        return new MigrationResult(
            executedStatements: $executedStatements,
            warnings: $warnings,
            backupCreated: $backupCreated
        );
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
        // Check if mysqldump is available
        $output = [];
        $returnCode = 0;
        @exec('which mysqldump 2>/dev/null', $output, $returnCode);

        if ($returnCode !== 0) {
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

        $command = sprintf(
            'mysqldump -h %s -P %s -u %s %s %s > %s 2>/dev/null',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($user),
            $password !== '' ? '-p' . escapeshellarg($password) : '',
            escapeshellarg($dbName),
            escapeshellarg($backupFile)
        );

        @exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $warnings[] = 'Database backup failed — proceeding without backup.';
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
