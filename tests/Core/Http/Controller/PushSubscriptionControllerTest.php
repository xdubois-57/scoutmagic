<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\PushSubscriptionController;
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
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PushSubscriptionControllerTest extends TestCase
{
    private \PDO $pdo;
    private PushSubscriptionController $controller;
    private PushSubscriptionRepository $subscriptionRepository;
    private Environment $twig;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->subscriptionRepository = new PushSubscriptionRepository($this->pdo, $encryption);
        $notificationRepository = new NotificationRepository($this->pdo, $encryption);
        $webPush = $this->createMock(WebPush::class);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $notificationService = new NotificationService(
            $notificationRepository,
            $this->subscriptionRepository,
            new NotificationPreferenceRepository($this->pdo),
            $webPush,
            $settingService,
            $journalService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption)
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

        $this->controller = new PushSubscriptionController($this->twig, $notificationService, $journalService);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->userId, 'member@test.be', 'identified');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(string $method, array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs([$method, '/api/push-subscription', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testSubscribeValidatesCsrf(): void
    {
        $response = $this->controller->subscribe($this->jsonRequest('POST', [
            'endpoint' => 'https://93.184.216.34/device', 'auth_key' => 'a', 'p256dh_key' => 'p', '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSubscribeRejectsIncompleteData(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->subscribe($this->jsonRequest('POST', [
            'endpoint' => '', 'auth_key' => 'a', 'p256dh_key' => 'p', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSubscribeSucceeds(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->subscribe($this->jsonRequest('POST', [
            'endpoint' => 'https://93.184.216.34/device', 'auth_key' => 'auth', 'p256dh_key' => 'p256dh', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertCount(1, $this->subscriptionRepository->findByUserAccountId($this->userId));
    }

    /**
     * @dataProvider internalEndpoints
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('internalEndpoints')]
    public function testSubscribeRefusesAnInternalOrNonHttpsEndpoint(string $endpoint): void
    {
        // The endpoint is POSTed to server-side, so an internal/non-https one
        // must never be stored — otherwise the delete-on-404/410 behaviour
        // becomes a port/path oracle (audit M4).
        $response = $this->controller->subscribe($this->jsonRequest('POST', [
            'endpoint' => $endpoint, 'auth_key' => 'auth', 'p256dh_key' => 'p256dh', '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['success']);
        $this->assertCount(0, $this->subscriptionRepository->findByUserAccountId($this->userId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function internalEndpoints(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/device'],
            'metadata' => ['https://169.254.169.254/latest'],
            'private' => ['https://10.0.0.1/device'],
            'http not https' => ['http://93.184.216.34/device'],
        ];
    }

    public function testUnsubscribeValidatesCsrf(): void
    {
        $response = $this->controller->unsubscribe($this->jsonRequest('DELETE', [
            'endpoint' => 'https://93.184.216.34/device', '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUnsubscribeRemovesTheDevice(): void
    {
        $this->subscriptionRepository->create($this->userId, 'https://93.184.216.34/device', 'auth', 'p256dh');
        $token = $this->csrfToken();

        $response = $this->controller->unsubscribe($this->jsonRequest('DELETE', [
            'endpoint' => 'https://93.184.216.34/device', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame([], $this->subscriptionRepository->findByUserAccountId($this->userId));
    }

    /**
     * RBAC boundary: role_min identified — public visitor denied, identified allowed.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('POST', '/api/push-subscription', PushSubscriptionController::class, 'subscribe', 'identified');

        $configFile = sys_get_temp_dir() . '/test_push_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(PushSubscriptionController::class, $this->controller);

        return $fc;
    }

    public function testPublicVisitorIsRedirectedToLogin(): void
    {
        AuthSession::logout();

        $response = $this->buildFrontController()->handle($this->jsonRequest('POST', ['_csrf_token' => 'x']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testIdentifiedUserIsAllowedThrough(): void
    {
        $token = $this->csrfToken();

        $response = $this->buildFrontController()->handle($this->jsonRequest('POST', [
            'endpoint' => 'https://93.184.216.34/device', 'auth_key' => 'auth', 'p256dh_key' => 'p256dh', '_csrf_token' => $token,
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }
}
