<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\LinkFetchLogRepository;
use Modules\Groups\Service\LinkFetchThrottleService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class LinkFetchThrottleServiceTest extends TestCase
{
    private \PDO $pdo;
    private LinkFetchThrottleService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->service = new LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo));
    }

    public function testAFreshMemberIsAllowed(): void
    {
        $this->assertTrue($this->service->allowFetch(1));
    }

    public function testTheSameMemberIsAllowedRepeatedlyUnderTheLimit(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->assertTrue($this->service->allowFetch(1), "attempt {$i}");
        }
    }

    public function testAMemberIsThrottledAfterExceedingTheLimitWithinTheWindow(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->service->allowFetch(1);
        }

        $this->assertFalse($this->service->allowFetch(1));
    }

    public function testEveryAttemptIsRecordedEvenWhenThrottled(): void
    {
        // The throttled attempt itself still counts — otherwise a member
        // stuck exactly at the limit could poll forever without ever
        // being charged for the extra attempts.
        for ($i = 0; $i < 20; $i++) {
            $this->service->allowFetch(1);
        }

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_link_fetch_log WHERE member_id = 1')->fetchColumn();
        $this->assertSame(20, $count);
    }

    public function testDifferentMembersHaveIndependentBudgets(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->service->allowFetch(1);
        }

        $this->assertFalse($this->service->allowFetch(1));
        $this->assertTrue($this->service->allowFetch(2));
    }

    public function testAnAttemptOutsideTheWindowButStillWithinRetentionIsNotCountedAgainstTheLimit(): void
    {
        $repository = new LinkFetchLogRepository($this->pdo);
        for ($i = 0; $i < 15; $i++) {
            $repository->record(1);
        }
        // Past the 10-minute counting window but still inside the
        // 60-minute retention — distinguishes "excluded from the count"
        // from "already purged", which -71 minutes would conflate.
        $this->pdo->exec("UPDATE discussion_group_link_fetch_log SET created_at = datetime('now', '-11 minutes')");

        $this->assertTrue($this->service->allowFetch(1));
    }
}
