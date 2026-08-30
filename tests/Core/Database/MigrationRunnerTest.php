<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        // Assigned only once the server has answered: tearDown() runs even
        // after markTestSkipped(), and dereferencing an unusable Connection
        // there turned a clean skip into a PDOException on every one of
        // this class's tests wherever no MySQL is running.
        $connection = new Connection($host, $port, $dbName, $user, $password);

        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database connection not available: ' . $result);
        }

        $this->connection = $connection;

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

    /**
     * migrate() used to dump the entire database before doing anything —
     * before even knowing whether there was any DDL to apply, and once per
     * schema file set, so a release touching six modules produced seven
     * full dumps of which six were immediately discarded. It was the
     * single largest cost of a migration, and a duplicate of the backup
     * every caller that can change a schema file already takes. Nothing
     * here may write a dump again.
     */
    public function testMigrateWritesNoDatabaseDump(): void
    {
        $tempDir = dirname(__DIR__, 3) . '/storage/temp';
        $before = glob($tempDir . '/backup_*.sql') ?: [];

        $runner = new MigrationRunner(
            $this->connection,
            $this->introspector,
            new SchemaComparator(),
            new SqlParser()
        );

        $schemaPath = dirname(__DIR__, 3) . '/schema/core.sql';
        $result = $runner->migrate([$schemaPath]);

        $this->assertIsArray($result->warnings);
        $this->assertSame($before, glob($tempDir . '/backup_*.sql') ?: []);
    }

    /**
     * The introspection memo describes the live schema, so executing DDL
     * makes it a description of the past.
     *
     * Getting this test to actually fail without the invalidation took two
     * attempts, and the first one is worth recording: against an EMPTY
     * database the memo is never even populated — the diff loop only
     * consults it for tables that already exist, so with none existing it
     * short-circuits and the cache stays null. The staleness only bites
     * when the first pass both READS the schema and then CHANGES it, which
     * is why `memo_existing` is created up front.
     *
     * Two different schema files on purpose too: a second migrate() for the
     * same file short-circuits on the cached hash and never introspects at
     * all, so the obvious "migrate twice" shape cannot cover this either.
     */
    public function testExecutingDdlInvalidatesTheIntrospectionMemo(): void
    {
        $pdo = $this->connection->getPdo();
        // Exists before the first pass, so the diff loop consults — and
        // therefore populates — the memo while `memo_created` still does
        // not exist.
        $pdo->exec('CREATE TABLE memo_existing (id INT PRIMARY KEY)');

        $tmpDir = sys_get_temp_dir() . '/migration_memo_test_' . uniqid();
        mkdir($tmpDir);
        $first = $tmpDir . '/first.sql';
        $second = $tmpDir . '/second.sql';
        file_put_contents(
            $first,
            "CREATE TABLE memo_existing (\n    id INT PRIMARY KEY\n);\n"
            . "CREATE TABLE memo_created (\n    id INT PRIMARY KEY\n);"
        );
        // A runner still holding the pre-CREATE memo believes memo_created
        // absent and emits CREATE TABLE for it, which fails with "table
        // already exists" and lands in warnings.
        file_put_contents(
            $second,
            "CREATE TABLE memo_created (\n    id INT PRIMARY KEY,\n    label VARCHAR(20) NULL\n);"
        );

        try {
            $runner = new MigrationRunner($this->connection, $this->introspector, new SchemaComparator(), new SqlParser());

            $runner->migrate([$first]);
            $result = $runner->migrate([$second]);

            // Not assertSame([], warnings): the second file declares only
            // memo_created, so memo_existing legitimately produces the
            // "exists in database but not in declared schema" notice — the
            // structurally-false warning a later iteration removes. What
            // must not appear is an execution failure.
            $failures = array_values(array_filter(
                $result->warnings,
                static fn(string $w): bool => str_contains($w, 'Failed to execute')
            ));
            $this->assertSame([], $failures, 'a stale memo shows up here as "table already exists"');
            $this->assertTrue($result->complete);
            $this->assertNotEmpty(array_filter(
                $result->executedStatements,
                static fn(string $st): bool => str_contains($st, 'ADD COLUMN')
            ), 'the second pass must ALTER the existing table, not try to create it');

            $columns = array_map(fn($c) => $c->name, $this->introspector->getColumns('memo_created'));
            $this->assertContains('label', $columns);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS memo_created');
            $pdo->exec('DROP TABLE IF EXISTS memo_existing');
            @unlink($first);
            @unlink($second);
            @rmdir($tmpDir);
        }
    }

    /**
     * The advisory lock is deliberately non-blocking (timeout 0): on the
     * shared host this targets, ten visitors waiting on a lock are ten FPM
     * workers immobilised against a ceiling of about twenty, so the site
     * would fall over because of the mechanism meant to protect it. A
     * caller that does not get the lock hands back immediately and simply
     * reports "not done yet".
     */
    public function testMigrateYieldsImmediatelyWhenAnotherProcessHoldsTheLock(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        // A second connection is a second MySQL session, which is what
        // GET_LOCK() is scoped to — the same connection would just be
        // granted the lock it already holds.
        $other = new Connection($host, $port, $dbName, $user, $password);
        $holder = $other->getPdo()->query("SELECT GET_LOCK('scoutmagic_schema_migration', 0)");
        $this->assertSame(1, (int) $holder->fetchColumn());
        $holder->closeCursor();

        try {
            $runner = new MigrationRunner($this->connection, $this->introspector, new SchemaComparator(), new SqlParser());

            $result = $runner->migrate([dirname(__DIR__, 3) . '/schema/core.sql']);

            $this->assertFalse($result->complete);
            $this->assertEmpty($result->executedStatements);
            // Nothing was created: the lock holder is the one doing the work.
            $this->assertNotContains('members', $this->introspector->getTables());
        } finally {
            $release = $other->getPdo()->query("SELECT RELEASE_LOCK('scoutmagic_schema_migration')");
            if ($release !== false) {
                $release->closeCursor();
            }
        }
    }

    /**
     * The one place in the migration path that destroys data, so the one
     * place that owes the journal a `security` entry.
     */
    public function testExecutedDropIsJournaledAtSecurityLevel(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE journaled_drop_test (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, legacy VARCHAR(50) NOT NULL)');

        $tmpDir = sys_get_temp_dir() . '/migration_journal_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', "CREATE TABLE journaled_drop_test (\n    id INT PRIMARY KEY,\n    name VARCHAR(50) NOT NULL\n);");
        file_put_contents($tmpDir . '/drops.sql', 'ALTER TABLE journaled_drop_test DROP COLUMN legacy;');

        try {
            $journal = $this->createMock(JournalService::class);
            $journal->expects($this->once())
                ->method('log')
                ->with(
                    'core',
                    'schema_drop_executed',
                    'security',
                    $this->anything(),
                    ['table' => 'journaled_drop_test', 'column' => 'legacy']
                );

            $runner = new MigrationRunner(
                $this->connection,
                $this->introspector,
                new SchemaComparator(),
                new SqlParser(),
                journal: $journal
            );

            $runner->migrate([$tmpDir . '/schema.sql']);
        } finally {
            @unlink($tmpDir . '/schema.sql');
            @unlink($tmpDir . '/drops.sql');
            @rmdir($tmpDir);
        }
    }

    /**
     * The counterpart: a drops.sql line whose column is already gone is
     * skipped before anything executes, so it must not produce a journal
     * entry either — otherwise every migration on every installed site
     * would re-log the whole file forever.
     */
    public function testAlreadyAppliedDropIsNotJournaled(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE quiet_drop_test (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');

        $tmpDir = sys_get_temp_dir() . '/migration_quiet_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', "CREATE TABLE quiet_drop_test (\n    id INT PRIMARY KEY,\n    name VARCHAR(50) NOT NULL\n);");
        file_put_contents($tmpDir . '/drops.sql', 'ALTER TABLE quiet_drop_test DROP COLUMN legacy;');

        try {
            $journal = $this->createMock(JournalService::class);
            $journal->expects($this->never())->method('log');

            $runner = new MigrationRunner(
                $this->connection,
                $this->introspector,
                new SchemaComparator(),
                new SqlParser(),
                journal: $journal
            );

            $runner->migrate([$tmpDir . '/schema.sql']);
        } finally {
            @unlink($tmpDir . '/schema.sql');
            @unlink($tmpDir . '/drops.sql');
            @rmdir($tmpDir);
        }
    }

    /**
     * `event_log` is itself one of the tables a migration may still be
     * creating, so writing the journal entry can throw. A drop that
     * already succeeded must not be turned into a failed migration by the
     * attempt to record it.
     */
    public function testAJournalThatThrowsDoesNotFailTheDrop(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE noisy_journal_test (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, legacy VARCHAR(50) NOT NULL)');

        $tmpDir = sys_get_temp_dir() . '/migration_noisy_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', "CREATE TABLE noisy_journal_test (\n    id INT PRIMARY KEY,\n    name VARCHAR(50) NOT NULL\n);");
        file_put_contents($tmpDir . '/drops.sql', 'ALTER TABLE noisy_journal_test DROP COLUMN legacy;');

        try {
            $journal = $this->createMock(JournalService::class);
            $journal->method('log')->willThrowException(new \RuntimeException('no event_log yet'));

            $runner = new MigrationRunner(
                $this->connection,
                $this->introspector,
                new SchemaComparator(),
                new SqlParser(),
                journal: $journal
            );

            $result = $runner->migrate([$tmpDir . '/schema.sql']);

            $this->assertTrue($result->complete);
            $this->assertContains('ALTER TABLE `noisy_journal_test` DROP COLUMN `legacy`', $result->executedStatements);
            $columns = array_map(fn($c) => $c->name, $this->introspector->getColumns('noisy_journal_test'));
            $this->assertNotContains('legacy', $columns);
        } finally {
            @unlink($tmpDir . '/schema.sql');
            @unlink($tmpDir . '/drops.sql');
            @rmdir($tmpDir);
        }
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

    public function testMigrateAppliesExplicitTableDropFromSiblingDropsFile(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec('CREATE TABLE keep_test (id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE table_drop_test (id INT PRIMARY KEY)');

        $tmpDir = sys_get_temp_dir() . '/migration_drop_test_' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/schema.sql', "CREATE TABLE keep_test (\n    id INT PRIMARY KEY\n);");
        file_put_contents($tmpDir . '/drops.sql', 'DROP TABLE table_drop_test;');

        try {
            $journal = $this->createMock(JournalService::class);
            $journal->expects($this->once())
                ->method('log')
                ->with(
                    'core',
                    'schema_drop_executed',
                    'security',
                    $this->anything(),
                    ['table' => 'table_drop_test']
                );

            $runner = new MigrationRunner(
                $this->connection,
                $this->introspector,
                new SchemaComparator(),
                new SqlParser(),
                journal: $journal
            );

            $result = $runner->migrate([$tmpDir . '/schema.sql']);

            $this->assertNotContains('table_drop_test', $this->introspector->getTables());
            $this->assertContains('keep_test', $this->introspector->getTables());
            $this->assertContains('DROP TABLE `table_drop_test`', $result->executedStatements);

            // Idempotent: the table is already gone, so a second run is a
            // no-op for the drop — the exact property that makes drops.sql
            // safe to apply on every request.
            $secondResult = $runner->migrate([$tmpDir . '/schema.sql']);
            $this->assertEmpty($secondResult->warnings);
            $this->assertNotContains('DROP TABLE `table_drop_test`', $secondResult->executedStatements);
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
            $journal = $this->createMock(JournalService::class);
            $journal->expects($this->once())
                ->method('log')
                ->with(
                    'core',
                    'schema_drop_executed',
                    'security',
                    $this->anything(),
                    ['table' => 'fk_drop_test_child', 'constraint' => 'fk_drop_test_old']
                );

            $runner = new MigrationRunner(
                $this->connection,
                $this->introspector,
                new SchemaComparator(),
                new SqlParser(),
                journal: $journal
            );

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
