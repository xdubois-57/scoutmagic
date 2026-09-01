<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Scheduler\CronPassLock;
use PHPUnit\Framework\TestCase;

/**
 * The lock has to be real to be worth anything: what it protects against
 * is two crontab passes running at once — a per-minute cron starting a
 * fresh pass while a backup, an update install or a 900-second schema
 * migration from the previous minute is still going. Only a server-side
 * named lock can prevent that, so these tests use two genuine connections
 * to the real database rather than the SQLite one most of the suite runs
 * on. Same shape as Tests\Core\Maintenance\InstallLockTest, deliberately.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CronPassLockTest extends TestCase
{
    private ?\PDO $first = null;
    private ?\PDO $second = null;

    protected function setUp(): void
    {
        $this->first = $this->connect();
        $this->second = $this->connect();
    }

    protected function tearDown(): void
    {
        foreach ([$this->first, $this->second] as $pdo) {
            if ($pdo instanceof \PDO) {
                CronPassLock::release($pdo);
            }
        }
        $this->first = null;
        $this->second = null;
    }

    private function connect(): \PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';

        try {
            $pdo = new \PDO(
                "mysql:host={$host};port={$port};dbname={$dbName}",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }

        return $pdo;
    }

    /**
     * The whole point, and the reason the timeout is 0: the pass that
     * cannot have the lock finds out immediately and stands down. A queue
     * of blocked cron processes waiting their turn is the same pile-up
     * this exists to prevent, arriving a minute later by another road.
     */
    public function testASecondPassIsRefusedWhileTheFirstIsStillRunning(): void
    {
        $this->assertTrue(CronPassLock::acquire($this->first));

        $this->assertFalse(CronPassLock::acquire($this->second));
    }

    public function testTheNextPassGetsTheLockOnceTheRunningOneHasReleasedIt(): void
    {
        $this->assertTrue(CronPassLock::acquire($this->first));
        CronPassLock::release($this->first);

        $this->assertTrue(CronPassLock::acquire($this->second));
    }

    /**
     * The self-healing property, and the reason a pass that dies mid-flight
     * never wedges every later one: a MySQL/MariaDB advisory lock belongs
     * to a CONNECTION, and the server drops it the moment that connection
     * goes away — which is what happens when the PHP process ends, however
     * it ends. Closing the holder without ever calling release() must
     * therefore be enough.
     */
    public function testDroppingTheConnectionReleasesTheLockWithoutAnyoneCallingRelease(): void
    {
        $dying = $this->connect();
        $this->assertTrue(CronPassLock::acquire($dying));
        $this->assertFalse(CronPassLock::acquire($this->first));

        // The only way to close a PDO connection: drop every reference.
        unset($dying);

        // The server needs a moment to notice the disconnect on some
        // builds; poll rather than assert once, so this cannot be flaky.
        $granted = false;
        for ($attempt = 0; $attempt < 50 && !$granted; $attempt++) {
            $granted = CronPassLock::acquire($this->first);
            if (!$granted) {
                usleep(100_000);
            }
        }

        $this->assertTrue($granted, 'a lock held by a connection that went away must not survive it');
    }

    /**
     * RELEASE_LOCK() returns a result set. Leaving its cursor open breaks
     * the very next query on the connection with "Cannot execute queries
     * while other unbuffered queries are active" — the failure mode
     * MigrationRunner already hit once, and cron.php releases the lock
     * with the rest of the pass's work behind it.
     */
    public function testTheConnectionIsStillUsableRightAfterReleasing(): void
    {
        CronPassLock::acquire($this->first);
        CronPassLock::release($this->first);

        $this->assertSame('1', (string) $this->first->query('SELECT 1')->fetchColumn());
    }

    /**
     * A pass and a schema migration are different work and must not
     * exclude each other: a browser driving the migration-step endpoint
     * holds `scoutmagic_schema_migration`, and a cron pass that stood down
     * for it would be a cron pass blocked by a visitor.
     */
    public function testItDoesNotCollideWithTheOtherNamedLocksInThisCodebase(): void
    {
        $this->first->query("SELECT GET_LOCK('scoutmagic_schema_migration', 0)")->closeCursor();
        $this->first->query("SELECT GET_LOCK('scoutmagic_update_install', 0)")->closeCursor();

        $this->assertTrue(CronPassLock::acquire($this->second));

        $this->first->query("SELECT RELEASE_LOCK('scoutmagic_schema_migration')")->closeCursor();
        $this->first->query("SELECT RELEASE_LOCK('scoutmagic_update_install')")->closeCursor();
    }

    /**
     * GET_LOCK() is MySQL/MariaDB-only. On the SQLite connection most of
     * the suite runs on, refusing would make every pass stand down without
     * doing anything; proceeding is right there, since nothing runs two
     * passes at once against one in-memory database.
     */
    public function testItProceedsWithoutMutualExclusionWhereGetLockDoesNotExist(): void
    {
        $sqlite = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->assertTrue(CronPassLock::acquire($sqlite));
        CronPassLock::release($sqlite);
        $this->assertSame('1', (string) $sqlite->query('SELECT 1')->fetchColumn());
    }
}
