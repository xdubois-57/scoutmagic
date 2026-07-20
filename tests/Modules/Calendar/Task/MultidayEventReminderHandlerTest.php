<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Task\MultidayEventReminderHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class MultidayEventReminderHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SchedulerRunner $runner;
    private SchedulerRepository $schedulerRepository;
    private JournalRepository $journalRepository;
    private EncryptionService $encryption;
    private MailService $mailService;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $this->journalRepository = new JournalRepository($this->pdo);
        $journalService = new JournalService($this->journalRepository);

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->runner = new SchedulerRunner($this->schedulerRepository, $journalService);
        $this->runner->registerHandler('calendar', 'multiday_event_reminder', new MultidayEventReminderHandler());

        $this->mailService = $this->createMock(MailService::class);
        $userAccounts = new UserAccountRepository($this->pdo, $this->encryption);

        $this->runner->setTaskContext(new TaskContext(
            $connection,
            $this->encryption,
            $this->mailService,
            $journalService,
            $settingService,
            $userAccounts,
            sys_get_temp_dir()
        ));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['ECL', 'Éclaireurs', 30]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['ECL01', $branchId, 'Éclaireurs']);
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function createStaffMember(string $totem, ?string $email): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Jean'),
            $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($totem),
            $email !== null ? $this->encryption->encrypt($email) : null,
            $email !== null ? $this->encryption->blindIndex($email) : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES ('CHEF', 'Chef', 'chief', 1)");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'CHEF'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId]);
    }

    private function createEvent(int $calendarId, string $startDate, string $endDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_events (calendar_id, title, start_date, end_date, sequence) VALUES (?, ?, ?, ?, 0)'
        );
        $stmt->execute([$calendarId, 'Camp', $startDate, $endDate]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testHandleSendsReminderToSectionStaffWithEmail(): void
    {
        $this->createStaffMember('Akela', 'akela@example.test');
        $calendarRepository = new CalendarRepository($this->pdo);
        $calendarId = $calendarRepository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $eventId = $this->createEvent($calendarId, '2026-08-10', '2026-08-13');

        $this->mailService->expects($this->once())
            ->method('send')
            ->with('akela@example.test', $this->stringContains('Camp'));

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->schedulerRepository->create(
            'calendar',
            'multiday_event_reminder',
            $pastTime,
            json_encode(['event_id' => $eventId, 'calendar_id' => $calendarId]),
            'event-' . $eventId
        );

        $this->runner->processOverdue();

        $stmt = $this->pdo->prepare('SELECT status FROM scheduled_actions WHERE reference = ?');
        $stmt->execute(['event-' . $eventId]);
        $this->assertSame('done', $stmt->fetchColumn());
    }

    public function testHandleSkipsMembersWithoutEmail(): void
    {
        $this->createStaffMember('Akela', null);
        $calendarRepository = new CalendarRepository($this->pdo);
        $calendarId = $calendarRepository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $eventId = $this->createEvent($calendarId, '2026-08-10', '2026-08-13');

        $this->mailService->expects($this->never())->method('send');

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->schedulerRepository->create(
            'calendar',
            'multiday_event_reminder',
            $pastTime,
            json_encode(['event_id' => $eventId, 'calendar_id' => $calendarId]),
            'event-' . $eventId
        );

        $this->runner->processOverdue();
    }

    public function testHandleNoOpsWhenEventWasDeletedSinceScheduling(): void
    {
        $this->mailService->expects($this->never())->method('send');

        $pastTime = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $this->schedulerRepository->create(
            'calendar',
            'multiday_event_reminder',
            $pastTime,
            json_encode(['event_id' => 999999, 'calendar_id' => 1]),
            'event-999999'
        );

        $this->runner->processOverdue();

        $stmt = $this->pdo->prepare('SELECT status FROM scheduled_actions WHERE reference = ?');
        $stmt->execute(['event-999999']);
        $this->assertSame('done', $stmt->fetchColumn());
    }
}
