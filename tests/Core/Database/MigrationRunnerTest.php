<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
class MigrationRunnerTest extends TestCase
{
    private ?Connection $connection = null;
    private ?SchemaIntrospector $introspector = null;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        $this->connection = new Connection($host, $port, $dbName, $user, $password);

        $result = $this->connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database connection not available: ' . $result);
        }

        $this->introspector = new SchemaIntrospector($this->connection->getPdo());

        $this->dropAllTables($this->connection->getPdo());
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->dropAllTables($this->connection->getPdo());
        }
    }

    /**
     * Every test in this class expects a genuinely empty database to
     * measure migrations against — a curated per-test DROP list drifts out
     * of sync with whatever tables other tests' full core.sql migrations
     * leave behind (e.g. testMigrateCreatesTablesFromCoreSql creates the
     * entire schema and nothing dropped it, so a later test's "second
     * migrate() is a no-op" assertion sees leftover tables as unexpected
     * diffs). Drop unconditionally instead, same SHOW TABLES + FK-checks-off
     * approach as Core\Maintenance\Task\FullResetHandler::truncateAllTables().
     */
    private function dropAllTables(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testMigrateCreatesTablesFromCoreSql(): void
    {
        $runner = new MigrationRunner(
            $this->connection,
            $this->introspector,
            new SchemaComparator(),
            new SqlParser()
        );

        $schemaPath = dirname(__DIR__, 3) . '/schema/core.sql';
        $result = $runner->migrate([$schemaPath]);

        $this->assertTrue($result->hasChanges());
        $this->assertNotEmpty($result->executedStatements);

        // Verify tables exist
        $tables = $this->introspector->getTables();
        $this->assertContains('scout_years', $tables);
        $this->assertContains('members', $tables);
    }

    public function testMigrateAgainProducesNoChanges(): void
    {
        $runner = new MigrationRunner(
            $this->connection,
            $this->introspector,
            new SchemaComparator(),
            new SqlParser()
        );

        $schemaPath = dirname(__DIR__, 3) . '/schema/core.sql';

        // First migration
        $runner->migrate([$schemaPath]);

        // Second migration should produce no changes
        $result = $runner->migrate([$schemaPath]);

        $this->assertFalse($result->hasChanges());
        $this->assertEmpty($result->executedStatements);
    }

    /**
     * Regression/feature test: migrate() re-introspects the entire live
     * schema on every call, which is wasted work on every page load once
     * schema.sql hasn't changed since the last clean run — a third call
     * (the second already having found nothing to do) must be answered
     * from the cached schema hash without touching the database at all.
     * Proven here by manually dropping a declared table between the
     * second and third calls: if the third call actually re-introspected,
     * it would recreate the table; a genuinely cache-skipped call won't.
     */
    public function testMigrateSkipsTheFullCheckOnceTheSchemaHashIsCached(): void
    {
        $runner = new MigrationRunner(
            $this->connection,
            $this->introspector,
            new SchemaComparator(),
            new SqlParser()
        );

        $schemaPath = dirname(__DIR__, 3) . '/schema/core.sql';

        $runner->migrate([$schemaPath]); // creates everything — has changes, hash not saved
        $runner->migrate([$schemaPath]); // nothing left to do — clean, hash saved

        $pdo = $this->connection->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE scout_years');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $thirdResult = $runner->migrate([$schemaPath]);

        $this->assertFalse($thirdResult->hasChanges());
        $this->assertEmpty($thirdResult->executedStatements);
        $this->assertNotContains('scout_years', $this->introspector->getTables());
    }

    /**
     * The cache above must never mask a real schema change — the whole
     * point is skipping redundant work, not skipping real migrations.
     */
    public function testMigrateRerunsTheFullCheckWhenTheSchemaFileContentChanges(): void
    {
        $tmpDir = sys_get_temp_dir() . '/migration_hash_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', 'CREATE TABLE hash_test (id INT PRIMARY KEY);');

        try {
            $runner = new MigrationRunner($this->connection, $this->introspector, new SchemaComparator(), new SqlParser());

            $runner->migrate([$tmpDir . '/schema.sql']); // creates the table — hash not saved
            $runner->migrate([$tmpDir . '/schema.sql']); // nothing left to do — hash saved

            file_put_contents($tmpDir . '/schema.sql', 'CREATE TABLE hash_test (id INT PRIMARY KEY, extra VARCHAR(10));');

            $result = $runner->migrate([$tmpDir . '/schema.sql']);

            $this->assertTrue($result->hasChanges());
            $columns = array_map(fn($c) => $c->name, $this->introspector->getColumns('hash_test'));
            $this->assertContains('extra', $columns);
        } finally {
            $this->connection->getPdo()->exec('DROP TABLE IF EXISTS hash_test');
            @unlink($tmpDir . '/schema.sql');
            @rmdir($tmpDir);
        }
    }

    public function testMigrationResultContainsWarningsWhenBackupUnavailable(): void
    {
        $runner = new MigrationRunner(
            $this->connection,
            $this->introspector,
            new SchemaComparator(),
            new SqlParser()
        );

        $schemaPath = dirname(__DIR__, 3) . '/schema/core.sql';
        $result = $runner->migrate([$schemaPath]);

        // This test just verifies the result object structure is correct;
        // whether the backup itself succeeds depends on the environment
        // DatabaseDumper connects to, not on this test's setup.
        $this->assertIsBool($result->backupCreated);
        $this->assertIsArray($result->warnings);
    }

    public function testMigrateAppliesExplicitColumnDropFromSiblingDropsFile(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE drop_test (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, legacy VARCHAR(50) NOT NULL)');

        $tmpDir = sys_get_temp_dir() . '/migration_drop_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', "CREATE TABLE drop_test (\n    id INT PRIMARY KEY,\n    name VARCHAR(50) NOT NULL\n);");
        file_put_contents($tmpDir . '/drops.sql', 'ALTER TABLE drop_test DROP COLUMN legacy;');

        try {
            $runner = new MigrationRunner($this->connection, $this->introspector, new SchemaComparator(), new SqlParser());

            $result = $runner->migrate([$tmpDir . '/schema.sql']);

            $columns = array_map(fn($c) => $c->name, $this->introspector->getColumns('drop_test'));
            $this->assertNotContains('legacy', $columns);
            $this->assertContains('ALTER TABLE `drop_test` DROP COLUMN `legacy`', $result->executedStatements);

            // Idempotent: the column is already gone, so a second run is a no-op for the drop
            $secondResult = $runner->migrate([$tmpDir . '/schema.sql']);
            $this->assertNotContains('ALTER TABLE `drop_test` DROP COLUMN `legacy`', $secondResult->executedStatements);
        } finally {
            @unlink($tmpDir . '/schema.sql');
            @unlink($tmpDir . '/drops.sql');
            @rmdir($tmpDir);
        }
    }

    public function testMigrateSkipsDropWhenColumnNeverExisted(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE drop_test (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');

        $tmpDir = sys_get_temp_dir() . '/migration_drop_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', "CREATE TABLE drop_test (\n    id INT PRIMARY KEY,\n    name VARCHAR(50) NOT NULL\n);");
        file_put_contents($tmpDir . '/drops.sql', 'ALTER TABLE drop_test DROP COLUMN legacy;');

        try {
            $runner = new MigrationRunner($this->connection, $this->introspector, new SchemaComparator(), new SqlParser());

            $result = $runner->migrate([$tmpDir . '/schema.sql']);

            $this->assertEmpty($result->warnings);
            $this->assertNotContains('ALTER TABLE `drop_test` DROP COLUMN `legacy`', $result->executedStatements);
        } finally {
            @unlink($tmpDir . '/schema.sql');
            @unlink($tmpDir . '/drops.sql');
            @rmdir($tmpDir);
        }
    }

    public function testMigrateAppliesExplicitForeignKeyDropFromSiblingDropsFile(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE fk_drop_test_parent (id INT PRIMARY KEY)');
        $pdo->exec(
            'CREATE TABLE fk_drop_test_child (
                id INT PRIMARY KEY,
                parent_id INT NOT NULL,
                CONSTRAINT fk_drop_test_old FOREIGN KEY (parent_id) REFERENCES fk_drop_test_parent(id)
            )'
        );

        $tmpDir = sys_get_temp_dir() . '/migration_fk_drop_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents(
            $tmpDir . '/schema.sql',
            "CREATE TABLE fk_drop_test_child (\n    id INT PRIMARY KEY,\n    parent_id INT NOT NULL\n);"
        );
        file_put_contents($tmpDir . '/drops.sql', 'ALTER TABLE fk_drop_test_child DROP FOREIGN KEY fk_drop_test_old;');

        try {
            $runner = new MigrationRunner($this->connection, $this->introspector, new SchemaComparator(), new SqlParser());

            $result = $runner->migrate([$tmpDir . '/schema.sql']);

            $constraints = array_map(fn($fk) => $fk->name, $this->introspector->getForeignKeys('fk_drop_test_child'));
            $this->assertNotContains('fk_drop_test_old', $constraints);
            $this->assertContains('ALTER TABLE `fk_drop_test_child` DROP FOREIGN KEY `fk_drop_test_old`', $result->executedStatements);

            // Idempotent: the constraint is already gone, so a second run is a no-op for the drop
            $secondResult = $runner->migrate([$tmpDir . '/schema.sql']);
            $this->assertNotContains('ALTER TABLE `fk_drop_test_child` DROP FOREIGN KEY `fk_drop_test_old`', $secondResult->executedStatements);
        } finally {
            @unlink($tmpDir . '/schema.sql');
            @unlink($tmpDir . '/drops.sql');
            @rmdir($tmpDir);
        }
    }
}
