<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\TwigFactory;
use Modules\TestTools\Controller\MailSandboxController;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxService;
use Modules\TestTools\Task\PurgeCapturedEmailsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Retention and the danger zone (ARCHITECTURE.md §8.63): the sandbox keeps
 * exactly what it says it keeps, deletes rows and files together, and
 * cannot be emptied without the exact word.
 */
#[Group('database')]
class RetentionTest extends TestCase
{
    private \PDO $pdo;
    private CapturedEmailRepository $repository;
    private MailSandboxService $sandboxService;
    private SettingService $settingService;
    private EncryptedFileStorageService $fileStorage;
    private Environment $twig;
    private string $tempDir;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);

        $encryption = TestToolsTestHelper::encryption();
        $this->repository = new CapturedEmailRepository($this->pdo, $encryption);

        $this->tempDir = sys_get_temp_dir() . '/test_tools_retention_' . uniqid();
        mkdir($this->tempDir, 0700, true);

        $this->fileStorage = new EncryptedFileStorageService(
            new FileRepository($this->pdo),
            $encryption,
            $this->tempDir
        );

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            MailSandboxService::SETTING_ARMED,
            '0',
            'boolean',
            'Capture',
            'Réglage de test.',
            MailSandboxService::MODULE_ID,
            null,
            null,
            false
        );
        $this->settingService->register(
            MailSandboxService::SETTING_RETENTION,
            (string) MailSandboxService::DEFAULT_RETENTION,
            'number',
            'Conservation',
            'Réglage de test.',
            MailSandboxService::MODULE_ID
        );

        $this->sandboxService = new MailSandboxService(
            $this->repository,
            $this->settingService,
            $this->fileStorage,
            new JournalService(new JournalRepository($this->pdo))
        );

        $root = dirname(__DIR__, 3);
        $this->twig = TwigFactory::create(
            $root . '/core/View/templates',
            false,
            ['test_tools' => $root . '/modules/test_tools/views']
        );
        foreach ([
            'site_name' => 'Test Unit', 'is_authenticated' => true, 'current_user_role' => 'superadmin',
            'config_mode' => false, 'cookie_consent_given' => true, 'menus' => null,
            'current_path' => '/test-tools/mail-sandbox', 'csp_nonce' => 'test-nonce',
        ] as $key => $value) {
            $this->twig->addGlobal($key, $value);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        self::removeDir($this->tempDir);
    }

    /**
     * Each capture gets its own message file and its own attachment file,
     * so "the purge removed every associated file" is actually testable.
     */
    private function capture(int $index): int
    {
        $mimeFileId = $this->fileStorage->store(
            'message ' . $index,
            'message/rfc822',
            'message.eml',
            'test',
            'superadmin',
            'test_tools'
        );
        $attachmentFileId = $this->fileStorage->store(
            'piece ' . $index,
            'text/plain',
            'note.txt',
            'test',
            'superadmin',
            'test_tools'
        );

        return $this->repository->create(
            (new \DateTimeImmutable('2026-03-01 09:00:00'))->modify('+' . $index . ' minutes'),
            sprintf('[25SV] Message %02d', $index),
            'chef@example.be',
            'noreply@example.be',
            null,
            10,
            false,
            $mimeFileId,
            null,
            null,
            null,
            [['file_name' => 'note.txt', 'mime_type' => 'text/plain', 'size_bytes' => 8, 'file_id' => $attachmentFileId]]
        );
    }

    private function fileCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
    }

    private function filesOnDisk(): int
    {
        $dir = $this->tempDir . '/test';

        return is_dir($dir) ? count(array_diff((array) scandir($dir), ['.', '..'])) : 0;
    }

    public function testTheDefaultRetentionIsFiveHundred(): void
    {
        $this->assertSame(500, MailSandboxService::DEFAULT_RETENTION);
        $this->assertSame(500, $this->sandboxService->retentionLimit());
    }

    public function testTheRetentionSettingIsHonoured(): void
    {
        $this->settingService->set(MailSandboxService::SETTING_RETENTION, '12', MailSandboxService::MODULE_ID);

        $this->assertSame(12, $this->sandboxService->retentionLimit());
    }

    /**
     * "0" must not silently mean "keep everything for ever".
     */
    public function testANonPositiveRetentionFallsBackToTheDefault(): void
    {
        $this->settingService->set(MailSandboxService::SETTING_RETENTION, '0', MailSandboxService::MODULE_ID);

        $this->assertSame(MailSandboxService::DEFAULT_RETENTION, $this->sandboxService->retentionLimit());
    }

    public function testThePurgeKeepsExactlyTheConfiguredCountAndDropsTheOldest(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->capture($i);
        }
        $this->settingService->set(MailSandboxService::SETTING_RETENTION, '4', MailSandboxService::MODULE_ID);

        $deleted = $this->sandboxService->purge(PurgeCapturedEmailsHandler::MAX_PER_RUN);

        $this->assertSame(6, $deleted);
        $this->assertSame(4, $this->sandboxService->count());

        $kept = array_map(
            static fn($email): string => $email->subject,
            $this->sandboxService->page(10, 0)
        );
        // Newest first, so the four survivors are 10, 09, 08, 07.
        $this->assertSame(
            ['[25SV] Message 10', '[25SV] Message 09', '[25SV] Message 08', '[25SV] Message 07'],
            $kept
        );
    }

    public function testThePurgeRemovesEveryAssociatedFileAndLeavesNoOrphan(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->capture($i);
        }
        // Two files per capture: the message and its attachment.
        $this->assertSame(12, $this->fileCount());
        $this->assertSame(12, $this->filesOnDisk());

        $this->settingService->set(MailSandboxService::SETTING_RETENTION, '2', MailSandboxService::MODULE_ID);
        $this->sandboxService->purge(PurgeCapturedEmailsHandler::MAX_PER_RUN);

        $this->assertSame(2, $this->sandboxService->count());
        $this->assertSame(4, $this->fileCount());
        $this->assertSame(4, $this->filesOnDisk());

        // No attachment row survives its message either.
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT COUNT(*) FROM captured_email_attachments')->fetchColumn()
        );
    }

    public function testThePurgeDoesNothingBelowTheLimit(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->capture($i);
        }
        $this->settingService->set(MailSandboxService::SETTING_RETENTION, '10', MailSandboxService::MODULE_ID);

        $this->assertSame(0, $this->sandboxService->purge(PurgeCapturedEmailsHandler::MAX_PER_RUN));
        $this->assertSame(3, $this->sandboxService->count());
        $this->assertSame(6, $this->fileCount());
    }

    /**
     * A run is capped; the rule is not. What is still in excess goes next
     * time rather than holding one request open.
     */
    public function testARunIsBoundedAndTheNextOneFinishesTheJob(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->capture($i);
        }
        $this->settingService->set(MailSandboxService::SETTING_RETENTION, '2', MailSandboxService::MODULE_ID);

        $this->assertSame(3, $this->sandboxService->purge(3));
        $this->assertSame(7, $this->sandboxService->count());

        $this->sandboxService->purge(100);
        $this->assertSame(2, $this->sandboxService->count());
    }

    private function frontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('POST', '/test-tools/mail-sandbox/empty', MailSandboxController::class, 'empty', 'superadmin');

        $configFile = $this->tempDir . '/config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(
            MailSandboxController::class,
            new MailSandboxController($this->twig, $this->sandboxService)
        );

        return $frontController;
    }

    /**
     * @param array<string, string> $body
     */
    private function postEmpty(array $body): int
    {
        return $this->frontController()
            ->handle(new Request('POST', '/test-tools/mail-sandbox/empty', [], $body, [], []))
            ->getStatusCode();
    }

    public function testEmptyingRequiresTheExactWordCheckedServerSide(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->capture($i);
        }

        $status = $this->postEmpty([
            '_csrf_token' => CsrfGuard::generateToken(),
            'confirmation' => 'effacer',
        ]);

        $this->assertSame(400, $status);
        $this->assertSame(3, $this->sandboxService->count());
        $this->assertSame(6, $this->fileCount());
    }

    public function testEmptyingWithNoWordAtAllChangesNothing(): void
    {
        $this->capture(1);

        $this->assertSame(400, $this->postEmpty(['_csrf_token' => CsrfGuard::generateToken()]));
        $this->assertSame(1, $this->sandboxService->count());
    }

    public function testEmptyingRefusesAMissingCsrfToken(): void
    {
        $this->capture(1);

        $this->assertSame(400, $this->postEmpty(['confirmation' => MailSandboxService::CONFIRMATION_WORD]));
        $this->assertSame(1, $this->sandboxService->count());
    }

    public function testEmptyingWithTheExactWordRemovesEverythingRowsAndFiles(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->capture($i);
        }

        $status = $this->postEmpty([
            '_csrf_token' => CsrfGuard::generateToken(),
            'confirmation' => MailSandboxService::CONFIRMATION_WORD,
        ]);

        $this->assertSame(302, $status);
        $this->assertSame(0, $this->sandboxService->count());
        $this->assertSame(0, $this->fileCount());
        $this->assertSame(0, $this->filesOnDisk());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM captured_email_attachments')->fetchColumn()
        );
    }

    public function testAdminIsRejectedFromTheEmptyRoute(): void
    {
        $this->capture(1);

        AuthSession::login(1, 'admin@test.com', 'admin');

        $status = $this->postEmpty([
            '_csrf_token' => CsrfGuard::generateToken(),
            'confirmation' => MailSandboxService::CONFIRMATION_WORD,
        ]);

        $this->assertSame(403, $status);
        $this->assertSame(1, $this->sandboxService->count());
    }

    public function testEmptyingIsJournaledAtSecurityWithNoAddress(): void
    {
        $this->capture(1);
        $this->sandboxService->empty(1);

        $entry = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'mail_capture_emptied'")
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($entry);
        $this->assertSame('security', $entry['level']);
        $this->assertStringNotContainsString('@', (string) $entry['description']);
        $this->assertStringNotContainsString('@', (string) ($entry['context'] ?? ''));
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
