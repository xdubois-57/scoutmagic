<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\RateLimitRepository;
use Modules\Groups\Service\GroupsException;
use Modules\Groups\Service\RateLimitService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Flood protection: generous enough that a lively conversation never
 * notices it, tight enough that a runaway script does.
 *
 * @group database
 */
#[Group('database')]
class RateLimitServiceTest extends TestCase
{
    private \PDO $pdo;
    private RateLimitService $service;
    private RateLimitRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->repository = new RateLimitRepository($this->pdo);
        $this->service = new RateLimitService($this->repository);
    }

    public function testAnOrdinaryBurstIsAllowedAndRecorded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord(3, RateLimitService::ACTION_POST);
        }

        $this->assertSame(5, $this->repository->countSince(3, 'post', '2000-01-01 00:00:00'));
    }

    public function testPostingPastTheLimitIsRefusedWithATypedException(): void
    {
        $caught = null;
        try {
            for ($i = 0; $i < 100; $i++) {
                $this->service->checkAndRecord(3, RateLimitService::ACTION_POST);
            }
        } catch (GroupsException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame(GroupsException::TYPE_RATE_LIMITED, $caught->type);
        // The composer reacts to the type, never to the message string —
        // and a rate limit carries no suggestion to show.
        $this->assertNull($caught->suggestion);
    }

    public function testThePostAndReplyBudgetsAreSeparate(): void
    {
        try {
            for ($i = 0; $i < 100; $i++) {
                $this->service->checkAndRecord(3, RateLimitService::ACTION_POST);
            }
        } catch (GroupsException) {
            // expected: the post budget is now spent
        }

        // Being unable to start a new thread must not stop someone
        // answering an existing one.
        $this->service->checkAndRecord(3, RateLimitService::ACTION_REPLY);

        $this->assertSame(1, $this->repository->countSince(3, 'reply', '2000-01-01 00:00:00'));
    }

    public function testTheBudgetIsPerMember(): void
    {
        try {
            for ($i = 0; $i < 100; $i++) {
                $this->service->checkAndRecord(3, RateLimitService::ACTION_POST);
            }
        } catch (GroupsException) {
            // expected
        }

        $this->service->checkAndRecord(4, RateLimitService::ACTION_POST);

        $this->assertSame(1, $this->repository->countSince(4, 'post', '2000-01-01 00:00:00'));
    }

    /**
     * The window slides: rows older than it are not counted, whether or
     * not the purge task has swept them yet.
     */
    public function testRowsOutsideTheWindowDoNotCountAgainstTheLimit(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_rate_limits (member_id, action_type, created_at) VALUES (?, ?, ?)'
        );
        for ($i = 0; $i < 50; $i++) {
            $stmt->execute([3, 'post', '2020-01-01 00:00:00']);
        }

        $this->service->checkAndRecord(3, RateLimitService::ACTION_POST);

        $this->assertSame(51, $this->repository->countSince(3, 'post', '2000-01-01 00:00:00'));
    }

    public function testAnUnknownActionTypeIsNotSilentlyRateLimited(): void
    {
        // No configured budget means no limit rather than a limit of
        // zero — a typo in a caller must not lock a member out.
        for ($i = 0; $i < 60; $i++) {
            $this->service->checkAndRecord(3, 'something_else');
        }

        $this->assertSame(60, $this->repository->countSince(3, 'something_else', '2000-01-01 00:00:00'));
    }
}
