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
#[\PHPUnit\Framework\Attributes\Group('database')]
class EventReminderHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRunner $runner;
    private SchedulerRepository $schedulerRepository;
    private NotificationRepository $notificationRepository;
    private UserAccountRepository $userAccountRepository;
    private int $sectionCalendarId;
    private int $sectionId;
    private int $otherSectionId;
    private int $scoutYearId;
    private int $animeFunctionId;
    private int $adminFunctionId;
    private int $chiefFunctionId;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $this->encryption = $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
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
            $this->createStub(WebPush::class),
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
            $this->createStub(MailService::class),
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
        $this->sectionId = $sectionId = (int) $this->pdo->lastInsertId();
        $stmt->execute(['SEE', $branchId, 'Seeonee']);
        $this->otherSectionId = (int) $this->pdo->lastInsertId();
        $this->sectionCalendarId = (new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);

        // The year the event of these tests falls in, and the three functions
        // the targeting tells apart — the real role queries read them.
        $label = \Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable('+1 day'));
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute([$label, substr($label, 0, 4) . '-09-01', substr($label, 5, 4) . '-08-31']);
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        foreach (['animeFunctionId' => ['ANI', 'identified'], 'chiefFunctionId' => ['CHE', 'chief'], 'adminFunctionId' => ['CU', 'admin']] as $property => [$code, $role]) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
                ->execute([$code, $code, $role]);
            $this->{$property} = (int) $this->pdo->lastInsertId();
        }
    }

    /** $email's account, linked to $section through a member holding $function in it. */
    private function linkedAccount(string $email, int $section, ?int $function = null): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['D' . uniqid()]);
        $member = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
             email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $member, $this->scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);
        $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)')
            ->execute([(int) $this->pdo->lastInsertId(), $function ?? $this->animeFunctionId, $section]);

        $existing = $this->userAccountRepository->findByEmail($email);

        return $existing?->id ?? $this->userAccountRepository->create($email)->id;
    }

    private function remind(int $eventId): void
    {
        $this->schedulerRepository->create(
            'calendar',
            'event_reminder',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            json_encode(['event_id' => $eventId]),
            'event-reminder-' . $eventId
        );
        $this->runner->processOverdue();
    }

    /** @return list<array{typeId: string, body: string}> */
    private function remindersOf(int $accountId): array
    {
        return array_map(
            static fn($n): array => ['typeId' => $n->typeId, 'body' => $n->body],
            $this->notificationRepository->findByUserAccountId($accountId)
        );
    }

    private function createEvent(string $startDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_events (calendar_id, title, start_date, sequence) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$this->sectionCalendarId, 'Camp', $startDate]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testHandleDispatchesTheReminderToTheAccountsLinkedToTheSection(): void
    {
        $accountId = $this->linkedAccount('member@example.test', $this->sectionId);
        $outsider = $this->linkedAccount('outsider@example.test', $this->otherSectionId);
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $this->remind($eventId);

        $notifications = $this->notificationRepository->findByUserAccountId($accountId);
        $this->assertCount(1, $notifications);
        $this->assertSame('calendar.event_reminder', $notifications[0]->typeId);
        $this->assertStringContainsString('Camp', $notifications[0]->body);
        // The event title alone is ambiguous — the body must name which
        // calendar (here, the section) the reminder is about.
        $this->assertStringContainsString('Éclaireurs', $notifications[0]->body);
        $this->assertSame([], $this->remindersOf($outsider), 'another section is not told');

        $stmt = $this->pdo->prepare('SELECT status FROM scheduled_actions WHERE reference = ?');
        $stmt->execute(['event-reminder-' . $eventId]);
        $this->assertSame('done', $stmt->fetchColumn());
    }

    /**
     * Issue #1348, the report: an administrator, parent of children in two
     * sections, received « Rappel d'activité » for a weekend of a third.
     * Their role is not what subscribes them.
     */
    public function testAnAdministratorParentGetsNoReminderForASectionOfNoneOfTheirChildren(): void
    {
        $admin = $this->linkedAccount('admin-parent@example.test', $this->otherSectionId);
        $this->linkedAccount('admin-parent@example.test', $this->otherSectionId, $this->adminFunctionId);
        $super = $this->userAccountRepository->create('super@example.test')->id;
        $this->pdo->prepare('UPDATE user_accounts SET is_super_admin = 1 WHERE id = ?')->execute([$super]);
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $this->remind($eventId);

        $this->assertSame([], $this->remindersOf($admin));
        $this->assertSame([], $this->remindersOf($super), 'a super-administrator is not subscribed by their role either');
    }

    /** An account with several children and functions in the section: one reminder. */
    public function testSeveralLinksToTheSameSectionGiveOneReminder(): void
    {
        $parent = $this->linkedAccount('parent@example.test', $this->sectionId);
        $this->linkedAccount('parent@example.test', $this->sectionId);
        $this->linkedAccount('parent@example.test', $this->sectionId, $this->chiefFunctionId);
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $this->remind($eventId);

        $this->assertCount(1, $this->remindersOf($parent));
    }

    /**
     * The calendar is reserved to the chefs: the title of the activity —
     * which the reminder carries — must not reach the parents of the section.
     */
    public function testAChiefOnlyCalendarsReminderNeverReachesTheParents(): void
    {
        $this->pdo->prepare('UPDATE calendar_calendars SET visibility = ? WHERE id = ?')
            ->execute([Calendar::VISIBILITY_CHIEF, $this->sectionCalendarId]);
        $parent = $this->linkedAccount('parent@example.test', $this->sectionId);
        $chief = $this->linkedAccount('chief@example.test', $this->sectionId, $this->chiefFunctionId);
        $foreignChief = $this->linkedAccount('foreign@example.test', $this->otherSectionId, $this->chiefFunctionId);
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $this->remind($eventId);

        $this->assertCount(1, $this->remindersOf($chief));
        $this->assertSame([], $this->remindersOf($parent));
        $this->assertSame([], $this->remindersOf($foreignChief), 'a chef of another section is not linked to this one');
    }

    /**
     * Rights are resolved when the reminder SENDS, not when it was scheduled,
     * weeks earlier perhaps: a calendar tightened, or a child who left the
     * section, since then.
     */
    public function testWhatChangedBeforeSendingIsTakenIntoAccount(): void
    {
        $stayed = $this->linkedAccount('stayed@example.test', $this->sectionId);
        $left = $this->linkedAccount('left@example.test', $this->sectionId);
        $this->pdo->prepare('DELETE FROM member_functions WHERE member_year_id IN
            (SELECT id FROM member_years WHERE email_blind_index = ?)')
            ->execute([$this->encryption->blindIndex('left@example.test', 'email')]);
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $this->remind($eventId);

        $this->assertCount(1, $this->remindersOf($stayed));
        $this->assertSame([], $this->remindersOf($left));
    }

    /** A supplementary calendar keeps the visibility rule of « Mes évènements ». */
    public function testASupplementaryCalendarsReminderFollowsItsVisibility(): void
    {
        $repository = new CalendarRepository($this->pdo, $this->encryption);
        $animateurs = $repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_CHIEF, 'tok-a');
        $parent = $this->linkedAccount('parent@example.test', $this->sectionId);
        $chief = $this->linkedAccount('chief@example.test', $this->otherSectionId, $this->chiefFunctionId);
        $this->pdo->prepare('INSERT INTO calendar_events (calendar_id, title, start_date, sequence) VALUES (?, ?, ?, 0)')
            ->execute([$animateurs, 'Conseil', (new \DateTimeImmutable('+1 day'))->format('Y-m-d')]);

        $this->remind((int) $this->pdo->lastInsertId());

        $this->assertSame([], $this->remindersOf($parent), 'the title of a staff meeting is not for parents');
        $this->assertCount(1, $this->remindersOf($chief));
    }

    public function testNoLinkedAccountSendsNothingAndTheTaskIsDone(): void
    {
        $eventId = $this->createEvent((new \DateTimeImmutable('+1 day'))->format('Y-m-d'));

        $this->remind($eventId);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
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
