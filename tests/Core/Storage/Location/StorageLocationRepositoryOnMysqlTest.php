<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;

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
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';

        try {
            $this->pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }

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
