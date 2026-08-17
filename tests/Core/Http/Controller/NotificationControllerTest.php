<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Http\Controller\NotificationController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Notification\NotificationRepository;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NotificationControllerTest extends TestCase
{
    private \PDO $pdo;
    private NotificationController $controller;
    private NotificationRepository $notificationRepository;
    private Environment $twig;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->notificationRepository = new NotificationRepository($this->pdo, $encryption);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');

        $this->controller = new NotificationController($this->twig, $this->notificationRepository);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
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
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    public function testIndexRendersOwnNotificationsGroupedByDay(): void
    {
        $this->notificationRepository->create($this->userId, null, 'core.system', 'Titre 1', 'Corps 1', null);

        $response = $this->controller->index(new Request('GET', '/notifications', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Titre 1', $response->getBody());
        $this->assertStringContainsString('Aujourd&#039;hui', $response->getBody());
    }

    public function testIndexShowsEmptyStateWithNoNotifications(): void
    {
        $response = $this->controller->index(new Request('GET', '/notifications', [], [], [], []), []);

        $this->assertStringContainsString('Aucune notification', $response->getBody());
    }

    /**
     * A read row's indicator span and an unread row's must occupy the
     * exact same box (same width/height/margin, only the unread one gets
     * the bg-primary color class) — otherwise the two rows' text starts
     * at different horizontal positions, which is what was reported.
     */
    public function testReadAndUnreadIndicatorSpansShareTheSameBoxSoTextAligns(): void
    {
        $unreadId = $this->notificationRepository->create($this->userId, null, 'core.system', 'Non lue', 'B', null);
        $readId = $this->notificationRepository->create($this->userId, null, 'core.system', 'Lue', 'B', null);
        $this->notificationRepository->markRead($readId);

        $response = $this->controller->index(new Request('GET', '/notifications', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertMatchesRegularExpression(
            '/<span class="d-inline-block rounded-circle bg-primary" style="width:8px;height:8px;margin-top:6px;flex-shrink:0;"/',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/<span class="d-inline-block rounded-circle" style="width:8px;height:8px;margin-top:6px;flex-shrink:0;"/',
            $body
        );
    }

    public function testMarkReadMarksAndRedirectsToTheNotificationsOwnUrl(): void
    {
        $id = $this->notificationRepository->create($this->userId, null, 'core.system', 'T', 'B', '/gallery/42');
        $token = $this->issueCsrfToken();

        $response = $this->controller->markRead(
            new Request('POST', "/notifications/{$id}/read", [], ['_csrf_token' => $token], [], []),
            ['id' => (string) $id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/gallery/42', $response->getHeaders()['Location']);
        $records = $this->notificationRepository->findByUserAccountId($this->userId);
        $this->assertTrue($records[0]->isRead());
    }

    public function testMarkReadRedirectsToCentreWhenNotificationHasNoUrl(): void
    {
        $id = $this->notificationRepository->create($this->userId, null, 'core.system', 'T', 'B', null);
        $token = $this->issueCsrfToken();

        $response = $this->controller->markRead(
            new Request('POST', "/notifications/{$id}/read", [], ['_csrf_token' => $token], [], []),
            ['id' => (string) $id]
        );

        $this->assertSame('/notifications', $response->getHeaders()['Location']);
    }

    public function testMarkReadRefusesANotificationBelongingToAnotherAccount(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc2', 'idx2']);
        $otherUserId = (int) $this->pdo->lastInsertId();
        $id = $this->notificationRepository->create($otherUserId, null, 'core.system', 'T', 'B', '/secret');
        $token = $this->issueCsrfToken();

        $this->controller->markRead(
            new Request('POST', "/notifications/{$id}/read", [], ['_csrf_token' => $token], [], []),
            ['id' => (string) $id]
        );

        $records = $this->notificationRepository->findByUserAccountId($otherUserId);
        $this->assertFalse($records[0]->isRead());
    }

    public function testMarkAllReadMarksEveryUnreadNotificationForTheAccount(): void
    {
        $this->notificationRepository->create($this->userId, null, 'core.system', 'A', 'B', null);
        $this->notificationRepository->create($this->userId, null, 'core.system', 'C', 'D', null);
        $token = $this->issueCsrfToken();

        $this->controller->markAllRead(new Request('POST', '/notifications/mark-all-read', [], ['_csrf_token' => $token], [], []), []);

        $this->assertSame(0, $this->notificationRepository->countUnread($this->userId));
    }

    public function testUnreadCountReturnsJsonCount(): void
    {
        $this->notificationRepository->create($this->userId, null, 'core.system', 'A', 'B', null);

        $response = $this->controller->unreadCount(new Request('GET', '/api/notifications/unread-count', [], [], [], []), []);

        $data = json_decode($response->getBody(), true);
        $this->assertSame(1, $data['count']);
    }

    // --- RBAC ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/notifications', NotificationController::class, 'index', 'identified');

        $configFile = sys_get_temp_dir() . '/test_notif_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(NotificationController::class, $this->controller);

        return $fc;
    }

    public function testPublicVisitorIsRedirectedToLogin(): void
    {
        AuthSession::logout();

        $response = $this->buildFrontController()->handle(new Request('GET', '/notifications', [], [], [], []));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testIdentifiedUserIsAllowedThrough(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/notifications', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }
}
