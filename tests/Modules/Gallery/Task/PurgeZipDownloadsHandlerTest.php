<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Task;

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
use Modules\Gallery\Repository\ZipDownloadRepository;
use Modules\Gallery\Task\PurgeZipDownloadsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * The daily sweep of gallery_zip_downloads, and the self-rescheduling that
 * keeps it running — same shape as Tests\Modules\Groups\Task\
 * PurgeRateLimitHandlerTest.
 */
#[Group('database')]
class PurgeZipDownloadsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private ZipDownloadRepository $repository;
    private SchedulerService $schedulerService;
    private TaskContext $context;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('enc', 'idx')");
        $this->accountId = (int) $this->pdo->lastInsertId();

        $this->repository = new ZipDownloadRepository($this->pdo);
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
        $this->repository->record($this->accountId, '2020-01-01 00:00:00');
        $this->repository->record($this->accountId, AppClock::now()->modify('-3 hours')->format('Y-m-d H:i:s'));
        $this->repository->record($this->accountId, AppClock::now()->modify('-50 minutes')->format('Y-m-d H:i:s'));

        (new PurgeZipDownloadsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->repository->countSince($this->accountId, '2000-01-01 00:00:00'));
    }

    public function testReschedulesItselfForTheNextDay(): void
    {
        (new PurgeZipDownloadsHandler())->handle([], $this->context);

        $scheduled = $this->schedulerService->find('gallery', PurgeZipDownloadsHandler::TASK_KEY, 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testDoesNotDoubleScheduleWhenAFutureRunIsAlreadyPending(): void
    {
        $this->schedulerService->schedule(
            'gallery', PurgeZipDownloadsHandler::TASK_KEY, new \DateTimeImmutable('+1 day'), [], 'daily'
        );

        (new PurgeZipDownloadsHandler())->handle([], $this->context);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE module_id = ? AND task_key = ?');
        $stmt->execute(['gallery', PurgeZipDownloadsHandler::TASK_KEY]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
