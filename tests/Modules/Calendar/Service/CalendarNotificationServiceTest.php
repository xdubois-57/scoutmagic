<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
class CalendarNotificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private CalendarNotificationService $service;
    private CalendarNotificationService $serviceWithNotifications;
    private NotificationService $notificationService;
    private NotificationRepository $notificationRepository;
    private UserAccountRepository $userAccountRepository;
    private CalendarEventRepository $eventRepository;
    private CalendarRepository $calendarRepository;
    private SchedulerRepository $schedulerRepository;
    private SettingService $settingService;
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

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('notify_multiday_events_enabled', '0', 'boolean', 'Rappels', 'desc', 'calendar');
        $this->settingService->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');
        $this->settingService->register('event_reminder_hour', '18:00', 'text', 'Heure du rappel', 'desc', 'calendar');

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->service = new CalendarNotificationService(
            new SchedulerService($this->schedulerRepository),
            $this->settingService,
            $calendarService,
            $this->eventRepository
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->notificationRepository = new NotificationRepository($this->pdo, $encryption);
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $this->notificationService = new NotificationService(
            $this->notificationRepository,
            new PushSubscriptionRepository($this->pdo, $encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService($this->schedulerRepository),
            $this->userAccountRepository
        );
        $this->notificationService->registerModuleTypes('calendar', [
            [
                'id' => 'calendar.event_published', 'label' => 'Nouvelle activité', 'description' => 'd',
                'group' => 'Calendrier', 'role_min' => 'identified',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
            [
                'id' => 'calendar.event_changed', 'label' => 'Activité modifiée', 'description' => 'd',
                'group' => 'Calendrier', 'role_min' => 'identified',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
            [
                'id' => 'calendar.event_reminder', 'label' => 'Rappel', 'description' => 'd',
                'group' => 'Calendrier', 'role_min' => 'identified',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
        ]);
        $this->serviceWithNotifications = new CalendarNotificationService(
            new SchedulerService($this->schedulerRepository),
            $this->settingService,
            $calendarService,
            $this->eventRepository,
            $this->notificationService,
            $this->userAccountRepository
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

    // --- Notification-centre / push dispatch ---

    public function testDispatchEventPublishedCreatesNotificationForEveryAccountExceptTheActor(): void
    {
        $actor = $this->userAccountRepository->create('actor@test.com')->id;
        $other = $this->userAccountRepository->create('other@test.com')->id;
        $eventId = $this->createEvent($this->sectionCalendarId, (new \DateTimeImmutable('+5 days'))->format('Y-m-d'), null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->dispatchEventPublished($event, $actor);

        $actorNotifications = $this->notificationRepository->findByUserAccountId($actor);
        $otherNotifications = $this->notificationRepository->findByUserAccountId($other);
        $this->assertCount(1, $actorNotifications);
        $this->assertCount(1, $otherNotifications);
        $this->assertSame('calendar.event_published', $otherNotifications[0]->typeId);
        // The event title alone is ambiguous (many sections reuse the same
        // generic titles) — the body must name which calendar it's about.
        $this->assertSame('Éclaireurs — Camp', $otherNotifications[0]->body);
    }

    public function testDispatchEventPublishedNamesASupplementaryCalendarByItsOwnName(): void
    {
        $other = $this->userAccountRepository->create('other3@test.com')->id;
        $eventId = $this->createEvent($this->supplementaryCalendarId, (new \DateTimeImmutable('+5 days'))->format('Y-m-d'), null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->dispatchEventPublished($event, null);

        $notifications = $this->notificationRepository->findByUserAccountId($other);
        $this->assertSame('Animateurs — Camp', $notifications[0]->body);
    }

    public function testDispatchEventChangedUsesItsOwnType(): void
    {
        $other = $this->userAccountRepository->create('other2@test.com')->id;
        $eventId = $this->createEvent($this->sectionCalendarId, (new \DateTimeImmutable('+5 days'))->format('Y-m-d'), null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->dispatchEventChanged($event, null);

        $notifications = $this->notificationRepository->findByUserAccountId($other);
        $this->assertCount(1, $notifications);
        $this->assertSame('calendar.event_changed', $notifications[0]->typeId);
    }

    public function testDispatchIsANoOpWithoutNotificationDependencies(): void
    {
        $this->userAccountRepository->create('solo@test.com');
        $eventId = $this->createEvent($this->sectionCalendarId, (new \DateTimeImmutable('+5 days'))->format('Y-m-d'), null);
        $event = $this->eventRepository->findById($eventId);

        // $this->service has no NotificationService/UserAccountRepository —
        // must degrade to a no-op rather than throw.
        $this->service->dispatchEventPublished($event, null);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    // --- Activity reminder (day-before, calendar.event_reminder) ---

    private function reminderRowCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'calendar' AND task_key = 'event_reminder' AND status = 'pending'");
        return (int) $stmt->fetchColumn();
    }

    public function testSyncActivityReminderSchedulesForDayBeforeAtConfiguredHour(): void
    {
        $start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->syncActivityReminderForEvent($event);

        $this->assertSame(1, $this->reminderRowCount());
        $row = $this->pdo->query("SELECT run_at FROM scheduled_actions WHERE task_key = 'event_reminder' AND status = 'pending'")->fetch(\PDO::FETCH_ASSOC);
        $expected = (new \DateTimeImmutable($start))->modify('-1 day')->setTime(18, 0);
        $this->assertSame($expected->format('Y-m-d H:i:s'), $row['run_at']);
    }

    public function testSyncActivityReminderSkipsWhenReminderInstantAlreadyPassed(): void
    {
        // An event starting today reminds "the day before, at the
        // configured hour" — i.e. yesterday, which is always already
        // passed relative to "now" regardless of what time of day this
        // test itself runs (a previous "+12 hours" formulation was
        // time-of-day-dependent: whether it landed on today's or
        // tomorrow's calendar date, and therefore whether the reminder
        // instant had actually passed yet, depended on the wall-clock
        // hour the test happened to run at).
        $start = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->syncActivityReminderForEvent($event);

        $this->assertSame(0, $this->reminderRowCount());
    }

    public function testSyncActivityReminderIsANoOpWithoutNotificationService(): void
    {
        $start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);

        $this->service->syncActivityReminderForEvent($event);

        $this->assertSame(0, $this->reminderRowCount());
    }

    public function testCancelActivityReminderCancelsPendingRow(): void
    {
        $start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);
        $this->serviceWithNotifications->syncActivityReminderForEvent($event);

        $this->serviceWithNotifications->cancelActivityReminderForEvent($eventId);

        $this->assertSame(0, $this->reminderRowCount());
    }

    public function testResyncingActivityReminderReplacesThePreviousOne(): void
    {
        $start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->syncActivityReminderForEvent($event);
        $this->serviceWithNotifications->syncActivityReminderForEvent($event);

        $this->assertSame(1, $this->reminderRowCount());
    }

    public function testActivityReminderHourSettingIsRespected(): void
    {
        $this->settingService->set('event_reminder_hour', '09:30', 'calendar');
        $start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $eventId = $this->createEvent($this->sectionCalendarId, $start, null);
        $event = $this->eventRepository->findById($eventId);

        $this->serviceWithNotifications->syncActivityReminderForEvent($event);

        $row = $this->pdo->query("SELECT run_at FROM scheduled_actions WHERE task_key = 'event_reminder' AND status = 'pending'")->fetch(\PDO::FETCH_ASSOC);
        $expected = (new \DateTimeImmutable($start))->modify('-1 day')->setTime(9, 30);
        $this->assertSame($expected->format('Y-m-d H:i:s'), $row['run_at']);
    }
}
