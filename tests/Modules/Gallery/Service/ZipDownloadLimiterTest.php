<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\AppClock;
use Modules\Gallery\Repository\ZipDownloadRepository;
use Modules\Gallery\Service\ZipDownloadLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * Ten whole-album ZIPs per account per hour, the eleventh refused (issue
 * #1112, audit SEC-013).
 */
#[Group('database')]
class ZipDownloadLimiterTest extends TestCase
{
    private \PDO $pdo;
    private ZipDownloadRepository $repository;
    private ZipDownloadLimiter $limiter;
    private int $accountId;
    private int $otherAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc-a', 'idx-a']);
        $this->accountId = (int) $this->pdo->lastInsertId();
        $stmt->execute(['enc-b', 'idx-b']);
        $this->otherAccountId = (int) $this->pdo->lastInsertId();

        $this->repository = new ZipDownloadRepository($this->pdo);
        $this->limiter = new ZipDownloadLimiter($this->repository);
    }

    public function testTheTenthZipWithinTheHourIsAcceptedAndTheEleventhRefused(): void
    {
        for ($i = 1; $i <= ZipDownloadLimiter::LIMIT; $i++) {
            $this->assertTrue($this->limiter->tryAcquire($this->accountId), "ZIP #{$i} should be accepted");
        }

        $this->assertFalse($this->limiter->tryAcquire($this->accountId));
        $this->assertSame(10, ZipDownloadLimiter::LIMIT);
    }

    public function testARefusedAttemptIsNotCounted(): void
    {
        for ($i = 0; $i < ZipDownloadLimiter::LIMIT + 3; $i++) {
            $this->limiter->tryAcquire($this->accountId);
        }

        $this->assertSame(
            ZipDownloadLimiter::LIMIT,
            $this->repository->countSince($this->accountId, '2000-01-01 00:00:00')
        );
    }

    public function testTheLimitIsPerAccount(): void
    {
        for ($i = 0; $i < ZipDownloadLimiter::LIMIT; $i++) {
            $this->limiter->tryAcquire($this->accountId);
        }

        $this->assertTrue($this->limiter->tryAcquire($this->otherAccountId));
    }

    public function testDownloadsOlderThanAnHourNoLongerCount(): void
    {
        $longAgo = AppClock::now()->modify('-61 minutes')->format('Y-m-d H:i:s');
        for ($i = 0; $i < ZipDownloadLimiter::LIMIT; $i++) {
            $this->repository->record($this->accountId, $longAgo);
        }

        $this->assertTrue($this->limiter->tryAcquire($this->accountId));
    }

    public function testDownloadsWithinTheHourDoCount(): void
    {
        $recent = AppClock::now()->modify('-59 minutes')->format('Y-m-d H:i:s');
        for ($i = 0; $i < ZipDownloadLimiter::LIMIT; $i++) {
            $this->repository->record($this->accountId, $recent);
        }

        $this->assertFalse($this->limiter->tryAcquire($this->accountId));
    }
}
