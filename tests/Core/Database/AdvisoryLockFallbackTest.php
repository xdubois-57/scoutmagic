<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\AdvisoryLock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1113: the `true` an AdvisoryLock answers when its query throws is
 * the SQLite fallback — an engine with no GET_LOCK() at all — and nothing
 * else. On any other driver the exception means the lock is not known to be
 * held. AdvisoryLockOnMysqlTest exercises the real failure on MariaDB; this
 * pins the decision on both sides without a server.
 */
#[Group('database')]
final class AdvisoryLockFallbackTest extends TestCase
{
    public function testSqliteHasNoAdvisoryLocksSoItProceeds(): void
    {
        $sqlite = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->assertTrue(AdvisoryLock::acquire($sqlite, 'any-name'));
    }

    public function testAFailedLockQueryOnAnotherDriverIsARefusal(): void
    {
        $this->assertFalse(AdvisoryLock::acquire($this->failingConnection('mysql'), 'any-name'));
    }

    /** The same failure on a driver that reports SQLite is the fallback, still. */
    public function testTheFallbackIsDecidedByTheDriverNotByTheException(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->failingConnection('sqlite'), 'any-name'));
    }

    /** A driver that cannot even be asked its name is not presumed to be SQLite. */
    public function testADriverThatCannotBeNamedIsARefusal(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('gone away'));
        $pdo->method('getAttribute')->willThrowException(new \PDOException('gone away'));

        $this->assertFalse(AdvisoryLock::acquire($pdo, 'any-name'));
    }

    private function failingConnection(string $driver): \PDO
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willThrowException(new \PDOException('MySQL server has gone away'));
        $pdo->method('getAttribute')->willReturnMap([[\PDO::ATTR_DRIVER_NAME, $driver]]);

        return $pdo;
    }
}
