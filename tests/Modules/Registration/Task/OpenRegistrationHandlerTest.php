<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

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
use Modules\Registration\Task\OpenRegistrationHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Recurring (never one-shot) scheduled open: `registration_scheduled_open_at`
 * is a bare "MM-DD" — no year — so the same configuration fires every scout
 * year without being re-entered. Fires exactly once per real calendar day
 * the day's own m-d matches, tracked via `registration_scheduled_open_applied_on`
 * (a real Y-m-d), never retroactively for a date that already passed earlier
 * this year (cron-like: waits for the next real occurrence).
 *
 * @group database
 */
class OpenRegistrationHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('registration_form_open', '0', 'boolean', 'Ouvert', 'desc', 'registration');
        $this->settingService->register('registration_scheduled_open_at', '', 'text', 'Ouverture', 'desc', 'registration');
        $this->settingService->register('registration_scheduled_open_applied_on', '', 'text', 'Appliqué', 'desc', 'registration');

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settingService,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testOpensWhenTodayMatchesTheScheduledMonthDay(): void
    {
        $todayMonthDay = (new \DateTimeImmutable())->format('m-d');
        $this->settingService->set('registration_scheduled_open_at', $todayMonthDay, 'registration');

        (new OpenRegistrationHandler())->handle([], $this->context);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testNeverFiresForAFutureMonthDay(): void
    {
        $future = (new \DateTimeImmutable('+10 days'))->format('m-d');
        $this->settingService->set('registration_scheduled_open_at', $future, 'registration');

        (new OpenRegistrationHandler())->handle([], $this->context);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testNeverCatchesUpAMonthDayThatAlreadyPassedThisYear(): void
    {
        // A date already behind us this year must wait for NEXT year's
        // occurrence, never fire retroactively the moment it's configured
        // — the defining difference from the old one-shot due-datetime design.
        $past = (new \DateTimeImmutable('-10 days'))->format('m-d');
        $this->settingService->set('registration_scheduled_open_at', $past, 'registration');

        (new OpenRegistrationHandler())->handle([], $this->context);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testDoesNotReopenTwiceTheSameDayAfterAManualClose(): void
    {
        $todayMonthDay = (new \DateTimeImmutable())->format('m-d');
        $this->settingService->set('registration_scheduled_open_at', $todayMonthDay, 'registration');

        (new OpenRegistrationHandler())->handle([], $this->context);
        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));

        // A chief manually closes it again the same day (e.g. via the
        // config page's own toggle) — a second poll later that same day
        // must never silently reopen it.
        $this->settingService->set('registration_form_open', '0', 'registration');

        (new OpenRegistrationHandler())->handle([], $this->context);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testEmptyScheduleNeverFires(): void
    {
        (new OpenRegistrationHandler())->handle([], $this->context);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testFiringLogsAJournalEntry(): void
    {
        $todayMonthDay = (new \DateTimeImmutable())->format('m-d');
        $this->settingService->set('registration_scheduled_open_at', $todayMonthDay, 'registration');

        (new OpenRegistrationHandler())->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_form_auto_opened'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testReschedulesItselfHourly(): void
    {
        (new OpenRegistrationHandler())->handle([], $this->context);

        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $scheduled = $schedulerService->find('registration', 'open_registration', 'poll');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }
}
