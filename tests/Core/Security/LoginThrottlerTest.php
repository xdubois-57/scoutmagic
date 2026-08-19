<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Security\LoginThrottler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class LoginThrottlerTest extends TestCase
{
    private LoginThrottler $throttler;
    private LoginThrottler $ipThrottler;
    private EncryptionService $encryption;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        // SQLite uses different date functions, so we need a mock Connection
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->throttler = new LoginThrottler($connection);

        // A second instance that also counts per source IP — the key is
        // what turns a raw address into the stored blind index.
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->ipThrottler = new LoginThrottler($connection, $this->encryption);
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

    /**
     * The attack per-email counting is structurally blind to: one likely
     * password tried against MANY accounts. No single address ever
     * accumulates enough failures to trip the email lockout, so before the
     * IP axis existed a spray ran completely unthrottled.
     */
    public function testASprayAcrossManyAccountsFromOneIpIsThrottled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->ipThrottler->recordFailure('victim_' . $i, '203.0.113.9');
        }

        // No single account is anywhere near its own 5-failure threshold...
        $this->assertSame(0, $this->throttler->getLockoutRemaining('victim_0'));

        // ...but the source is now locked out.
        $this->assertGreaterThan(0, $this->ipThrottler->getLockoutRemaining('victim_31', '203.0.113.9'));
    }

    public function testTheIpAxisToleratesNormalSharedConnectionUse(): void
    {
        // A busy shared NAT: several people fumbling their password.
        for ($i = 0; $i < 20; $i++) {
            $this->ipThrottler->recordFailure('user_' . $i, '203.0.113.10');
        }

        $this->assertSame(0, $this->ipThrottler->getLockoutRemaining('someone_else', '203.0.113.10'));
    }

    public function testTheIpLockoutEscalatesWithVolume(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->ipThrottler->recordFailure('acct_' . $i, '203.0.113.11');
        }
        $this->assertGreaterThan(60, $this->ipThrottler->getLockoutRemaining('acct_x', '203.0.113.11'));

        for ($i = 60; $i < 100; $i++) {
            $this->ipThrottler->recordFailure('acct_' . $i, '203.0.113.11');
        }
        $this->assertGreaterThan(300, $this->ipThrottler->getLockoutRemaining('acct_x', '203.0.113.11'));
    }

    public function testADifferentIpIsUnaffectedByAnotherSourcesLockout(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->ipThrottler->recordFailure('acct_' . $i, '203.0.113.12');
        }

        $this->assertGreaterThan(0, $this->ipThrottler->getLockoutRemaining('acct_x', '203.0.113.12'));
        $this->assertSame(0, $this->ipThrottler->getLockoutRemaining('acct_x', '198.51.100.4'));
    }

    /**
     * One attacker succeeding on an account they legitimately control must
     * not wipe the record of the spray they are running from the same
     * address against everybody else.
     */
    public function testASuccessfulLoginClearsOnlyTheEmailAxis(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->ipThrottler->recordFailure('acct_' . $i, '203.0.113.13');
        }

        $this->ipThrottler->clearFailures('acct_0');

        $this->assertGreaterThan(0, $this->ipThrottler->getLockoutRemaining('acct_0', '203.0.113.13'));
    }

    /**
     * The raw address is never stored — only its blind index, like every
     * other searchable personal datum (SECURITY.md §5).
     */
    public function testTheRawIpAddressIsNeverWrittenToTheDatabase(): void
    {
        $this->ipThrottler->recordFailure('someone', '203.0.113.14');

        $stored = (string) $this->pdo->query('SELECT ip_blind_index FROM login_attempts')->fetchColumn();

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('203.0.113.14', $stored);
        $this->assertSame($this->encryption->blindIndex('203.0.113.14'), $stored);
    }

    /**
     * Without a key there is nothing to hash the address with, so the IP
     * axis simply doesn't apply — it must not start counting everyone
     * together under a NULL bucket.
     */
    public function testWithoutAnEncryptionKeyTheIpAxisIsInert(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->throttler->recordFailure('acct_' . $i, '203.0.113.15');
        }

        $this->assertSame(0, $this->throttler->getLockoutRemaining('acct_x', '203.0.113.15'));
    }

    public function testAnEmptyIpIsIgnoredRatherThanBucketedTogether(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->ipThrottler->recordFailure('acct_' . $i, '');
        }

        $this->assertSame(0, $this->ipThrottler->getLockoutRemaining('acct_x', ''));
    }
}
