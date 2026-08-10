<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Database\TableDefinition;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Orchestration tests for MigrationRunner's chunked/resumable migrate() —
 * deliberately NOT @group database: it runs against an in-memory SQLite
 * connection (Connection::withPdo(), same `settings` table
 * DatabaseTestHelper already provides for every other SQLite-backed test)
 * with SchemaIntrospector mocked out, since SchemaIntrospector itself is
 * MySQL-only (raw INFORMATION_SCHEMA queries). Mocking it out to always
 * report "the database already matches what's declared" also sidesteps
 * SchemaComparator::generateCreateTable()'s MySQL-only `ENGINE=InnoDB`
 * tail, which isn't valid SQLite DDL — these tests are about the
 * checkpoint/resume mechanics (Core\Database\MigrationProgress), not about
 * exercising real DDL execution (that's MigrationRunnerTest.php's job,
 * against real MySQL in CI).
 */
class MigrationRunnerChunkingTest extends TestCase
{
    private string $tmpDir;
    private string $schemaPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/migration_chunking_test_' . uniqid();
        mkdir($this->tmpDir);
        $this->schemaPath = $this->tmpDir . '/schema.sql';
        file_put_contents(
            $this->schemaPath,
            "CREATE TABLE t1 (id INT PRIMARY KEY);\n"
            . "CREATE TABLE t2 (id INT PRIMARY KEY);\n"
            . "CREATE TABLE t3 (id INT PRIMARY KEY);"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->schemaPath);
        @unlink($this->tmpDir . '/drops.sql');
        @rmdir($this->tmpDir);
    }

    /**
     * A mocked SchemaIntrospector that reports the database as already
     * exactly matching whatever SqlParser parses from $schemaPath — so
     * SchemaComparator generates zero DDL, and the only thing under test
     * is the diff-phase chunking/checkpointing itself.
     */
    private function introspectorMirroring(string $schemaPath): SchemaIntrospector
    {
        $declaredByName = [];
        foreach ((new SqlParser())->parseFile($schemaPath) as $table) {
            $declaredByName[$table->name] = $table;
        }

        $introspector = $this->createMock(SchemaIntrospector::class);
        $introspector->method('getTables')->willReturn(array_keys($declaredByName));
        $introspector->method('getTableDefinition')->willReturnCallback(
            static fn(string $name): TableDefinition => $declaredByName[$name]
        );

        return $introspector;
    }

    private function settingValue(\PDO $pdo, string $key): ?string
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE module_id IS NULL AND setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testZeroTimeBudgetForcesIncompleteResultsUntilEventualCompletion(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);

        $lastProgress = -1.0;
        $complete = false;

        for ($i = 0; $i < 20 && !$complete; $i++) {
            $runner = new MigrationRunner(
                $connection,
                $this->introspectorMirroring($this->schemaPath),
                new SchemaComparator(),
                new SqlParser(),
                0
            );
            $result = $runner->migrate([$this->schemaPath]);

            // Progress never goes backwards across resumed calls.
            $this->assertGreaterThanOrEqual($lastProgress, $result->progressFraction);
            $lastProgress = $result->progressFraction;

            $complete = $result->complete;
        }

        $this->assertTrue($complete, 'migrate() never completed within 20 chunked calls');
        $this->assertSame(1.0, $lastProgress);

        // No real DDL was ever needed (the mock mirrors the declared
        // schema exactly), so nothing was executed and no failures
        // happened — only the chunking/resume mechanics are under test.
        $this->assertNull($this->settingValue($pdo, 'schema_migration_progress_' . substr(hash('sha256', $this->schemaPath), 0, 16)));
        $this->assertNotNull($this->settingValue($pdo, 'schema_hash_' . substr(hash('sha256', $this->schemaPath), 0, 16)));
    }

    public function testFullyCachedRunAfterCompletionSkipsAllWork(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);

        for ($i = 0; $i < 20; $i++) {
            $runner = new MigrationRunner(
                $connection,
                $this->introspectorMirroring($this->schemaPath),
                new SchemaComparator(),
                new SqlParser(),
                0
            );
            if ($runner->migrate([$this->schemaPath])->complete) {
                break;
            }
        }

        // A mock that would fail the test if getTables()/getTableDefinition()
        // were ever called again — the cached-hash fast path must never
        // touch the introspector at all.
        $introspector = $this->createMock(SchemaIntrospector::class);
        $introspector->expects($this->never())->method('getTables');
        $introspector->expects($this->never())->method('getTableDefinition');

        $cachedRunner = new MigrationRunner($connection, $introspector, new SchemaComparator(), new SqlParser());
        $result = $cachedRunner->migrate([$this->schemaPath]);

        $this->assertTrue($result->complete);
        $this->assertFalse($result->hasChanges());
        $this->assertSame(1.0, $result->progressFraction);
    }

    public function testIsPendingReflectsWhetherAMigrationIsOutstanding(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);

        $freshRunner = fn() => new MigrationRunner(
            $connection,
            $this->introspectorMirroring($this->schemaPath),
            new SchemaComparator(),
            new SqlParser(),
            0
        );

        $this->assertTrue($freshRunner()->isPending([$this->schemaPath]));

        for ($i = 0; $i < 20; $i++) {
            if ($freshRunner()->migrate([$this->schemaPath])->complete) {
                break;
            }
        }

        $this->assertFalse($freshRunner()->isPending([$this->schemaPath]));
    }

    public function testStaleProgressIsDiscardedWhenSchemaContentChangesMidAttempt(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);

        // First call: only far enough to persist progress against the
        // ORIGINAL (2-table) schema content, never completing it.
        file_put_contents(
            $this->schemaPath,
            "CREATE TABLE t1 (id INT PRIMARY KEY);\nCREATE TABLE t2 (id INT PRIMARY KEY);"
        );
        $firstRunner = new MigrationRunner(
            $connection,
            $this->introspectorMirroring($this->schemaPath),
            new SchemaComparator(),
            new SqlParser(),
            0
        );
        $firstResult = $firstRunner->migrate([$this->schemaPath]);
        $this->assertFalse($firstResult->complete);

        // Schema content changes (a 3rd table added) before the attempt
        // above ever finished — the stored progress now belongs to a
        // target hash nothing will ever reach again.
        file_put_contents(
            $this->schemaPath,
            "CREATE TABLE t1 (id INT PRIMARY KEY);\nCREATE TABLE t2 (id INT PRIMARY KEY);\nCREATE TABLE t3 (id INT PRIMARY KEY);"
        );

        $complete = false;
        for ($i = 0; $i < 20 && !$complete; $i++) {
            $runner = new MigrationRunner(
                $connection,
                $this->introspectorMirroring($this->schemaPath),
                new SchemaComparator(),
                new SqlParser(),
                0
            );
            $complete = $runner->migrate([$this->schemaPath])->complete;
        }

        $this->assertTrue($complete, 'migration against the NEW schema content never completed');

        // The hash now cached is the new content's hash, not the stale one.
        $key = 'schema_hash_' . substr(hash('sha256', $this->schemaPath), 0, 16);
        $currentHash = hash('sha256', file_get_contents($this->schemaPath) . "\x00\x00");
        $this->assertSame($currentHash, $this->settingValue($pdo, $key));
    }
}
