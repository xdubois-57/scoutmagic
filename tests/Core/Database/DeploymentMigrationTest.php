<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\Connection;
use Core\Database\DeploymentMigration;
use Core\Database\SchemaFiles;
use PHPUnit\Framework\TestCase;

/**
 * `public/cron.php` is never executed by any test and never reached by a
 * browser, so an inline migration block there is a block nothing can
 * check — which is exactly how it came to build a MigrationRunner and
 * never call it for a whole iteration. These tests exist so that the pass
 * the cron entry point performs is a thing with a name that can be run.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeploymentMigrationTest extends TestCase
{
    private ?Connection $connection = null;
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $connection = new Connection(
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: 3306),
            getenv('TEST_DB_NAME') ?: 'test_db',
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: ''
        );

        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database connection not available: ' . $result);
        }

        $this->connection = $connection;
        $this->dropAllTables($connection->getPdo());

        // A two-table installation rather than the real schema/core.sql:
        // what is under test is that run() reads SchemaFiles::all() from
        // the base path it is handed and actually executes the diff, not
        // the content of the production schema (MigrationRunnerTest owns
        // that). A small schema also keeps this test off the slow path.
        $this->tmpDir = sys_get_temp_dir() . '/deployment_migration_test_' . uniqid();
        mkdir($this->tmpDir . '/schema', 0777, true);
        file_put_contents(
            $this->tmpDir . '/schema/core.sql',
            "CREATE TABLE deployment_probe_one (id INT PRIMARY KEY);\n"
            . "CREATE TABLE deployment_probe_two (id INT PRIMARY KEY);\n"
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->dropAllTables($this->connection->getPdo());
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            @unlink($this->tmpDir . '/schema/core.sql');
            @rmdir($this->tmpDir . '/schema');
            @rmdir($this->tmpDir);
        }
    }

    private function dropAllTables(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return $this->connection->getPdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testRunCreatesTheWholeDeclaredSchemaInOnePass(): void
    {
        $result = DeploymentMigration::run($this->connection, $this->tmpDir);

        $this->assertTrue($result->complete, 'the cron budget is wide enough for a two-table schema in one pass');
        $this->assertSame(1.0, $result->progressFraction);
        $this->assertContains('deployment_probe_one', $this->tables());
        $this->assertContains('deployment_probe_two', $this->tables());
    }

    /**
     * The reason this entry point is allowed to be cheap: it runs on every
     * cron tick, and on all but the tick after a release there is nothing
     * to do. A second pass must therefore execute no DDL at all.
     */
    public function testASecondRunOnAnUpToDateSchemaExecutesNothing(): void
    {
        DeploymentMigration::run($this->connection, $this->tmpDir);
        $second = DeploymentMigration::run($this->connection, $this->tmpDir);

        $this->assertSame([], $second->executedStatements);
        $this->assertTrue($second->complete);
    }

    /**
     * The budget is what separates this entry point from the ones a person
     * waits on. A one-second budget on a schema with work to do must stop
     * and report itself incomplete rather than run to the end — that is the
     * checkpoint `cron.php` announces and the next pass resumes from.
     */
    public function testAnExhaustedBudgetStopsAndReportsItselfIncomplete(): void
    {
        $statements = '';
        for ($i = 0; $i < 60; $i++) {
            $statements .= "CREATE TABLE deployment_probe_bulk_$i (id INT PRIMARY KEY);\n";
        }
        file_put_contents($this->tmpDir . '/schema/core.sql', $statements);

        $result = DeploymentMigration::run($this->connection, $this->tmpDir, 0);

        $this->assertFalse($result->complete, 'a spent budget must checkpoint, not run to the end');
        $this->assertLessThan(1.0, $result->progressFraction);
    }

    /**
     * run() must read the same file list every other caller reads: a module
     * schema dropped into modules/<name>/schema.sql is part of the declared
     * schema, and the cron pass is the one that is supposed to find it.
     */
    public function testRunMigratesModuleSchemasAlongsideTheCoreOne(): void
    {
        mkdir($this->tmpDir . '/modules/probe', 0777, true);
        file_put_contents(
            $this->tmpDir . '/modules/probe/schema.sql',
            "CREATE TABLE deployment_probe_module (id INT PRIMARY KEY);\n"
        );
        $this->assertContains(
            $this->tmpDir . '/modules/probe/schema.sql',
            SchemaFiles::all($this->tmpDir),
            'guard: the fixture must actually be visible to SchemaFiles'
        );

        DeploymentMigration::run($this->connection, $this->tmpDir);

        $this->assertContains('deployment_probe_module', $this->tables());

        @unlink($this->tmpDir . '/modules/probe/schema.sql');
        @rmdir($this->tmpDir . '/modules/probe');
        @rmdir($this->tmpDir . '/modules');
    }
}
