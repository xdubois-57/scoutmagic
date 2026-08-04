<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\NotificationConfigController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class NotificationConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private NotificationConfigController $controller;
    private NotificationService $notificationService;
    private PushSubscriptionRepository $subscriptionRepository;
    private SecretManager $secretManager;
    private Environment $twig;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->subscriptionRepository = new PushSubscriptionRepository($this->pdo, $encryption);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);

        $this->notificationService = new NotificationService(
            new NotificationRepository($this->pdo, $encryption),
            $this->subscriptionRepository,
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $settingService,
            $journalService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $userAccountRepository
        );

        $storagePath = sys_get_temp_dir() . '/notif_config_test_' . uniqid();
        mkdir($storagePath, 0755, true);
        $this->secretManager = new SecretManager($storagePath . '/keys/master.key', $storagePath . '/config/secrets.enc');
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets(['vapid_public_key' => 'old-pub', 'vapid_private_key' => 'old-priv']);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');

        $this->controller = new NotificationConfigController(
            $this->twig,
            $this->notificationService,
            $settingService,
            $journalService,
            $this->secretManager,
            $this->pdo
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 1)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->userId, 'admin@test.example', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function issueCsrfToken(): string
    {
        $token = CsrfGuard::generateToken();
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    public function testIndexShowsDeviceCountAndVapidState(): void
    {
        $this->subscriptionRepository->create($this->userId, 'https://push.example/a', 'auth', 'p256dh');

        $response = $this->controller->index(new Request('GET', '/config/notifications', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('1', $response->getBody());
    }

    public function testRotateVapidRegeneratesKeysAndDisconnectsExistingDevices(): void
    {
        $this->subscriptionRepository->create($this->userId, 'https://push.example/a', 'auth', 'p256dh');
        $token = $this->issueCsrfToken();

        $response = $this->controller->rotateVapid(new Request('POST', '/config/notifications/rotate-vapid', [], ['_csrf_token' => $token], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->subscriptionRepository->findByUserAccountId($this->userId));
        $secrets = $this->secretManager->readSecrets();
        $this->assertNotSame('old-pub', $secrets['vapid_public_key']);
    }

    public function testRotateVapidValidatesCsrf(): void
    {
        $response = $this->controller->rotateVapid(new Request('POST', '/config/notifications/rotate-vapid', [], ['_csrf_token' => 'bad'], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $secrets = $this->secretManager->readSecrets();
        $this->assertSame('old-pub', $secrets['vapid_public_key']);
    }

    public function testSendTestCreatesANotificationRecordForTheActingAdmin(): void
    {
        $token = $this->issueCsrfToken();

        $this->controller->sendTest(new Request('POST', '/config/notifications/test', [], ['_csrf_token' => $token], [], []), []);

        $notifications = (new NotificationRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))
            ->findByUserAccountId($this->userId);
        $this->assertCount(1, $notifications);
        $this->assertSame('Notification test', $notifications[0]->title);
    }

    // --- RBAC ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/notifications', NotificationConfigController::class, 'index', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_notifconfig_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(NotificationConfigController::class, $this->controller);

        return $fc;
    }

    public function testSuperadminIsAllowedThrough(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/config/notifications', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminIsDenied(): void
    {
        AuthSession::logout();
        AuthSession::login($this->userId, 'admin@test.example', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/notifications', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
