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
use Modules\Registration\Task\CloseRegistrationHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Mirrors Tests\Modules\Registration\Task\OpenRegistrationHandlerTest — see
 * its own docblock for the recurring MM-DD / applied-on-marker rationale.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CloseRegistrationHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('registration_form_open', '1', 'boolean', 'Ouvert', 'desc', 'registration');
        $this->settingService->register('registration_scheduled_close_at', '', 'text', 'Fermeture', 'desc', 'registration');
        $this->settingService->register('registration_scheduled_close_applied_on', '', 'text', 'Appliqué', 'desc', 'registration');

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

    public function testClosesWhenTodayMatchesTheScheduledMonthDay(): void
    {
        $todayMonthDay = (new \DateTimeImmutable())->format('m-d');
        $this->settingService->set('registration_scheduled_close_at', $todayMonthDay, 'registration');

        (new CloseRegistrationHandler())->handle([], $this->context);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testNeverFiresForAFutureMonthDay(): void
    {
        $future = (new \DateTimeImmutable('+10 days'))->format('m-d');
        $this->settingService->set('registration_scheduled_close_at', $future, 'registration');

        (new CloseRegistrationHandler())->handle([], $this->context);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testNeverCatchesUpAMonthDayThatAlreadyPassedThisYear(): void
    {
        $past = (new \DateTimeImmutable('-10 days'))->format('m-d');
        $this->settingService->set('registration_scheduled_close_at', $past, 'registration');

        (new CloseRegistrationHandler())->handle([], $this->context);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testDoesNotReopenTheSameDayAfterAManualReopen(): void
    {
        $todayMonthDay = (new \DateTimeImmutable())->format('m-d');
        $this->settingService->set('registration_scheduled_close_at', $todayMonthDay, 'registration');

        (new CloseRegistrationHandler())->handle([], $this->context);
        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));

        // A chief manually reopens it again the same day — a second poll
        // later that same day must never silently close it again.
        $this->settingService->set('registration_form_open', '1', 'registration');

        (new CloseRegistrationHandler())->handle([], $this->context);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testEmptyScheduleNeverFires(): void
    {
        (new CloseRegistrationHandler())->handle([], $this->context);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testFiringLogsAJournalEntry(): void
    {
        $todayMonthDay = (new \DateTimeImmutable())->format('m-d');
        $this->settingService->set('registration_scheduled_close_at', $todayMonthDay, 'registration');

        (new CloseRegistrationHandler())->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_form_auto_closed'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testReschedulesItselfHourly(): void
    {
        (new CloseRegistrationHandler())->handle([], $this->context);

        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $scheduled = $schedulerService->find('registration', 'close_registration', 'poll');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }
}
