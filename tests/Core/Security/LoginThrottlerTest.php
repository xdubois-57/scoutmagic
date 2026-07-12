<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Security\LoginThrottler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class LoginThrottlerTest extends TestCase
{
    private LoginThrottler $throttler;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        // SQLite uses different date functions, so we need a mock Connection
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->throttler = new LoginThrottler($connection);
    }

    public function testNoLockoutForFirstFourFailures(): void
    {
        $blindIndex = 'test_blind_index_1';

        for ($i = 0; $i < 4; $i++) {
            $this->insertFailure($blindIndex);
        }

        $lockout = $this->throttler->getLockoutRemaining($blindIndex);
        $this->assertSame(0, $lockout);
    }

    public function testLockoutTriggersAtFiveFailures(): void
    {
        $blindIndex = 'test_blind_index_2';

        for ($i = 0; $i < 5; $i++) {
            $this->insertFailure($blindIndex);
        }

        $lockout = $this->throttler->getLockoutRemaining($blindIndex);
        $this->assertGreaterThan(0, $lockout);
        $this->assertLessThanOrEqual(60, $lockout);
    }

    public function testLockoutDurationIncreasesWithMoreFailures(): void
    {
        $blindIndex = 'test_blind_index_3';

        for ($i = 0; $i < 10; $i++) {
            $this->insertFailure($blindIndex);
        }

        $lockout = $this->throttler->getLockoutRemaining($blindIndex);
        $this->assertGreaterThan(60, $lockout);
        $this->assertLessThanOrEqual(300, $lockout);
    }

    public function testClearFailuresResetsCounter(): void
    {
        $blindIndex = 'test_blind_index_4';

        for ($i = 0; $i < 5; $i++) {
            $this->insertFailure($blindIndex);
        }

        $this->throttler->clearFailures($blindIndex);

        $lockout = $this->throttler->getLockoutRemaining($blindIndex);
        $this->assertSame(0, $lockout);
    }

    public function testRecordFailureInsertsRow(): void
    {
        $blindIndex = 'test_blind_index_5';
        $this->throttler->recordFailure($blindIndex);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testFailuresOutsideOneHourWindowAreNotCounted(): void
    {
        $blindIndex = 'test_blind_index_6';

        // Insert old failures (2 hours ago)
        $twoHoursAgo = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        for ($i = 0; $i < 20; $i++) {
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email_blind_index, attempted_at) VALUES (?, ?)');
            $stmt->execute([$blindIndex, $twoHoursAgo]);
        }

        $lockout = $this->throttler->getLockoutRemaining($blindIndex);
        $this->assertSame(0, $lockout);
    }

    private function insertFailure(string $blindIndex): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email_blind_index, attempted_at) VALUES (?, ?)');
        $stmt->execute([$blindIndex, $now]);
    }
}
