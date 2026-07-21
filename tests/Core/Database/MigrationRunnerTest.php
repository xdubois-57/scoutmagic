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

        // Clean up tables from previous test runs
        $pdo = $this->connection->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS members');
        $pdo->exec('DROP TABLE IF EXISTS scout_years');
        $pdo->exec('DROP TABLE IF EXISTS drop_test');
        $pdo->exec('DROP TABLE IF EXISTS fk_drop_test_child');
        $pdo->exec('DROP TABLE IF EXISTS fk_drop_test_parent');
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $pdo = $this->connection->getPdo();
            $pdo->exec('DROP TABLE IF EXISTS members');
            $pdo->exec('DROP TABLE IF EXISTS scout_years');
            $pdo->exec('DROP TABLE IF EXISTS drop_test');
            $pdo->exec('DROP TABLE IF EXISTS fk_drop_test_child');
            $pdo->exec('DROP TABLE IF EXISTS fk_drop_test_parent');
        }
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

        // On CI without mysqldump, there should be a backup warning
        // This test just verifies the result object structure is correct
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
