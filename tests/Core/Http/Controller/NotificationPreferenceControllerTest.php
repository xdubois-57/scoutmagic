<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\NotificationPreferenceController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
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
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class NotificationPreferenceControllerTest extends TestCase
{
    private \PDO $pdo;
    private NotificationPreferenceController $controller;
    private NotificationService $notificationService;
    private NotificationPreferenceRepository $preferenceRepository;
    private UserAccountRepository $userAccountRepository;
    private Environment $twig;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->preferenceRepository = new NotificationPreferenceRepository($this->pdo);
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->notificationService = new NotificationService(
            new NotificationRepository($this->pdo, $encryption),
            new PushSubscriptionRepository($this->pdo, $encryption),
            $this->preferenceRepository,
            $this->createMock(WebPush::class),
            $settingService,
            $journalService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->userAccountRepository
        );
        $this->notificationService->registerModuleTypes('test_module', [
            [
                'id' => 'test_module.for_everyone',
                'label' => 'Pour tous', 'description' => 'd', 'group' => 'Test', 'role_min' => 'identified',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
            [
                'id' => 'test_module.chief_only',
                'label' => 'Chefs uniquement', 'description' => 'd', 'group' => 'Test', 'role_min' => 'chief',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
        ]);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');
        $this->twig->addGlobal('vapid_public_key', 'test-key');

        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo);

        $this->controller = new NotificationPreferenceController(
            $this->twig,
            $this->notificationService,
            $this->preferenceRepository,
            $this->userAccountRepository,
            $roleResolver,
            new ScoutYearService($this->pdo)
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('member@test.example'), $encryption->blindIndex('member@test.example')]);
        $this->userId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->userId, 'member@test.example', 'identified');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function issueCsrfToken(): string
    {
        $token = CsrfGuard::generateToken();
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    public function testIndexShowsTypesTheViewerCanSeeAndHidesHigherRoleTypes(): void
    {
        $response = $this->controller->index(new Request('GET', '/notifications/preferences', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Pour tous', $response->getBody());
        $this->assertStringNotContainsString('Chefs uniquement', $response->getBody());
    }

    public function testUpdateChannelSavesAnOverride(): void
    {
        $token = $this->issueCsrfToken();
        $body = json_encode(['type_id' => 'test_module.for_everyone', 'channel' => 'push', 'value' => 'off', '_csrf_token' => $token]);
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/notifications/preferences', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body);

        $response = $this->controller->updateChannel($request, []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $pref = $this->preferenceRepository->find($this->userId, 'test_module.for_everyone');
        $this->assertFalse($pref->push);
    }

    public function testUpdateChannelRejectsALockedChannel(): void
    {
        $this->notificationService->registerModuleTypes('test_module', [[
            'id' => 'test_module.locked', 'label' => 'L', 'description' => 'd', 'group' => 'Test', 'role_min' => 'identified',
            'channels' => ['in_app' => 'on', 'push' => 'off', 'email' => 'off'],
        ]]);
        $token = $this->issueCsrfToken();
        $body = json_encode(['type_id' => 'test_module.locked', 'channel' => 'push', 'value' => 'on', '_csrf_token' => $token]);
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/notifications/preferences', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body);

        $response = $this->controller->updateChannel($request, []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($this->preferenceRepository->find($this->userId, 'test_module.locked'));
    }

    public function testUpdateAccountSettingsSavesQuietHoursAndDiscretion(): void
    {
        $token = $this->issueCsrfToken();
        $body = json_encode(['quiet_hours_start' => '23:00', 'quiet_hours_end' => '06:00', 'discretion' => true, '_csrf_token' => $token]);
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/notifications/quiet-hours', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body);

        $response = $this->controller->updateAccountSettings($request, []);

        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $account = $this->userAccountRepository->findById($this->userId);
        $this->assertSame('23:00', $account->quietHoursStart);
        $this->assertTrue($account->notificationDiscretion);
    }

    public function testUpdateAccountSettingsRejectsOneHourWithoutTheOther(): void
    {
        $token = $this->issueCsrfToken();
        $body = json_encode(['quiet_hours_start' => '23:00', 'quiet_hours_end' => '', 'discretion' => false, '_csrf_token' => $token]);
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/notifications/quiet-hours', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body);

        $response = $this->controller->updateAccountSettings($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- RBAC ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/notifications/preferences', NotificationPreferenceController::class, 'index', 'identified');

        $configFile = sys_get_temp_dir() . '/test_notifpref_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(NotificationPreferenceController::class, $this->controller);

        return $fc;
    }

    public function testPublicVisitorIsRedirectedToLogin(): void
    {
        AuthSession::logout();

        $response = $this->buildFrontController()->handle(new Request('GET', '/notifications/preferences', [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testIdentifiedUserIsAllowedThrough(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/notifications/preferences', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}
