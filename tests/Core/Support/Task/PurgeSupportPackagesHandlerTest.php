<?php

declare(strict_types=1);

namespace Tests\Core\Support\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use Core\Statistics\InstallationIdentityService;
use Core\Support\SupportPackageState;
use Core\Support\Task\GenerateSupportPackageHandler;
use Core\Support\Task\PurgeSupportPackagesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class PurgeSupportPackagesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $projectRoot;
    private string $storagePath;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-purge-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->projectRoot . '/storage';
        mkdir($this->storagePath . '/keys', 0700, true);
        mkdir($this->storagePath . '/config', 0700, true);
        mkdir($this->storagePath . '/temp', 0700, true);
        mkdir($this->projectRoot . '/modules', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $secretManager = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $secretManager->generateMasterKey();
        $secretManager->writeSecrets([]);

        $this->settings->register('support_email', 'support@scoutmagic.be', 'email', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportPackageState::FILE_ID, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportPackageState::GENERATED_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register('statistics_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('base_url', 'https://unite-exemple.be', 'url', 'L', 'D');
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->clearCache();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $connection->method('dumpCredentials')->willReturn([
            'host' => '', 'port' => 0, 'dbName' => '', 'user' => '', 'password' => '',
        ]);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            new MailService('local', 'unite@exemple.be', 'Unité', 'EX', new DkimManager($this->storagePath . '/keys'), 's1'),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduled(string $taskKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_actions WHERE module_id = ? AND task_key = ?');
        $stmt->execute(['core', $taskKey]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testGenerationProducesAnEncryptedSuperadminPackageAndJournalsIt(): void
    {
        (new GenerateSupportPackageHandler())->handle([], $this->context);

        $this->settings->clearCache();
        $fileId = (int) $this->settings->get(SupportPackageState::FILE_ID);
        $this->assertGreaterThan(0, $fileId);

        $record = (new FileRepository($this->pdo))->findById($fileId);
        $this->assertNotNull($record);
        $this->assertTrue($record->encrypted);
        $this->assertSame('superadmin', $record->roleMin);

        $stmt = $this->pdo->query('SELECT event_type FROM event_log');
        $types = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        $this->assertContains('support_package_generated', $types);
    }

    /**
     * `requested_by_user_account_id` (ARCHITECTURE.md §8.16) is what lets a
     * background task tell the person who asked for it that it is done —
     * SchedulerRunner copies the column into the payload, and this handler
     * is one of the few that acts on it. Untested, the notification branch
     * is exactly the kind that quietly stops working.
     */
    public function testTheRequesterIsNotifiedAndNamedOnTheStoredFile(): void
    {
        $notifications = $this->createMock(\Core\Notification\NotificationService::class);
        $notifications->expects($this->once())
            ->method('dispatch')
            ->with(
                'core.support_package_ready',
                [['userAccountId' => 7, 'memberId' => null]],
                $this->callback(static fn(array $data): bool => ($data['url'] ?? null) === '/config/support')
            );

        $context = new TaskContext(
            $this->context->connection,
            $this->context->encryption,
            $this->context->mailService,
            $this->context->journal,
            $this->context->settings,
            $this->context->userAccounts,
            $this->context->storagePath,
            $notifications
        );

        (new GenerateSupportPackageHandler())->handle(['requested_by_user_account_id' => 7], $context);

        $this->settings->clearCache();
        $fileId = (int) $this->settings->get(SupportPackageState::FILE_ID);
        $record = (new FileRepository($this->pdo))->findById($fileId);
        $this->assertNotNull($record);

        $stmt = $this->pdo->prepare('SELECT user_account_id FROM event_log WHERE event_type = ?');
        $stmt->execute(['support_package_generated']);
        $this->assertSame(7, (int) $stmt->fetchColumn());
    }

    /**
     * Nobody to notify is the ordinary case for a task the scheduler picked
     * up on its own; it must not be an error, and must not stop the package
     * being produced.
     */
    public function testAPackageWithNoRequesterNotifiesNobodyAndStillGenerates(): void
    {
        $notifications = $this->createMock(\Core\Notification\NotificationService::class);
        $notifications->expects($this->never())->method('dispatch');

        $context = new TaskContext(
            $this->context->connection,
            $this->context->encryption,
            $this->context->mailService,
            $this->context->journal,
            $this->context->settings,
            $this->context->userAccounts,
            $this->context->storagePath,
            $notifications
        );

        (new GenerateSupportPackageHandler())->handle([], $context);

        $this->settings->clearCache();
        $this->assertGreaterThan(0, (int) $this->settings->get(SupportPackageState::FILE_ID));
    }

    /**
     * Exactly one package is ever kept: generating replaces the previous
     * file *and* its FileRecord (ARCHITECTURE.md §8.48). An archive of
     * phpinfo output and server logs left lying around per generation is
     * the opposite of what its storage rules are for.
     */
    public function testGeneratingASecondPackageReplacesTheFirstEntirely(): void
    {
        (new GenerateSupportPackageHandler())->handle([], $this->context);
        $this->settings->clearCache();
        $first = (int) $this->settings->get(SupportPackageState::FILE_ID);

        (new GenerateSupportPackageHandler())->handle([], $this->context);
        $this->settings->clearCache();
        $second = (int) $this->settings->get(SupportPackageState::FILE_ID);

        $this->assertNotSame($first, $second);
        $this->assertNull((new FileRepository($this->pdo))->findById($first));
        $this->assertNotNull((new FileRepository($this->pdo))->findById($second));
    }

    public function testThePurgeReschedulesItselfDaily(): void
    {
        (new PurgeSupportPackagesHandler())->handle([], $this->context);

        $scheduled = $this->scheduled(PurgeSupportPackagesHandler::TASK_KEY);
        $this->assertCount(1, $scheduled);
        $this->assertSame(PurgeSupportPackagesHandler::REFERENCE, $scheduled[0]['reference']);
    }

    public function testAnExpiredPackageIsRemovedAndJournaled(): void
    {
        (new GenerateSupportPackageHandler())->handle([], $this->context);
        $this->settings->clearCache();
        $fileId = (int) $this->settings->get(SupportPackageState::FILE_ID);

        $this->settingRepository->updateValue(
            null,
            SupportPackageState::GENERATED_AT,
            (new \DateTimeImmutable('-8 days'))->format(\DateTimeInterface::ATOM)
        );
        $this->settings->clearCache();

        (new PurgeSupportPackagesHandler())->handle([], $this->context);

        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));
        $stmt = $this->pdo->query('SELECT event_type FROM event_log');
        $types = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        $this->assertContains('support_package_purged', $types);
    }

    public function testAFreshPackageSurvivesThePurge(): void
    {
        (new GenerateSupportPackageHandler())->handle([], $this->context);
        $this->settings->clearCache();
        $fileId = (int) $this->settings->get(SupportPackageState::FILE_ID);

        (new PurgeSupportPackagesHandler())->handle([], $this->context);

        $this->assertNotNull((new FileRepository($this->pdo))->findById($fileId));
    }
}
