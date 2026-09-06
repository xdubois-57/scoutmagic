<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * reserve() is the counter and the record in one step
 * (ARCHITECTURE.md §8.49sexies). What is pinned here is its arithmetic
 * on the engine most of the suite runs on: the limit, the window, and
 * one address never counting another's. The mutual exclusion it adds
 * on MySQL/MariaDB is proved against real connections in
 * RateLimitReservationLockTest, because SQLite has no advisory lock to
 * prove it on.
 */
class SupportReportRateLimitRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private SupportReportRateLimitRepository $rateLimits;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->rateLimits = new SupportReportRateLimitRepository($this->pdo);
    }

    private function since(int $minutes): string
    {
        return (new \DateTimeImmutable('-' . $minutes . ' minutes'))->format('Y-m-d H:i:s');
    }

    private function recordAt(string $ipHash, string $when): void
    {
        $this->pdo
            ->prepare('INSERT INTO support_report_rate_limits (ip_hash, created_at) VALUES (?, ?)')
            ->execute([$ipHash, (new \DateTimeImmutable($when))->format('Y-m-d H:i:s')]);
    }

    public function testAReservationRecordsUpToTheLimitAndRefusesPastIt(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->rateLimits->reserve('hash-a', $this->since(60), 3), "attempt {$i}");
        }

        $this->assertFalse($this->rateLimits->reserve('hash-a', $this->since(60), 3));
        $this->assertFalse($this->rateLimits->reserve('hash-a', $this->since(60), 3));
        // A refused reservation writes nothing: the count stays at the limit.
        $this->assertSame(3, $this->rateLimits->countSince('hash-a', $this->since(60)));
    }

    public function testAttemptsOutsideTheWindowDoNotCount(): void
    {
        $this->recordAt('hash-b', '-90 minutes');
        $this->recordAt('hash-b', '-70 minutes');
        $this->recordAt('hash-b', '-10 minutes');

        // Two of the three rows are past a sixty-minute window.
        $this->assertTrue($this->rateLimits->reserve('hash-b', $this->since(60), 2));
        $this->assertFalse($this->rateLimits->reserve('hash-b', $this->since(60), 2));
    }

    public function testOneAddressNeverCountsAnother(): void
    {
        $this->assertTrue($this->rateLimits->reserve('hash-c', $this->since(60), 1));
        $this->assertFalse($this->rateLimits->reserve('hash-c', $this->since(60), 1));

        $this->assertTrue($this->rateLimits->reserve('hash-d', $this->since(60), 1));
    }

    /**
     * `GET_LOCK()` refuses a name longer than 64 characters, and a blind
     * index is already 64 of them.
     */
    public function testTheLockNameFitsWhatTheServerAccepts(): void
    {
        $name = SupportReportRateLimitRepository::reservationLockName(str_repeat('f', 64));

        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertStringStartsWith('support_report_rate_limit:', $name);
        $this->assertNotSame(
            $name,
            SupportReportRateLimitRepository::reservationLockName(str_repeat('0', 64))
        );
    }
}
