<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Database\AdvisoryLock;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use PHPUnit\Framework\TestCase;

/**
 * The exclusion has to be real to be worth anything: what reserve()
 * protects against is two requests from one address counting the window
 * at the same instant and both writing. Only a server-side named lock
 * does that, so this test uses two genuine connections to the real
 * database rather than the SQLite one the rest of the module's tests
 * run on. Same shape as Tests\Core\Scheduler\CronPassLockTest, and the
 * same skip rule as Tests\Core\Scheduler\LivePrefixGuardOnMysqlTest:
 * where TEST_DB_* is set, a server that does not answer is a failure.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RateLimitReservationLockTest extends TestCase
{
    private ?\PDO $holder = null;
    private ?\PDO $requester = null;

    /**
     * Two connections to the pre-provisioned test database (TEST_DB_NAME,
     * the one CI's database job and the session hook both create), never
     * a database of this test's own: a `CREATE DATABASE` takes its name
     * by concatenation, and SECURITY.md allows no exception to "every
     * statement is prepared" for a value that merely happens to be
     * generated. The table is created with a static statement if the
     * database does not carry the module schema, and the rows this test
     * writes are deleted by a prepared statement in tearDown().
     */
    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbName);

        try {
            $this->holder = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->requester = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            // Skip only on a laptop that never configured a server. On a
            // CI runner (GitHub sets CI=true) both PHP jobs export
            // TEST_DB_*, so an unset TEST_DB_HOST there is a workflow
            // regression to fail on, not a laptop to accommodate: a lock
            // test that skips itself in CI proves nothing.
            if (getenv('TEST_DB_HOST') === false && getenv('CI') === false) {
                $this->markTestSkipped('No MySQL server configured (TEST_DB_HOST unset): ' . $e->getMessage());
            }

            throw $e;
        }

        // The production DDL (modules/support_dashboard/schema.sql), a no-op
        // where the module schema is already installed.
        $this->requester->exec('CREATE TABLE IF NOT EXISTS support_report_rate_limits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_support_report_rate_limits_lookup (ip_hash, created_at)
        ) ENGINE=InnoDB');
        $this->deleteOwnRows();
    }

    protected function tearDown(): void
    {
        if ($this->holder instanceof \PDO) {
            AdvisoryLock::release($this->holder, SupportReportRateLimitRepository::reservationLockName($this->hash()));
        }
        $this->deleteOwnRows();
        $this->holder = null;
        $this->requester = null;
    }

    private function deleteOwnRows(): void
    {
        if (!$this->requester instanceof \PDO) {
            return;
        }
        $stmt = $this->requester->prepare('DELETE FROM support_report_rate_limits WHERE ip_hash IN (?, ?)');
        $stmt->execute([$this->hash(), $this->otherHash()]);
    }

    private function hash(): string
    {
        return str_repeat('a', 64);
    }

    private function otherHash(): string
    {
        return str_repeat('b', 64);
    }

    private function since(): string
    {
        return (new \DateTimeImmutable('-60 minutes'))->format('Y-m-d H:i:s');
    }

    /**
     * The whole point: while another connection holds the address's
     * lock — which is what a concurrent request from that address looks
     * like from here — a reservation is refused at once and writes
     * nothing, rather than counting a window the other is about to change.
     */
    public function testAReservationIsRefusedWhileAnotherConnectionHoldsTheAddress(): void
    {
        $this->assertTrue(
            AdvisoryLock::acquire($this->holder, SupportReportRateLimitRepository::reservationLockName($this->hash()))
        );
        $repository = new SupportReportRateLimitRepository($this->requester);

        $this->assertFalse($repository->reserve($this->hash(), $this->since(), 20));
        $this->assertSame(0, $repository->countSince($this->hash(), $this->since()));
    }

    public function testTheReservationGoesThroughOnceTheHolderHasLetGo(): void
    {
        $name = SupportReportRateLimitRepository::reservationLockName($this->hash());
        $this->assertTrue(AdvisoryLock::acquire($this->holder, $name));
        AdvisoryLock::release($this->holder, $name);
        $repository = new SupportReportRateLimitRepository($this->requester);

        $this->assertTrue($repository->reserve($this->hash(), $this->since(), 20));
        $this->assertSame(1, $repository->countSince($this->hash(), $this->since()));
    }

    /**
     * The lock lives for the reservation and not a moment longer: after
     * reserve() returns, another connection can take the same name.
     */
    public function testTheLockIsReleasedWhenTheReservationReturns(): void
    {
        $repository = new SupportReportRateLimitRepository($this->requester);
        $this->assertTrue($repository->reserve($this->hash(), $this->since(), 20));

        $this->assertTrue(
            AdvisoryLock::acquire($this->holder, SupportReportRateLimitRepository::reservationLockName($this->hash()))
        );
    }

    /**
     * A different address is a different lock: one address being
     * reserved never makes another wait.
     */
    public function testAnotherAddressIsNotHeldUp(): void
    {
        $this->assertTrue(
            AdvisoryLock::acquire($this->holder, SupportReportRateLimitRepository::reservationLockName($this->hash()))
        );
        $repository = new SupportReportRateLimitRepository($this->requester);

        $this->assertTrue($repository->reserve($this->otherHash(), $this->since(), 20));
    }
}
