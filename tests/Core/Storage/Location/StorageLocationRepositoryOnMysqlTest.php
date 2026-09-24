<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two statements in this repository that only the REAL engine can
 * judge, run against it.
 *
 * Most of the suite runs on SQLite, which serialises its writers itself
 * and has no `SELECT … FOR UPDATE` — so the clause that keeps two
 * concurrent first-ever creations from both claiming `is_default = 1` is
 * exactly the kind of thing a green SQLite run proves nothing about. It
 * is added for MySQL and MariaDB, and this is where it is actually
 * parsed.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StorageLocationRepositoryOnMysqlTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $repository;
    private bool $createdTheTable = false;

    protected function setUp(): void
    {
        $this->pdo = $this->connect();

        // The shared test database is not migrated, so the table is
        // created here from `schema/core.sql` itself — which also means
        // the declaration this chantier added is parsed by the real
        // engine rather than only by SQLite's dialect.
        //
        // Created only when it is absent, and dropped only when this test
        // is what created it: the database is shared with every other
        // `@group database` test, and dropping a table another one left
        // behind would make this file's result depend on the order the
        // suite happens to run in.
        $this->createdTheTable = $this->pdo
            ->query("SHOW TABLES LIKE 'storage_locations'")?->fetchColumn() === false;
        if ($this->createdTheTable) {
            $this->pdo->exec(self::createTableStatement());
        }

        $this->repository = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    private function connect(): \PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';

        try {
            return new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised('Database connection not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdTheTable) {
            $this->pdo->exec('DROP TABLE IF EXISTS storage_locations');
        }
    }

    /**
     * The `CREATE TABLE storage_locations` block of `schema/core.sql`, as
     * written — foreign keys stripped, since nothing else is migrated here.
     */
    private static function createTableStatement(): string
    {
        // The `--` comments are dropped BEFORE looking for the statement's
        // terminator: `schema/core.sql` documents this table heavily, and
        // one of those comments ends in `getSecret());` — a semicolon that
        // would cut the declaration in half.
        $lines = [];
        foreach (explode("\n", (string) file_get_contents(__DIR__ . '/../../../../schema/core.sql')) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $schema = implode("\n", $lines);

        $start = strpos($schema, 'CREATE TABLE IF NOT EXISTS storage_locations');
        self::assertNotFalse($start, 'schema/core.sql no longer declares storage_locations.');
        $end = strpos($schema, ';', $start);
        self::assertNotFalse($end);

        return substr($schema, $start, $end - $start + 1);
    }

    public function testCreatingALocationParsesAndRunsOnTheRealEngine(): void
    {
        $label = 'Test ' . bin2hex(random_bytes(6));

        $id = $this->repository->create(
            StorageLocationType::Local,
            $label,
            new LocalLocationConfig('gallery'),
            null
        );

        try {
            $this->assertGreaterThan(0, $id);
            $this->assertSame($label, $this->repository->findById($id)?->label);
        } finally {
            $this->repository->delete($id);
        }
    }

    public function testDeletingTheDefaultPromotesTheSurvivorOnTheRealEngine(): void
    {
        // The promotion updates a table it also selects from. MariaDB
        // 10.11 — this container, and production — accepts that written
        // either way; MySQL 8, which CI's `test` job runs, is the engine
        // that raises 1093 unless the subquery sits in a derived table.
        // So the statement is spelled for the stricter of the two and
        // exercised here against a real server rather than only SQLite.
        $first = $this->repository->create(
            StorageLocationType::Local,
            'Premier ' . bin2hex(random_bytes(4)),
            new LocalLocationConfig('gallery'),
            null
        );
        $second = $this->repository->create(
            StorageLocationType::Local,
            'Second ' . bin2hex(random_bytes(4)),
            new LocalLocationConfig('autre'),
            null
        );
        $this->repository->setDefault($second);

        try {
            $this->repository->delete($second);

            $this->assertTrue($this->repository->findById($first)?->isDefault);
        } finally {
            $this->repository->delete($first);
        }
    }

    public function testTheLockingReadDeleteIssuesMakesAConcurrentWriterWait(): void
    {
        // What this pins, and what it does not.
        //
        // `delete()` reads whether the row is the default and then, on the
        // strength of that answer, promotes a successor. Under REPEATABLE
        // READ a plain SELECT reads a snapshot: a concurrent `setDefault()`
        // could move the flag between the read and the promotion, and both
        // rows would end up flagged. `FOR UPDATE` is what serialises the
        // two.
        //
        // That interleaving is NOT reachable from a test: the race needs a
        // second transaction driven between two statements inside
        // `delete()`, and nothing can get in there without instrumenting
        // the method. So this pins the mechanism instead — that the
        // statement `delete()` now issues really does take a row lock a
        // concurrent writer has to wait for, on the engine that matters.
        // The correctness of the scenario rests on the isolation
        // semantics, not on this assertion.
        $first = $this->repository->create(
            StorageLocationType::Local,
            'Premier ' . bin2hex(random_bytes(4)),
            new LocalLocationConfig('gallery'),
            null
        );
        $second = $this->repository->create(
            StorageLocationType::Local,
            'Second ' . bin2hex(random_bytes(4)),
            new LocalLocationConfig('autre'),
            null
        );

        $other = $this->connect();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        // The statement is spelled exactly as StorageLocationRepository::
        // isDefaultWithin() spells it — if that method loses its clause,
        // this test keeps passing, which is precisely the limit stated
        // above.
        $this->pdo->beginTransaction();
        $held = $this->pdo->prepare('SELECT is_default FROM storage_locations WHERE id = ? FOR UPDATE');
        $held->execute([$first]);
        $held->fetchColumn();

        try {
            $blocked = $other->prepare('UPDATE storage_locations SET is_default = 0 WHERE id = ?');
            $blocked->execute([$first]);
            $this->fail('A second writer reached the row this transaction had locked.');
        } catch (\PDOException $e) {
            // 1205 = lock wait timeout. That the second writer had to wait
            // at all is the whole assertion.
            $this->assertSame('1205', (string) ($e->errorInfo[1] ?? ''), $e->getMessage());
        } finally {
            $this->pdo->rollBack();
            $this->repository->delete($second);
            $this->repository->delete($first);
        }
    }

    public function testPromotingALocationThatIsGoneIsRefusedOnTheRealEngine(): void
    {
        // MySQL reports zero affected rows both for an id that matches
        // nothing and for a row already holding the value, which is the
        // whole reason setDefault() reads the row back inside its own
        // transaction. SQLite counts differently, so this belongs here.
        $this->expectException(\Core\Storage\Location\StorageLocationException::class);

        $this->repository->setDefault(2_000_000_000);
    }
}
