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
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        // MigrationRunner reads the schema in bulk now, so this is the
        // method it actually calls. A mock that stubs only the two above
        // returns an empty array here, every table then looks brand new,
        // and the failure is a confusing pile of CREATE TABLE statements
        // rather than an obvious "you forgot to stub something".
        $introspector->method('getTableDefinitions')->willReturnCallback(
            /**
             * @param array<string> $names
             * @return array<string, TableDefinition>
             */
            static function (array $names) use ($declaredByName): array {
                $result = [];
                foreach ($names as $name) {
                    if (isset($declaredByName[$name])) {
                        $result[$name] = $declaredByName[$name];
                    }
                }

                return $result;
            }
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

    /**
     * A zero budget must still converge rather than spin. With nothing to
     * execute — the mocked introspector mirrors the declared schema — the
     * very first pass finishes, because the budget is only ever consulted
     * between statements and there are none. What this pins is that the
     * loop terminates and the progress bar never goes backwards.
     */
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
        $introspector->expects($this->never())->method('getTableDefinitions');

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

    /**
     * Progress checkpointed against a DIFFERENT target hash describes a
     * path to a schema nothing will reach again — it must be discarded,
     * not resumed. Seeded directly rather than produced by an interrupted
     * run: since the statement queue stopped being persisted, a pass with
     * nothing to execute has nothing to interrupt, and the only way to
     * hold a stale row is to write one.
     */
    public function testProgressLeftBehindByAnotherTargetHashIsDiscardedNotResumed(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);
        $progressKey = 'schema_migration_progress_' . substr(hash('sha256', $this->schemaPath), 0, 16);

        $pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description, editable, sort_order)
             VALUES (NULL, ?, ?, ?, \'text\', ?, ?, 0, 999)'
        )->execute([
            $progressKey,
            (string) json_encode([
                'target_hash' => 'a-hash-for-schema-content-that-no-longer-exists',
                'executed_statements' => ['ALTER TABLE ghost ADD COLUMN gone INT'],
                'warnings' => ['a warning from an attempt nothing will resume'],
                'same_failure_count' => 2,
                'failure_signature' => 'stale',
            ]),
            '',
            'test',
            'test',
        ]);

        $runner = new MigrationRunner(
            $connection,
            $this->introspectorMirroring($this->schemaPath),
            new SchemaComparator(),
            new SqlParser()
        );
        $result = $runner->migrate([$this->schemaPath]);

        $this->assertTrue($result->complete);
        $this->assertTrue($result->converged);
        $this->assertNotContains('ALTER TABLE ghost ADD COLUMN gone INT', $result->executedStatements);
        $this->assertNotContains('a warning from an attempt nothing will resume', $result->warnings);

        // And the hash now cached is this content's, not the stale one.
        $key = 'schema_hash_' . substr(hash('sha256', $this->schemaPath), 0, 16);
        $currentHash = hash('sha256', file_get_contents($this->schemaPath) . "\x00\x00");
        $this->assertSame($currentHash, $this->settingValue($pdo, $key));
    }

    /**
     * A counting introspector: mirrors the declared schema like
     * introspectorMirroring(), but records how many times the bulk read
     * was actually performed.
     *
     * @param array{count: int} $calls
     */
    private function countingIntrospector(string $schemaPath, array &$calls): SchemaIntrospector
    {
        $declaredByName = [];
        foreach ((new SqlParser())->parseFile($schemaPath) as $table) {
            $declaredByName[$table->name] = $table;
        }

        $introspector = $this->createMock(SchemaIntrospector::class);
        $introspector->method('getTables')->willReturn(array_keys($declaredByName));
        $introspector->expects($this->never())->method('getTableDefinition');
        $introspector->method('getTableDefinitions')->willReturnCallback(
            /**
             * @param array<string> $names
             * @return array<string, TableDefinition>
             */
            function (array $names) use ($declaredByName, &$calls): array {
                $calls['count']++;
                $result = [];
                foreach ($names as $name) {
                    if (isset($declaredByName[$name])) {
                        $result[$name] = $declaredByName[$name];
                    }
                }

                return $result;
            }
        );

        return $introspector;
    }

    /**
     * Three declared tables, one bulk read — not one read per table, and
     * never the per-table method. This is the whole point of the change:
     * the diff loop used to cost 3 INFORMATION_SCHEMA round trips per
     * table, about 500 of them on the reference installation.
     */
    public function testTheDiffReadsTheSchemaInBulkOnceNotOncePerTable(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);
        $calls = ['count' => 0];

        $runner = new MigrationRunner(
            $connection,
            $this->countingIntrospector($this->schemaPath, $calls),
            new SchemaComparator(),
            new SqlParser()
        );
        $result = $runner->migrate([$this->schemaPath]);

        $this->assertTrue($result->complete);
        $this->assertSame(1, $calls['count'], 'three declared tables must cost one bulk read');
    }

    /**
     * The memo deliberately outlives a single migrate() call: ModuleManager
     * shares one MigrationRunner across the core schema and every module's,
     * and re-reading the live schema for each was most of the cost of a
     * release that touched several modules. With no DDL in between, the
     * second schema file is diffed against the same cached read.
     */
    public function testTheMemoSurvivesAcrossMigrateCallsOnTheSameInstance(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);

        $second = $this->tmpDir . '/other.sql';
        file_put_contents($second, "CREATE TABLE t1 (id INT PRIMARY KEY);");

        try {
            $calls = ['count' => 0];
            $runner = new MigrationRunner(
                $connection,
                $this->countingIntrospector($this->schemaPath, $calls),
                new SchemaComparator(),
                new SqlParser()
            );

            $runner->migrate([$this->schemaPath]);
            $runner->migrate([$second]);

            $this->assertSame(1, $calls['count'], 'the second migrate() must reuse the first read');
        } finally {
            @unlink($second);
        }
    }
}
