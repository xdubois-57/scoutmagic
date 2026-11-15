<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\AppClock;
use Modules\Registration\Repository\ConfirmationSendRepository;
use Modules\Registration\Service\ConfirmationSendLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Twenty secondary-address confirmation e-mails per request per hour, the
 * twenty-first refused (issue #1126).
 */
#[Group('database')]
class ConfirmationSendLimiterTest extends TestCase
{
    private ConfirmationSendRepository $repository;
    private ConfirmationSendLimiter $limiter;
    private int $requestId;
    private int $otherRequestId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($pdo);
        $yearId = RegistrationTestHelper::insertScoutYear($pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->requestId = RegistrationTestHelper::insertRequest($pdo, $yearId, 'a@example.com');
        $this->otherRequestId = RegistrationTestHelper::insertRequest($pdo, $yearId, 'b@example.com');

        $this->repository = new ConfirmationSendRepository($pdo);
        $this->limiter = new ConfirmationSendLimiter($this->repository);
    }

    public function testTheTwentiethSendWithinTheHourIsAcceptedAndTheTwentyFirstRefused(): void
    {
        for ($i = 1; $i <= ConfirmationSendLimiter::LIMIT; $i++) {
            $this->assertTrue($this->limiter->tryAcquire($this->requestId), "send #{$i} should be accepted");
        }

        $this->assertFalse($this->limiter->tryAcquire($this->requestId));
        $this->assertSame(20, ConfirmationSendLimiter::LIMIT);
    }

    public function testARefusedAttemptIsNotCounted(): void
    {
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT + 3; $i++) {
            $this->limiter->tryAcquire($this->requestId);
        }

        $this->assertSame(
            ConfirmationSendLimiter::LIMIT,
            $this->repository->countSince($this->requestId, '2000-01-01 00:00:00')
        );
    }

    public function testTheLimitIsPerRequest(): void
    {
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->limiter->tryAcquire($this->requestId);
        }

        $this->assertTrue($this->limiter->tryAcquire($this->otherRequestId));
    }

    public function testSendsOlderThanAnHourNoLongerCount(): void
    {
        $longAgo = AppClock::now()->modify('-61 minutes')->format('Y-m-d H:i:s');
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->repository->record($this->requestId, $longAgo);
        }

        $this->assertTrue($this->limiter->tryAcquire($this->requestId));
    }

    public function testSendsWithinTheHourDoCount(): void
    {
        $recent = AppClock::now()->modify('-59 minutes')->format('Y-m-d H:i:s');
        for ($i = 0; $i < ConfirmationSendLimiter::LIMIT; $i++) {
            $this->repository->record($this->requestId, $recent);
        }

        $this->assertFalse($this->limiter->tryAcquire($this->requestId));
    }
}
