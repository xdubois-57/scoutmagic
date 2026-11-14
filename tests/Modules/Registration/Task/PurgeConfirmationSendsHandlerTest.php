<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Config\AppClock;
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
use Modules\Registration\Repository\ConfirmationSendRepository;
use Modules\Registration\Task\PurgeConfirmationSendsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The daily sweep of registration_confirmation_sends, and the
 * self-rescheduling that keeps it running — same shape as Tests\Modules\
 * Gallery\Task\PurgeZipDownloadsHandlerTest.
 */
#[Group('database')]
class PurgeConfirmationSendsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private ConfirmationSendRepository $repository;
    private SchedulerService $schedulerService;
    private TaskContext $context;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->requestId = RegistrationTestHelper::insertRequest($this->pdo, $yearId, 'a@example.com');

        $this->repository = new ConfirmationSendRepository($this->pdo);
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testDeletesRowsPastTheRetentionAndKeepsTheOnesTheLimitStillCounts(): void
    {
        $this->repository->record($this->requestId, '2020-01-01 00:00:00');
        $this->repository->record($this->requestId, AppClock::now()->modify('-3 hours')->format('Y-m-d H:i:s'));
        $this->repository->record($this->requestId, AppClock::now()->modify('-50 minutes')->format('Y-m-d H:i:s'));

        (new PurgeConfirmationSendsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->repository->countSince($this->requestId, '2000-01-01 00:00:00'));
    }

    /** Past the hour but within the retention: kept, never purged early. */
    public function testKeepsARowJustPastTheWindow(): void
    {
        $this->repository->record($this->requestId, AppClock::now()->modify('-90 minutes')->format('Y-m-d H:i:s'));

        (new PurgeConfirmationSendsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->repository->countSince($this->requestId, '2000-01-01 00:00:00'));
    }

    public function testReschedulesItselfForTheNextDay(): void
    {
        (new PurgeConfirmationSendsHandler())->handle([], $this->context);

        $scheduled = $this->schedulerService->find('registration', PurgeConfirmationSendsHandler::TASK_KEY, 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testDoesNotDoubleScheduleWhenAFutureRunIsAlreadyPending(): void
    {
        $this->schedulerService->schedule(
            'registration', PurgeConfirmationSendsHandler::TASK_KEY, new \DateTimeImmutable('+1 day'), [], 'daily'
        );

        (new PurgeConfirmationSendsHandler())->handle([], $this->context);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE module_id = ? AND task_key = ?');
        $stmt->execute(['registration', PurgeConfirmationSendsHandler::TASK_KEY]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /** The rows go with their request. */
    public function testTheRowsGoWithTheirRequest(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->repository->record($this->requestId, AppClock::now()->format('Y-m-d H:i:s'));

        $this->pdo->prepare('DELETE FROM registration_requests WHERE id = ?')->execute([$this->requestId]);

        $this->assertSame(0, $this->repository->countSince($this->requestId, '2000-01-01 00:00:00'));
    }
}
