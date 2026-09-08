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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
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
            $this->encryption->encrypt('Jean', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($totem, 'member_years.totem'),
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $this->encryption->blindIndex($email, 'email') : null,
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
        $calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
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

    /**
     * #236. The handler resolved its recipients with getCurrentYear() —
     * the date-computed year, which also CREATES the new year's empty row.
     * A reminder for an event of late August running after the 1st of
     * September therefore looked for the section's staff in a year whose
     * roster is not imported yet, found nobody, and returned in silence.
     * The staff of that event exist: in the event's own year.
     */
    public function testTheRecipientsAreResolvedInTheEventsOwnYearNotTodays(): void
    {
        // The staff live in the event's year, and nobody lives in the year
        // the clock is in.
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)"
            . " VALUES ('2025-2026', '2025-09-01', '2026-08-31', 0)"
        );
        $eventYearId = (int) $this->pdo->lastInsertId();
        $todayYearId = $this->scoutYearId;
        $this->scoutYearId = $eventYearId;
        $this->createStaffMember('Akela', 'akela@example.test');
        $this->scoutYearId = $todayYearId;

        $calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $calendarId = $calendarRepository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $eventId = $this->createEvent($calendarId, '2026-08-28', '2026-09-02');

        $this->mailService->expects($this->once())
            ->method('send')
            ->with('akela@example.test', $this->stringContains('Camp'));

        $this->schedulerRepository->create(
            'calendar',
            'multiday_event_reminder',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            json_encode(['event_id' => $eventId, 'calendar_id' => $calendarId]),
            'event-' . $eventId
        );

        $this->runner->processOverdue();
    }

    /**
     * #247, the other half of the same handler: the journal line said
     * « Rappel envoyé » before the first send, and counted the people
     * AIMED AT. A run where every send failed left that line and nothing
     * else.
     */
    public function testTheJournalCountsWhatActuallyLeft(): void
    {
        $this->createStaffMember('Akela', 'akela@example.test');
        $calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $calendarId = $calendarRepository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $eventId = $this->createEvent($calendarId, '2026-08-10', '2026-08-13');

        $this->mailService->method('send')->willThrowException(new \Core\Mail\MailException('SMTP connect() failed.'));

        $this->schedulerRepository->create(
            'calendar',
            'multiday_event_reminder',
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            json_encode(['event_id' => $eventId, 'calendar_id' => $calendarId]),
            'event-' . $eventId
        );

        $this->runner->processOverdue();

        $row = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'multiday_event_reminder_sent' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $context = json_decode((string) $row['context'], true);

        $this->assertSame('warning', $row['level']);
        $this->assertSame(0, $context['sent']);
        $this->assertSame(1, $context['failed']);
    }

    // ── a replay reminds nobody twice (issue #246) ────────────────────

    /**
     * The scheduler marks a task done only after handle() returns, so an
     * abrupt stop mid-loop replays the whole send and the section's staff
     * are reminded a second time.
     */
    public function testAReplayOfTheSameReminderSendsNothingASecondTime(): void
    {
        $this->createStaffMember('Akela', 'akela@example.test');
        $eventId = $this->createEvent($this->sectionCalendar(), '2026-08-10', '2026-08-13');

        $this->mailService->expects($this->once())->method('send');

        $payload = ['event_id' => $eventId, 'calendar_id' => $this->sectionCalendar()];
        (new MultidayEventReminderHandler())->handle($payload, $this->taskContext());
        (new MultidayEventReminderHandler())->handle($payload, $this->taskContext());
    }

    /**
     * The scope carries the event's start date on purpose: an event MOVED
     * to another date is a new thing to say, and « l'évènement approche »
     * has to be said again.
     */
    public function testAnEventMovedToAnotherDateIsRemindedAgain(): void
    {
        $this->createStaffMember('Akela', 'akela@example.test');
        $calendarId = $this->sectionCalendar();
        $eventId = $this->createEvent($calendarId, '2026-08-10', '2026-08-13');

        $this->mailService->expects($this->exactly(2))->method('send');

        $payload = ['event_id' => $eventId, 'calendar_id' => $calendarId];
        (new MultidayEventReminderHandler())->handle($payload, $this->taskContext());

        $stmt = $this->pdo->prepare('UPDATE calendar_events SET start_date = ?, end_date = ? WHERE id = ?');
        $stmt->execute(['2026-09-14', '2026-09-17', $eventId]);

        (new MultidayEventReminderHandler())->handle($payload, $this->taskContext());
    }

    public function testTheJournalSaysHowManyAnimatorsTheRunFoundAlreadyReminded(): void
    {
        $this->createStaffMember('Akela', 'akela@example.test');
        $calendarId = $this->sectionCalendar();
        $eventId = $this->createEvent($calendarId, '2026-08-10', '2026-08-13');

        $payload = ['event_id' => $eventId, 'calendar_id' => $calendarId];
        (new MultidayEventReminderHandler())->handle($payload, $this->taskContext());
        (new MultidayEventReminderHandler())->handle($payload, $this->taskContext());

        $row = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'multiday_event_reminder_sent' ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        $context = json_decode((string) $row['context'], true);

        $this->assertSame(0, $context['sent']);
        $this->assertSame(1, $context['skipped']);
    }

    public function testHandleSkipsMembersWithoutEmail(): void
    {
        $this->createStaffMember('Akela', null);
        $calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
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

    /**
     * The section's calendar, created on first use — the handler resolves
     * it by section, so every test that needs one needs the same one.
     */
    private function sectionCalendar(): int
    {
        $existing = $this->pdo->query('SELECT id FROM calendar_calendars ORDER BY id LIMIT 1')->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        return (new CalendarRepository($this->pdo, $this->encryption))
            ->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
    }

    /**
     * The same context the runner hands the handler — built here so a
     * test can call handle() twice in a row, which is exactly what a
     * replay is.
     */
    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->mailService,
            new JournalService($this->journalRepository),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }
}
