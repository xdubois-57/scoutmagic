<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Support\Timestamps;
use Modules\Groups\Task\CloseInactiveGroupsHandler;
use Modules\Groups\Task\EnsureSectionGroupsHandler;
use Modules\Groups\Task\PurgeClosedGroupsHandler;
use Modules\Groups\Task\PurgePostsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The four lifecycle handlers as Core\Scheduler actually runs them: with
 * nothing but a TaskContext, since SchedulerRunner constructs every
 * handler with a bare `new $handlerClass()`.
 *
 * Two things are asserted of every one of them — that it does its job
 * from a TaskContext alone, and that it books its own successor, because
 * Core\Scheduler has no first-class recurring task and a handler that
 * forgot to reschedule would simply stop running one day with nothing to
 * notice it.
 *
 * @group database
 */
#[Group('database')]
class LifecycleHandlersTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;
    private SchedulerService $scheduler;
    private GroupRepository $groupRepo;
    private int $currentYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, \Tests\DatabaseTestHelper::scoutYear()[0], true);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testEnsureSectionGroupsCreatesTheMissingGroups(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        (new EnsureSectionGroupsHandler())->handle([], $this->context);

        $this->assertNotNull($this->groupRepo->findSectionGroup($sectionId, $this->currentYearId));
    }

    public function testEnsureSectionGroupsIsIdempotentAcrossRuns(): void
    {
        GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        (new EnsureSectionGroupsHandler())->handle([], $this->context);
        (new EnsureSectionGroupsHandler())->handle([], $this->context);
        (new EnsureSectionGroupsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->countGroups());
    }

    /**
     * A chief can prepare next year's groups before the public switch —
     * the staff-year mechanism (ARCHITECTURE.md §8.26) already shows them
     * that year's content, so the group only has to exist.
     */
    public function testEnsureSectionGroupsAlsoCoversAFutureYearAlreadyImported(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $nextYearId = GroupsTestHelper::createScoutYear($this->pdo, \Tests\DatabaseTestHelper::scoutYear(1)[0], false);

        (new EnsureSectionGroupsHandler())->handle([], $this->context);

        $this->assertNotNull($this->groupRepo->findSectionGroup($sectionId, $this->currentYearId));
        $this->assertNotNull($this->groupRepo->findSectionGroup($sectionId, $nextYearId));
    }

    /**
     * Never a PAST year: its groups either already exist or were
     * deliberately purged, and recreating them nightly would undo the
     * retention purge.
     */
    public function testEnsureSectionGroupsNeverCreatesAGroupForAPastYear(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);

        (new EnsureSectionGroupsHandler())->handle([], $this->context);

        $this->assertNull($this->groupRepo->findSectionGroup($sectionId, $pastYearId));
    }

    /**
     * The loop the current-year purge protection exists to prevent, seen
     * from the other side: a group of a still-effective year is never
     * purged, so there is never a deleted-then-recreated cycle — and
     * hence no tombstone table to maintain.
     */
    public function testAPurgedPastYearGroupIsNotRecreatedWhileItsYearIsNotEffective(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->groupRepo->create('Louveteaux', $pastYearId, $sectionId, null);
        $this->groupRepo->setClosed($groupId, $this->at('-24 months'));

        (new PurgeClosedGroupsHandler())->handle([], $this->context);
        $this->assertNull($this->groupRepo->findById($groupId));

        (new EnsureSectionGroupsHandler())->handle([], $this->context);
        $this->assertNull(
            $this->groupRepo->findSectionGroup($sectionId, $pastYearId),
            'a purged past-year group must not come back on the next nightly run'
        );
    }

    /**
     * And the current year's group IS recreated — which is exactly why it
     * must never be purged in the first place.
     */
    public function testACurrentYearSectionGroupIsRecreatedIfItEverWentMissing(): void
    {
        $sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        (new EnsureSectionGroupsHandler())->handle([], $this->context);
        $groupId = $this->groupRepo->findSectionGroup($sectionId, $this->currentYearId)->id;

        $this->groupRepo->delete($groupId);
        (new EnsureSectionGroupsHandler())->handle([], $this->context);

        $this->assertNotNull($this->groupRepo->findSectionGroup($sectionId, $this->currentYearId));
    }

    public function testCloseInactiveGroupsClosesAnIdleGroup(): void
    {
        $groupId = $this->groupRepo->create('Louveteaux', $this->currentYearId, null, null);
        $this->groupRepo->touchActivity($groupId, $this->at('-24 months'));

        (new CloseInactiveGroupsHandler())->handle([], $this->context);

        $this->assertTrue($this->groupRepo->findById($groupId)->isClosed());
    }

    /**
     * The freshly-created-group pitfall, at handler level: creating the
     * groups and then running the closer on the same day must leave them
     * all open.
     */
    public function testGroupsCreatedTodayAreStillOpenAfterTheCloserRuns(): void
    {
        GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');
        (new EnsureSectionGroupsHandler())->handle([], $this->context);
        (new CloseInactiveGroupsHandler())->handle([], $this->context);

        $rows = $this->pdo->query('SELECT closed_at FROM discussion_groups')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([null], $rows);
    }

    public function testPurgePostsDeletesAnOverduePost(): void
    {
        $groupId = $this->groupRepo->create('Louveteaux', $this->currentYearId, null, null);
        GroupsTestHelper::createPostAt($this->pdo, $groupId, 'vieux', $this->at('-40 months'));

        (new PurgePostsHandler())->handle([], $this->context);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_posts')->fetchColumn());
    }

    public function testPurgePostsLeavesAPinnedPostAlone(): void
    {
        $groupId = $this->groupRepo->create('Louveteaux', $this->currentYearId, null, null);
        GroupsTestHelper::createPostAt($this->pdo, $groupId, 'épinglé', $this->at('-40 months'), 7, 3, true);

        (new PurgePostsHandler())->handle([], $this->context);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_posts')->fetchColumn());
    }

    public function testPurgeClosedGroupsDeletesAnOverdueClosedGroupOfAPastYear(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->groupRepo->create('Ancien', $pastYearId, null, null);
        $this->groupRepo->setClosed($groupId, $this->at('-24 months'));

        (new PurgeClosedGroupsHandler())->handle([], $this->context);

        $this->assertNull($this->groupRepo->findById($groupId));
    }

    public function testPurgeClosedGroupsSparesTheCurrentYear(): void
    {
        $groupId = $this->groupRepo->create('Louveteaux', $this->currentYearId, null, null);
        $this->groupRepo->setClosed($groupId, $this->at('-60 months'));

        (new PurgeClosedGroupsHandler())->handle([], $this->context);

        $this->assertNotNull($this->groupRepo->findById($groupId));
    }

    /**
     * Every handler books tomorrow's run before returning. Without this,
     * each task would fire exactly once ever.
     */
    public function testEveryHandlerReschedulesItselfDaily(): void
    {
        foreach ([
            EnsureSectionGroupsHandler::TASK_KEY => new EnsureSectionGroupsHandler(),
            CloseInactiveGroupsHandler::TASK_KEY => new CloseInactiveGroupsHandler(),
            PurgePostsHandler::TASK_KEY => new PurgePostsHandler(),
            PurgeClosedGroupsHandler::TASK_KEY => new PurgeClosedGroupsHandler(),
        ] as $taskKey => $handler) {
            $handler->handle([], $this->context);

            $scheduled = $this->scheduler->find('groups', $taskKey, 'daily');
            $this->assertNotNull($scheduled, "{$taskKey} must book its own next run");
            $this->assertSame('pending', $scheduled['status']);
        }
    }

    /**
     * A group deleted by the purge must not leave a scheduled task
     * pointing at it — these tasks are group-agnostic by design (they
     * carry no group id in their payload), which is what makes that true
     * by construction rather than by cleanup.
     */
    public function testNoScheduledTaskEverCarriesAGroupId(): void
    {
        (new PurgeClosedGroupsHandler())->handle([], $this->context);

        $payloads = $this->pdo
            ->query("SELECT payload FROM scheduled_actions WHERE module_id = 'groups'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertNotSame([], $payloads);
        foreach ($payloads as $payload) {
            $this->assertStringNotContainsString('group_id', (string) $payload);
        }
    }

    private function countGroups(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_groups')->fetchColumn();
    }

    private function at(string $modifier): string
    {
        return Timestamps::at($modifier);
    }
}
