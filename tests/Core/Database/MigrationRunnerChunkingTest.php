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
        // Canonicalised, because MigrationRunner canonicalises too: it keys
        // both the schema-hash setting and the resumable progress row on
        // realpath() of the schema file (ARCHITECTURE.md §10, « One file is
        // one migration, however it is spelled »). On Linux sys_get_temp_dir()
        // is already canonical and the difference never shows; on macOS
        // /var/folders/... resolves to /private/var/folders/..., so a test
        // deriving its expected key from the raw path looked for a setting
        // the runner had written under a different name — and failed on a
        // path difference while claiming the chunking was broken.
        $this->tmpDir = realpath($this->tmpDir);
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

    /**
     * A counting PDO: how many times the progress row was actually
     * WRITTEN. checkpoint() is called after every statement, and the
     * question this pins is how many of those calls reach the database.
     */
    private function countingPdo(\PDO $inner): \PDO
    {
        return new class ($inner) extends \PDO {
            public int $progressWrites = 0;

            public function __construct(private \PDO $inner)
            {
            }

            #[\ReturnTypeWillChange]
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'UPDATE settings SET setting_value')
                    || str_contains($query, 'INSERT INTO settings')) {
                    $this->progressWrites++;
                }

                return $this->inner->prepare($query, $options);
            }

            #[\ReturnTypeWillChange]
            public function exec(string $statement): int|false
            {
                return $this->inner->exec($statement);
            }

            #[\ReturnTypeWillChange]
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                return $this->inner->query($query);
            }

            #[\ReturnTypeWillChange]
            public function getAttribute(int $attribute): mixed
            {
                return $this->inner->getAttribute($attribute);
            }
        };
    }

    private function runnerOver(\PDO $pdo, int $budget): MigrationRunner
    {
        return new MigrationRunner(
            Connection::withPdo($pdo),
            $this->introspectorMirroring($this->schemaPath),
            new SchemaComparator(),
            new SqlParser(),
            $budget
        );
    }

    /**
     * One UPSERT per statement was the old cost of checkpointing. On the
     * reference installation that is 139 writes per pass — MariaDB reports
     * the same cosmetic MODIFY COLUMNs as needed on every run — to persist
     * a value nothing reads until the pass ends.
     *
     * Spacing them is only safe because of what a checkpoint carries since
     * the re-diff landed: no queue, just accumulated reporting state. This
     * drives checkpoint() as fast as the machine allows and asserts the
     * writes are bounded by elapsed time rather than by call count.
     */
    public function testRapidCheckpointsDoNotWriteOncePerCall(): void
    {
        $counting = $this->countingPdo(DatabaseTestHelper::createTestDatabase());
        $runner = $this->runnerOver($counting, 20);

        $method = new \ReflectionMethod(MigrationRunner::class, 'checkpoint');
        $method->setAccessible(true);
        $progress = \Core\Database\MigrationProgress::start('target-hash');
        $deadline = microtime(true) + 3600;

        $calls = 500;
        $started = microtime(true);
        for ($i = 0; $i < $calls; $i++) {
            $this->assertNull(
                $method->invoke($runner, 'schema_migration_progress_test', $progress, $deadline),
                'an ample budget must never end the pass'
            );
        }
        $elapsed = microtime(true) - $started;

        // The ceiling is what the interval allows over the time the loop
        // actually took, plus the first write (which is always taken) and
        // one for rounding. Expressed against elapsed time rather than a
        // fixed number, so a slow CI machine cannot make it flaky.
        $allowed = (int) ceil($elapsed / 0.5) + 2;

        $this->assertGreaterThan(0, $counting->progressWrites, 'the first checkpoint must still be written');
        $this->assertLessThanOrEqual($allowed, $counting->progressWrites);
        $this->assertLessThan(
            $calls,
            $counting->progressWrites,
            'spacing the writes is the entire point: one per call is the behaviour being replaced'
        );
    }

    /**
     * The counterpart, and the one that must never be skipped: a pass
     * leaving because its budget is spent writes before it goes, however
     * recently it last wrote. Otherwise the state the next pass reads
     * would be stale by up to a whole interval of work.
     */
    public function testThePassAlwaysWritesBeforeLeavingOnItsBudget(): void
    {
        $counting = $this->countingPdo(DatabaseTestHelper::createTestDatabase());
        $runner = $this->runnerOver($counting, 20);

        $method = new \ReflectionMethod(MigrationRunner::class, 'checkpoint');
        $method->setAccessible(true);
        $progress = \Core\Database\MigrationProgress::start('target-hash');

        // A first call with budget left: writes, and sets the clock.
        $this->assertNull($method->invoke($runner, 'schema_migration_progress_test', $progress, microtime(true) + 3600));
        $writesAfterFirst = $counting->progressWrites;

        // Immediately after — well inside the interval that would
        // otherwise skip the write — but out of budget.
        $progress->executedStatements[] = 'ALTER TABLE t1 ADD COLUMN late INT';
        $result = $method->invoke($runner, 'schema_migration_progress_test', $progress, microtime(true) - 1);

        $this->assertNotNull($result, 'a spent budget must end the pass');
        $this->assertFalse($result->complete);
        $this->assertGreaterThan($writesAfterFirst, $counting->progressWrites, 'the departing pass must write');

        $stored = json_decode((string) $this->settingValue($counting, 'schema_migration_progress_test'), true);
        $this->assertSame(
            ['ALTER TABLE t1 ADD COLUMN late INT'],
            $stored['executed_statements'],
            'and it must write the state as of leaving, not as of the last spaced write'
        );
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

    /**
     * The same file, spelled two ways, must be one migration.
     *
     * The cache key and the resume key are derived from the schema file
     * PATHS, and the callers do not spell the path the same way:
     * public/index.php passes `<root>/public/../schema/core.sql` while
     * Task\InstallUpdateHandler passes `<root>/schema/core.sql`
     * (dirname of the storage path). Two strings, two `schema_hash_` rows,
     * two `schema_migration_progress_` rows — for one file.
     *
     * What that costs in production: the update finishes migrating and
     * caches its hash, then the very next page load asks the OTHER key,
     * still finds the old hash there, and serves the migration-in-progress
     * page — re-running through the browser the work the update just did.
     * The resumable state is split the same way, so neither ever picks up
     * where the other stopped.
     */
    public function testTheSameFileSpelledTwoWaysIsOneMigrationNotTwo(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);

        // Two spellings of $this->schemaPath: the plain one, and one that
        // detours through a directory and back out again — exactly the
        // shape of `public/..`.
        $detour = $this->tmpDir . '/./' . basename($this->schemaPath);
        $this->assertFileExists($detour, 'both spellings must name the same existing file');

        $direct = new MigrationRunner(
            $connection,
            $this->introspectorMirroring($this->schemaPath),
            new SchemaComparator(),
            new SqlParser(),
            20
        );
        $this->assertTrue($direct->isPending([$this->schemaPath]));
        $this->assertTrue($direct->migrate([$this->schemaPath])->complete);
        $this->assertFalse($direct->isPending([$this->schemaPath]));

        // A second runner asking about the same file by its other name
        // must see the migration that already happened.
        $viaDetour = new MigrationRunner(
            $connection,
            $this->introspectorMirroring($this->schemaPath),
            new SchemaComparator(),
            new SqlParser(),
            20
        );

        $this->assertFalse(
            $viaDetour->isPending([$detour]),
            'a migration already done must not be pending again under another spelling of the same path'
        );
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
