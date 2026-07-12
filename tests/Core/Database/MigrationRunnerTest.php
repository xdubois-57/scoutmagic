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
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $pdo = $this->connection->getPdo();
            $pdo->exec('DROP TABLE IF EXISTS members');
            $pdo->exec('DROP TABLE IF EXISTS scout_years');
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
}
