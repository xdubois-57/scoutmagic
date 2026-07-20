<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class OnCallServiceTest extends TestCase
{
    private \PDO $pdo;
    private OnCallService $service;
    private OnCallRepository $onCallRepository;
    private SchedulerService $schedulerService;
    private SchedulerRepository $schedulerRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);

        $this->onCallRepository = new OnCallRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->schedulerService = new SchedulerService($this->schedulerRepository);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $settingService->register('email_notifications_enabled', '1', 'boolean', 'Emails', 'desc', 'sos_staff');

        $settingsService = $this->createMock(SosSettingsService::class);
        $settingsService->method('getTransitionHour')->willReturn('10:00');

        $this->service = new OnCallService($this->onCallRepository, $this->schedulerService, $settingsService);
    }

    private function pendingReferencesFor(string $moduleId, string $taskKey): array
    {
        $rows = $this->schedulerService->findAllForTask($moduleId, $taskKey, 1000);
        return array_values(array_map(
            fn($r) => $r['reference'],
            array_filter($rows, fn($r) => $r['status'] === 'pending')
        ));
    }

    public function testGetMonthGridReturnsAllDaysOfMonth(): void
    {
        $grid = $this->service->getMonthGrid(2026, 7);

        $this->assertCount(31, $grid['days']);
        $this->assertSame('2026-07-01', $grid['days'][0]['date']);
        $this->assertSame('2026-07-31', $grid['days'][30]['date']);
    }

    public function testGetMonthGridMarksWeekends(): void
    {
        // 2026-07-04 is a Saturday.
        $grid = $this->service->getMonthGrid(2026, 7);

        $day4 = $grid['days'][3];
        $this->assertSame('2026-07-04', $day4['date']);
        $this->assertTrue($day4['is_weekend']);

        $day1 = $grid['days'][0];
        $this->assertSame('2026-07-01', $day1['date']);
        $this->assertFalse($day1['is_weekend']);
    }

    public function testGetMonthGridIncludesStatesForAssignedDays(): void
    {
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(1, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        $grid = $this->service->getMonthGrid(2026, 7);

        $this->assertSame(OnCallAssignment::STATE_ONCALL, $grid['states']['2026-07-05'][1]);
    }

    public function testSaveMonthPersistsAssignments(): void
    {
        $this->service->saveMonth(2026, 7, [
            ['member_id' => 1, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1], 100);

        $grid = $this->service->getMonthGrid(2026, 7);
        $this->assertSame(OnCallAssignment::STATE_ONCALL, $grid['states']['2026-07-05'][1]);
    }

    public function testSaveMonthSchedulesTransitionOnFirstOnCallDay(): void
    {
        $result = $this->service->saveMonth(2026, 7, [
            ['member_id' => 1, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1], 100);

        // Two real transitions: into member 1 on the 5th, and back to the
        // default number on the 6th once the (single-day) streak ends —
        // without that second transition the redirect would incorrectly
        // stay pointed at member 1 forever.
        $this->assertCount(2, $result['transitions']);
        $this->assertSame('2026-07-05', $result['transitions'][0]['date']);
        $this->assertSame(1, $result['transitions'][0]['member_id']);
        $this->assertSame('2026-07-06', $result['transitions'][1]['date']);
        $this->assertNull($result['transitions'][1]['member_id']);
    }

    public function testSaveMonthDoesNotScheduleTransitionOnConsecutiveSamePersonDays(): void
    {
        $cells = [];
        foreach (range(5, 10) as $day) {
            $cells[] = ['member_id' => 1, 'date' => sprintf('2026-07-%02d', $day), 'state' => OnCallAssignment::STATE_ONCALL];
        }

        $result = $this->service->saveMonth(2026, 7, $cells, [1], 100);

        // Two transitions bracket the streak: into member 1 on the first
        // day, back to default the day after the last — nothing in
        // between, even though the streak spans 6 days.
        $this->assertCount(2, $result['transitions']);
        $this->assertSame('2026-07-05', $result['transitions'][0]['date']);
        $this->assertSame(1, $result['transitions'][0]['member_id']);
        $this->assertSame('2026-07-11', $result['transitions'][1]['date']);
        $this->assertNull($result['transitions'][1]['member_id']);
    }

    public function testSaveMonthSchedulesTransitionWhenPersonChanges(): void
    {
        $cells = [
            ['member_id' => 1, 'date' => '2026-07-01', 'state' => OnCallAssignment::STATE_ONCALL],
            ['member_id' => 1, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
            ['member_id' => 2, 'date' => '2026-07-06', 'state' => OnCallAssignment::STATE_ONCALL],
            ['member_id' => 2, 'date' => '2026-07-10', 'state' => OnCallAssignment::STATE_ONCALL],
        ];

        $result = $this->service->saveMonth(2026, 7, $cells, [1, 2], 100);

        $dates = array_column($result['transitions'], 'date');
        $this->assertContains('2026-07-01', $dates);
        $this->assertContains('2026-07-06', $dates);
    }

    public function testSaveMonthUsesFirstRosterMemberWhenMultipleOnCallSameDay(): void
    {
        $cells = [
            ['member_id' => 2, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
            ['member_id' => 1, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
        ];

        // Roster order [1, 2] — member 1 wins even though member 2's row
        // was inserted first.
        $result = $this->service->saveMonth(2026, 7, $cells, [1, 2], 100);

        $this->assertSame(1, $result['transitions'][0]['member_id']);
    }

    public function testSaveMonthChecksPreviousMonthLastDayToAvoidRedundantTransition(): void
    {
        // June 30 already has member 1 on call.
        $this->onCallRepository->replaceRange('2026-06-01', '2026-06-30', [
            new OnCallAssignment(1, '2026-06-30', OnCallAssignment::STATE_ONCALL),
        ]);

        // July 1 also has member 1 — no real change on the 1st itself, so
        // the only transition is the 2nd reverting to default (nothing is
        // marked for the rest of July in this test).
        $result = $this->service->saveMonth(2026, 7, [
            ['member_id' => 1, 'date' => '2026-07-01', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1], 100);

        $dates = array_column($result['transitions'], 'date');
        $this->assertNotContains('2026-07-01', $dates);
        $this->assertContains('2026-07-02', $dates);
    }

    public function testSaveMonthSchedulesDay1TransitionWhenPersonDiffersFromPreviousMonth(): void
    {
        $this->onCallRepository->replaceRange('2026-06-01', '2026-06-30', [
            new OnCallAssignment(1, '2026-06-30', OnCallAssignment::STATE_ONCALL),
        ]);

        $result = $this->service->saveMonth(2026, 7, [
            ['member_id' => 2, 'date' => '2026-07-01', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1, 2], 100);

        $this->assertSame('2026-07-01', $result['transitions'][0]['date']);
        $this->assertSame(2, $result['transitions'][0]['member_id']);
    }

    public function testSaveMonthCancelsStalePendingTransitionsOnResave(): void
    {
        $this->service->saveMonth(2026, 7, [
            ['member_id' => 1, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1], 100);

        $this->service->saveMonth(2026, 7, [
            ['member_id' => 1, 'date' => '2026-07-03', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1], 100);

        $pending = $this->pendingReferencesFor(OnCallService::MODULE_ID, OnCallService::TASK_KEY);
        // The stale day-5/day-6 pair from the first save must be gone,
        // replaced by the fresh day-3/day-4 pair (order not asserted —
        // findAllForTask() sorts newest run_at first).
        $this->assertEqualsCanonicalizing(['2026-07-03', '2026-07-04'], $pending);
    }

    public function testResolveTargetForDateReturnsOnCallMemberOrNull(): void
    {
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment(1, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->assertSame(1, $this->service->resolveTargetForDate('2026-07-05', [1]));
        $this->assertNull($this->service->resolveTargetForDate('2026-07-06', [1]));
    }

    public function testSaveMonthIncludesScoutYearIdInScheduledPayload(): void
    {
        $this->service->saveMonth(2026, 7, [
            ['member_id' => 1, 'date' => '2026-07-05', 'state' => OnCallAssignment::STATE_ONCALL],
        ], [1], 42);

        $rows = $this->schedulerService->findAllForTask(OnCallService::MODULE_ID, OnCallService::TASK_KEY, 1000);
        $payload = json_decode($rows[0]['payload'], true);
        $this->assertSame(42, $payload['scout_year_id']);
    }

    public function testCleanupOlderThanOneYearRemovesOldAssignmentsAndScheduledActions(): void
    {
        $this->onCallRepository->replaceRange('2024-01-01', '2024-01-31', [
            new OnCallAssignment(1, '2024-01-15', OnCallAssignment::STATE_ONCALL),
        ]);
        $this->schedulerRepository->create(OnCallService::MODULE_ID, OnCallService::TASK_KEY, '2024-01-15 10:00:00', null, '2024-01-15');

        $deleted = $this->service->cleanupOlderThanOneYear();

        $this->assertSame(1, $deleted);
        $this->assertSame([], $this->onCallRepository->findForRange('2024-01-01', '2024-01-31'));
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey(OnCallService::MODULE_ID, OnCallService::TASK_KEY));
    }
}
