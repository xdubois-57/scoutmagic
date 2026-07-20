<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarException;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarNotificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private CalendarNotificationService $service;
    private CalendarEventRepository $eventRepository;
    private CalendarRepository $calendarRepository;
    private SchedulerRepository $schedulerRepository;
    private int $sectionCalendarId;
    private int $supplementaryCalendarId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $this->calendarRepository = new CalendarRepository($this->pdo);
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $sectionService = new SectionService(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new MemberBadgeRepository($this->pdo)
        );
        $calendarService = new CalendarService(
            $this->calendarRepository, $this->eventRepository, $sectionService, new CalendarUnitFeedTokenRepository($this->pdo)
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('notify_multiday_events_enabled', '0', 'boolean', 'Rappels', 'desc', 'calendar');
        $settingService->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->service = new CalendarNotificationService(
            new SchedulerService($this->schedulerRepository),
            $settingService,
            $calendarService,
            $this->eventRepository
        );

        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['ECL', 'Éclaireurs', 30]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['ECL01', $branchId, 'Éclaireurs']);
        $sectionId = (int) $this->pdo->lastInsertId();

        $this->sectionCalendarId = $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->supplementaryCalendarId = $this->calendarRepository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');
    }

    private function createEvent(int $calendarId, string $startDate, ?string $endDate): int
    {
        return $this->eventRepository->create($calendarId, 'Camp', $startDate, $endDate, null, null, null, null, null);
    }

    private function scheduledRowCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'calendar' AND task_key = 'multiday_event_reminder' AND status = 'pending'");
        return (int) $stmt->fetchColumn();
    }

    public function testDefaultsAreDisabledWithFourteenDays(): void
    {
        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(14, $this->service->getDaysBefore());
    }

    public function testSyncReminderDoesNothingWhenDisabled(): void
    {
        $farFuture = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $farFuture, (new \DateTimeImmutable($farFuture))->modify('+3 days')->format('Y-m-d'));
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);

        $this->assertSame(0, $this->scheduledRowCount());
    }

    public function testSyncReminderSchedulesForMultiDaySectionEvent(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, $end);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);

        $this->assertSame(1, $this->scheduledRowCount());
        $row = $this->pdo->query("SELECT run_at FROM scheduled_actions WHERE status = 'pending'")->fetch(\PDO::FETCH_ASSOC);
        $expectedRunAt = (new \DateTimeImmutable($start))->modify('-14 days')->setTime(8, 0);
        $this->assertSame($expectedRunAt->format('Y-m-d H:i:s'), $row['run_at']);
    }

    public function testSyncReminderIgnoresSingleDayEvents(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);

        $this->assertSame(0, $this->scheduledRowCount());
    }

    public function testSyncReminderIgnoresSupplementaryCalendarEvents(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
        $eventId = $this->createEvent($this->supplementaryCalendarId, $start, $end);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);

        $this->assertSame(0, $this->scheduledRowCount());
    }

    public function testSyncReminderIgnoresEventsThatAlreadyStarted(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('-5 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('+2 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, $end);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);

        $this->assertSame(0, $this->scheduledRowCount());
    }

    public function testSyncReminderSchedulesAsapWhenIdealLeadTimeAlreadyPassed(): void
    {
        $this->service->setEnabled(true);
        // Event starts in 3 days but the configured 14-day lead time has
        // already passed — should still remind, just as soon as possible.
        $start = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('+6 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, $end);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);

        $this->assertSame(1, $this->scheduledRowCount());
        $row = $this->pdo->query("SELECT run_at FROM scheduled_actions WHERE status = 'pending'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertGreaterThan((new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['run_at']);
    }

    public function testResavingEventCancelsStaleReminderBeforeReschedule(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, $end);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncReminderForEvent($event);
        $this->service->syncReminderForEvent($event);

        $this->assertSame(1, $this->scheduledRowCount());
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'calendar'")->fetchColumn();
        $this->assertSame(2, $total); // one canceled, one pending
    }

    public function testCancelReminderForEventCancelsPendingRow(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, $end);
        $event = $this->eventRepository->findById($eventId);
        $this->service->syncReminderForEvent($event);

        $this->service->cancelReminderForEvent($eventId);

        $this->assertSame(0, $this->scheduledRowCount());
    }

    public function testSetEnabledResyncsExistingEvents(): void
    {
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
        $this->createEvent($this->sectionCalendarId, $start, $end);

        $this->assertSame(0, $this->scheduledRowCount());

        $this->service->setEnabled(true);

        $this->assertSame(1, $this->scheduledRowCount());
    }

    public function testSetDaysBeforeResyncsExistingEvents(): void
    {
        $this->service->setEnabled(true);
        $start = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable($start))->modify('+3 days')->format('Y-m-d');
        $this->createEvent($this->sectionCalendarId, $start, $end);

        $this->service->setDaysBefore(30);

        $row = $this->pdo->query("SELECT run_at FROM scheduled_actions WHERE status = 'pending'")->fetch(\PDO::FETCH_ASSOC);
        $expectedRunAt = (new \DateTimeImmutable($start))->modify('-30 days')->setTime(8, 0);
        $this->assertSame($expectedRunAt->format('Y-m-d H:i:s'), $row['run_at']);
    }

    public function testSetDaysBeforeRejectsOutOfRangeValues(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->setDaysBefore(0);
    }

    public function testSetDaysBeforeRejectsTooLargeValue(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->setDaysBefore(400);
    }
}
