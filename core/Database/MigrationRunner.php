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
     * 6. Return a MigrationResult.
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

        // Step 6: Return result
        return new MigrationResult(
            executedStatements: $executedStatements,
            warnings: $warnings,
            backupCreated: $backupCreated
        );
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
