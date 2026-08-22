<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxService;
use Modules\TestTools\Task\PurgeCapturedEmailsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The retention task itself: what it deletes, what it journals, and the
 * fact that it always reschedules itself — a purge that threw must not be
 * a purge that stops.
 */
#[Group('database')]
class PurgeCapturedEmailsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private CapturedEmailRepository $repository;
    private EncryptedFileStorageService $fileStorage;
    private SettingService $settings;
    private TaskContext $context;
    private string $storagePath;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);

        $encryption = TestToolsTestHelper::encryption();
        $this->repository = new CapturedEmailRepository($this->pdo, $encryption);

        $this->storagePath = sys_get_temp_dir() . '/purge_captured_' . uniqid();
        mkdir($this->storagePath . '/keys', 0o777, true);

        $this->fileStorage = new EncryptedFileStorageService(
            new FileRepository($this->pdo),
            $encryption,
            $this->storagePath
        );

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            MailSandboxService::SETTING_RETENTION,
            (string) MailSandboxService::DEFAULT_RETENTION,
            'number',
            'Conservation',
            'Réglage de test.',
            MailSandboxService::MODULE_ID
        );
        $this->settings->clearCache();

        // A stub rather than a mock: nothing here verifies interactions
        // with the connection, it only has to hand back the test PDO.
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            new MailService(
                'local',
                'unite@exemple.be',
                'Unité',
                'EX',
                new DkimManager($this->storagePath . '/keys'),
                's1'
            ),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        self::removeDir($this->storagePath);
    }

    private function capture(int $index): int
    {
        return $this->repository->create(
            (new \DateTimeImmutable('2026-03-01 09:00:00'))->modify('+' . $index . ' minutes'),
            sprintf('[25SV] Message %02d', $index),
            'chef@example.be',
            'noreply@example.be',
            null,
            10,
            false,
            $this->fileStorage->store('message ' . $index, 'message/rfc822', 'm.eml', 'test', 'superadmin', 'test_tools'),
            null,
            null,
            null,
            []
        );
    }

    public function testItKeepsExactlyTheConfiguredCountAndDeletesTheOldest(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->capture($i);
        }
        $this->settings->set(MailSandboxService::SETTING_RETENTION, '3', MailSandboxService::MODULE_ID);
        $this->settings->clearCache();

        (new PurgeCapturedEmailsHandler())->handle([], $this->context);

        $this->assertSame(3, $this->repository->countAll());
        $this->assertSame(
            ['[25SV] Message 08', '[25SV] Message 07', '[25SV] Message 06'],
            array_map(static fn($email): string => $email->subject, $this->repository->findPage(10, 0))
        );
    }

    public function testItDeletesTheEncryptedFilesAlongsideTheRows(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->capture($i);
        }
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        $this->settings->set(MailSandboxService::SETTING_RETENTION, '1', MailSandboxService::MODULE_ID);
        $this->settings->clearCache();

        (new PurgeCapturedEmailsHandler())->handle([], $this->context);

        $this->assertSame(1, $this->repository->countAll());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testItJournalsACountAndNeverASubjectOrAnAddress(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->capture($i);
        }
        $this->settings->set(MailSandboxService::SETTING_RETENTION, '1', MailSandboxService::MODULE_ID);
        $this->settings->clearCache();

        (new PurgeCapturedEmailsHandler())->handle([], $this->context);

        $entries = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringContainsString('mail_capture_purged', $entries);
        $this->assertStringNotContainsString('chef@example.be', $entries);
        $this->assertStringNotContainsString('Message 01', $entries);
    }

    public function testItJournalsNothingWhenThereIsNothingToPurge(): void
    {
        $this->capture(1);

        (new PurgeCapturedEmailsHandler())->handle([], $this->context);

        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'mail_capture_purged'")->fetchColumn()
        );
    }

    public function testItAlwaysReschedulesItself(): void
    {
        (new PurgeCapturedEmailsHandler())->handle([], $this->context);

        $next = (new SchedulerRepository($this->pdo))->findByModuleAndKey(
            MailSandboxService::MODULE_ID,
            PurgeCapturedEmailsHandler::TASK_KEY,
            PurgeCapturedEmailsHandler::REFERENCE
        );

        $this->assertNotNull($next);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
