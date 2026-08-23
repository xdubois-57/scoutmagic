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
use Modules\TestTools\Controller\TestToolsController;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Every route of the toolbox through the REAL Router/RbacGuard pipeline:
 * allowed at `superadmin`, denied one level below.
 */
#[Group('database')]
class MailSandboxControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private MailSandboxService $sandboxService;
    private CapturedEmailRepository $repository;
    private string $tempDir;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);

        $encryption = TestToolsTestHelper::encryption();
        $this->repository = new CapturedEmailRepository($this->pdo, $encryption);

        $this->tempDir = sys_get_temp_dir() . '/test_tools_ctrl_' . uniqid();
        mkdir($this->tempDir, 0700, true);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(
            MailSandboxService::SETTING_ARMED,
            '0',
            'boolean',
            'Capture des e-mails sortants',
            'Réglage de test.',
            MailSandboxService::MODULE_ID,
            null,
            null,
            false
        );

        $this->sandboxService = new MailSandboxService(
            $this->repository,
            $settingService,
            new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, $this->tempDir),
            new JournalService(new JournalRepository($this->pdo))
        );

        $root = dirname(__DIR__, 3);
        $this->twig = TwigFactory::create(
            $root . '/core/View/templates',
            false,
            ['test_tools' => $root . '/modules/test_tools/views']
        );
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/test-tools');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        self::removeDir($this->tempDir);
    }

    private function frontController(string $path, string $controllerClass, string $action, string $method = 'GET'): FrontController
    {
        $router = new Router();
        $router->addRoute($method, $path, $controllerClass, $action, 'superadmin');

        $configFile = $this->tempDir . '/config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(TestToolsController::class, new TestToolsController($this->twig));
        $frontController->registerController(
            MailSandboxController::class,
            new MailSandboxController($this->twig, $this->sandboxService)
        );

        return $frontController;
    }

    public function testSuperadminReachesTheToolboxIndex(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/test-tools', TestToolsController::class, 'index')
            ->handle(new Request('GET', '/test-tools', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Outils de test', $response->getBody());
    }

    public function testAdminIsRejectedFromTheToolboxIndex(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->frontController('/test-tools', TestToolsController::class, 'index')
            ->handle(new Request('GET', '/test-tools', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSuperadminReachesTheMailSandbox(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController('/test-tools/mail-sandbox', MailSandboxController::class, 'index')
            ->handle(new Request('GET', '/test-tools/mail-sandbox', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Bac à sable e-mail', $response->getBody());
    }

    public function testAdminIsRejectedFromTheMailSandbox(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->frontController('/test-tools/mail-sandbox', MailSandboxController::class, 'index')
            ->handle(new Request('GET', '/test-tools/mail-sandbox', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminIsRejectedFromTheCaptureSwitch(): void
    {
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->frontController(
            '/test-tools/mail-sandbox/capture',
            MailSandboxController::class,
            'toggleCapture',
            'POST'
        )->handle(new Request('POST', '/test-tools/mail-sandbox/capture', [], ['armed' => '1'], [], []));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->sandboxService->armed());
    }

    public function testTheCaptureSwitchRefusesAMissingCsrfToken(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $response = $this->frontController(
            '/test-tools/mail-sandbox/capture',
            MailSandboxController::class,
            'toggleCapture',
            'POST'
        )->handle(new Request('POST', '/test-tools/mail-sandbox/capture', [], ['armed' => '1'], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertFalse($this->sandboxService->armed());
    }

    public function testArmingAndDisarmingIsJournaledAtSecurityWithNoAddress(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $this->sandboxService->setArmed(true, 1);
        $this->assertTrue($this->sandboxService->armed());

        $this->sandboxService->setArmed(false, 1);
        $this->assertFalse($this->sandboxService->armed());

        $entries = $this->pdo->query('SELECT * FROM event_log ORDER BY id ASC')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertSame('security', $entry['level']);
            $this->assertSame('test_tools', $entry['category']);
            $this->assertStringNotContainsString('@', (string) $entry['description']);
            $this->assertStringNotContainsString('@', (string) ($entry['context'] ?? ''));
        }

        $this->assertSame('mail_capture_armed', $entries[0]['event_type']);
        $this->assertSame('mail_capture_disarmed', $entries[1]['event_type']);
    }

    public function testTheSwitchIsHonouredThroughTheRoute(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
        $token = CsrfGuard::generateToken();

        $response = $this->frontController(
            '/test-tools/mail-sandbox/capture',
            MailSandboxController::class,
            'toggleCapture',
            'POST'
        )->handle(new Request(
            'POST',
            '/test-tools/mail-sandbox/capture',
            [],
            ['armed' => '1', '_csrf_token' => $token],
            [],
            []
        ));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->sandboxService->armed());
    }

    public function testTheListShowsCapturedMessagesNewestFirst(): void
    {
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');

        $this->repository->create(
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            '[25SV] Ancien message',
            'ancien@example.be',
            'noreply@example.be',
            null,
            100,
            false,
            null,
            null,
            null,
            null,
            []
        );
        $this->repository->create(
            new \DateTimeImmutable('2026-02-01 10:00:00'),
            '[25SV] Message récent',
            'recent@example.be',
            'noreply@example.be',
            null,
            120,
            true,
            null,
            null,
            null,
            null,
            []
        );

        $body = $this->frontController('/test-tools/mail-sandbox', MailSandboxController::class, 'index')
            ->handle(new Request('GET', '/test-tools/mail-sandbox', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('Message récent', $body);
        $this->assertStringContainsString('Ancien message', $body);
        $this->assertLessThan(
            strpos($body, 'Ancien message'),
            strpos($body, 'Message récent'),
            'The newest capture must come first'
        );
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
