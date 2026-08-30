<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\InstallLock;
use PHPUnit\Framework\TestCase;

/**
 * The lock has to be real to be worth anything: what it protects is two
 * processes copying an extracted archive over the live install directory
 * at the same time, which only a server-side named lock can prevent. So
 * these tests use two genuine connections to the real database rather
 * than the SQLite one most of the suite runs on.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InstallLockTest extends TestCase
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
                InstallLock::release($pdo);
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

    public function testTheSecondConnectionIsRefusedWhileTheFirstHoldsTheLock(): void
    {
        $this->assertTrue(InstallLock::acquire($this->first));

        // The whole point, and the reason the timeout is 0: the loser finds
        // out immediately instead of queueing up behind a file copy and
        // starting its own the moment it ends.
        $this->assertFalse(InstallLock::acquire($this->second));
    }

    public function testTheLockIsGrantedAgainOnceReleased(): void
    {
        $this->assertTrue(InstallLock::acquire($this->first));
        InstallLock::release($this->first);

        $this->assertTrue(InstallLock::acquire($this->second));
    }

    /**
     * RELEASE_LOCK() returns a result set. Leaving its cursor open breaks
     * the very next query on the connection with "Cannot execute queries
     * while other unbuffered queries are active" — the failure mode
     * MigrationRunner already hit once.
     */
    public function testTheConnectionIsStillUsableRightAfterReleasing(): void
    {
        InstallLock::acquire($this->first);
        InstallLock::release($this->first);

        $this->assertSame('1', (string) $this->first->query('SELECT 1')->fetchColumn());
    }

    /**
     * GET_LOCK() is MySQL/MariaDB-only. On the SQLite connection most of
     * the suite runs on, refusing would make every install stand down
     * without doing anything; proceeding is right there, since those tests
     * never run two installs at once.
     */
    public function testItProceedsWithoutMutualExclusionWhereGetLockDoesNotExist(): void
    {
        $sqlite = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->assertTrue(InstallLock::acquire($sqlite));
        InstallLock::release($sqlite);
        $this->assertSame('1', (string) $sqlite->query('SELECT 1')->fetchColumn());
    }
}
