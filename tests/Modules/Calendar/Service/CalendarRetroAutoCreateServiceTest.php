<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Service\CalendarRetroAutoCreateService;
use Modules\Retro\Api\RetroEventLinkLookupInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CalendarRetroAutoCreateServiceTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerService $schedulerService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
    }

    private function event(int $id = 1, bool $autoCreateRetro = true, string $startDate = '2099-01-01', ?string $startTime = null): CalendarEvent
    {
        return new CalendarEvent(
            id: $id, calendarId: 1, title: 'Camp', startDate: $startDate, endDate: null,
            startTime: $startTime, endTime: null, location: null, description: null,
            sequence: 0, autoCreateRetro: $autoCreateRetro, createdBy: null, updatedAt: '2026-01-01 00:00:00'
        );
    }

    public function testSchedulesATaskWhenAutoCreateIsSetAndNoBoardIsLinked(): void
    {
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->method('hasLinkedBoard')->willReturn(false);
        $service = new CalendarRetroAutoCreateService($this->schedulerService, $lookup);

        $service->syncAutoCreateForEvent($this->event());

        $scheduled = $this->schedulerService->find('calendar', 'auto_create_retro', 'event-1');
        $this->assertNotNull($scheduled);
    }

    public function testDoesNotScheduleWhenAutoCreateIsUnchecked(): void
    {
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->method('hasLinkedBoard')->willReturn(false);
        $service = new CalendarRetroAutoCreateService($this->schedulerService, $lookup);

        $service->syncAutoCreateForEvent($this->event(autoCreateRetro: false));

        $this->assertNull($this->schedulerService->find('calendar', 'auto_create_retro', 'event-1'));
    }

    public function testDoesNotScheduleWhenABoardIsAlreadyLinked(): void
    {
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->method('hasLinkedBoard')->willReturn(true);
        $service = new CalendarRetroAutoCreateService($this->schedulerService, $lookup);

        $service->syncAutoCreateForEvent($this->event());

        $this->assertNull($this->schedulerService->find('calendar', 'auto_create_retro', 'event-1'));
    }

    public function testDegradesGracefullyWithoutTheRetroModule(): void
    {
        $service = new CalendarRetroAutoCreateService($this->schedulerService, null);

        // Must not throw — still schedules, since "retro absent" behaves
        // like "nothing linked yet" per the module's own docblock.
        $service->syncAutoCreateForEvent($this->event());

        $this->assertNotNull($this->schedulerService->find('calendar', 'auto_create_retro', 'event-1'));
    }

    public function testUncheckingOnUpdateCancelsAPreviouslyScheduledTask(): void
    {
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->method('hasLinkedBoard')->willReturn(false);
        $service = new CalendarRetroAutoCreateService($this->schedulerService, $lookup);

        $service->syncAutoCreateForEvent($this->event(autoCreateRetro: true));
        $this->assertNotNull($this->schedulerService->find('calendar', 'auto_create_retro', 'event-1'));

        $service->syncAutoCreateForEvent($this->event(autoCreateRetro: false));

        $this->assertNull($this->schedulerService->find('calendar', 'auto_create_retro', 'event-1'));
    }

    public function testResavingNeverLeavesAStaleDuplicateTask(): void
    {
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->method('hasLinkedBoard')->willReturn(false);
        $service = new CalendarRetroAutoCreateService($this->schedulerService, $lookup);

        $service->syncAutoCreateForEvent($this->event());
        $service->syncAutoCreateForEvent($this->event());
        $service->syncAutoCreateForEvent($this->event());

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'calendar' AND task_key = 'auto_create_retro' AND reference = 'event-1' AND status = 'pending'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCancelAutoCreateForEventCancelsAPendingTask(): void
    {
        $lookup = $this->createMock(RetroEventLinkLookupInterface::class);
        $lookup->method('hasLinkedBoard')->willReturn(false);
        $service = new CalendarRetroAutoCreateService($this->schedulerService, $lookup);
        $service->syncAutoCreateForEvent($this->event());

        $service->cancelAutoCreateForEvent(1);

        $this->assertNull($this->schedulerService->find('calendar', 'auto_create_retro', 'event-1'));
    }
}
