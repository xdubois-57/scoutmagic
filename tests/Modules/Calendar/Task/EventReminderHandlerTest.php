<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Task\EventReminderHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class EventReminderHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRunner $runner;
    private SchedulerRepository $schedulerRepository;
    private NotificationRepository $notificationRepository;
    private UserAccountRepository $userAccountRepository;
    private int $sectionCalendarId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->notificationRepository = new NotificationRepository($this->pdo, $encryption);
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $encryption);

        $notificationService = new NotificationService(
            $this->notificationRepository,
            new PushSubscriptionRepository($this->pdo, $encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $settingService,
            $journalService,
            new SchedulerService($this->schedulerRepository),
            $this->userAccountRepository
        );
        $notificationService->registerModuleTypes('calendar', [[
            'id' => 'calendar.event_reminder', 'label' => 'Rappel', 'description' => 'd',
            'group' => 'Calendrier', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);

        $this->runner = new SchedulerRunner($this->schedulerRepository, $journalService);
        $this->runner->registerHandler('calendar', 'event_reminder', new EventReminderHandler());
        $this->runner->setTaskContext(new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            $journalService,
            $settingService,
            $this->userAccountRepository,
            sys_get_temp_dir(),
            $notificationService
        ));

        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['ECL', 'Éclaireurs', 30]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['ECL01', $branchId, 'Éclaireurs']);
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->sectionCalendarId = (new CalendarRepository($this->pdo))->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);
    }

    private function createEvent(string $startDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_events (calendar_id, title, start_date, sequence) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$this->sectionCalendarId, 'Camp', $startDate]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testHandleDispatchesNotificationToEveryAccount(): void
    {
        $accountId = $this->userAccountRepository->create('member@example.test')->id;
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->schedulerRepository->create('calendar', 'event_reminder', $pastTime, json_encode(['event_id' => $eventId]), 'event-reminder-' . $eventId);

        $this->runner->processOverdue();

        $notifications = $this->notificationRepository->findByUserAccountId($accountId);
        $this->assertCount(1, $notifications);
        $this->assertSame('calendar.event_reminder', $notifications[0]->typeId);
        $this->assertStringContainsString('Camp', $notifications[0]->body);

        $stmt = $this->pdo->prepare('SELECT status FROM scheduled_actions WHERE reference = ?');
        $stmt->execute(['event-reminder-' . $eventId]);
        $this->assertSame('done', $stmt->fetchColumn());
    }

    public function testHandleNoOpsWhenEventWasDeletedSinceScheduling(): void
    {
        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->schedulerRepository->create('calendar', 'event_reminder', $pastTime, json_encode(['event_id' => 999999]), 'event-reminder-999999');

        $this->runner->processOverdue();

        $stmt = $this->pdo->prepare('SELECT status FROM scheduled_actions WHERE reference = ?');
        $stmt->execute(['event-reminder-999999']);
        $this->assertSame('done', $stmt->fetchColumn());
    }
}
