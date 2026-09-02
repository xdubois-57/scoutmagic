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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
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

    // ── Pagination ──────────────────────────────────────────────────────

    /**
     * The centre used to read one hard-capped screenful and render it. An
     * account past the cap had notifications that were not merely
     * off-screen but UNREACHABLE, with nothing on the page even hinting
     * they existed.
     */
    public function testTheSecondPageHoldsWhatTheFirstOneCouldNot(): void
    {
        $this->seed(NotificationController::PAGE_SIZE + 1);

        $first = $this->controller->index(new Request('GET', '/notifications', [], [], [], []), [])->getBody();
        $second = $this->controller->index(
            new Request('GET', '/notifications', ['page' => '2'], [], [], []),
            []
        )->getBody();

        // Newest first, so the OLDEST is the one pushed onto page two.
        $this->assertStringContainsString('Titre 001', $second);
        $this->assertStringNotContainsString('Titre 001', $first);
    }

    public function testAPageThatDoesNotExistLandsOnTheLastOneRatherThanOnNothing(): void
    {
        // The page number comes from a query string: `?page=99999` must not
        // render an empty screen that reads like « vous n'avez rien ».
        $this->seed(NotificationController::PAGE_SIZE + 1);

        $body = $this->controller->index(
            new Request('GET', '/notifications', ['page' => '99999'], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('Titre 001', $body);
        $this->assertStringNotContainsString('Aucune notification', $body);
    }

    public function testANegativePageIsTheFirstOne(): void
    {
        $this->seed(2);

        $body = $this->controller->index(
            new Request('GET', '/notifications', ['page' => '-3'], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('Titre 002', $body);
    }

    public function testASingleScreenfulShowsNoPaginationAtAll(): void
    {
        // A pagination bar with one disabled button is furniture, not
        // information (partials/pagination.html.twig).
        $this->seed(3);

        $body = $this->controller->index(new Request('GET', '/notifications', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('Pagination des notifications', $body);
    }

    public function testThePageSaysHowManyThereAreAndWhatBecomesOfThem(): void
    {
        $this->seed(NotificationController::PAGE_SIZE + 1);

        $body = $this->controller->index(new Request('GET', '/notifications', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Pagination des notifications', $body);
        $this->assertStringContainsString((string) (NotificationController::PAGE_SIZE + 1), $body);
        $this->assertStringContainsString('durée de conservation', $body);
    }

    public function testOneAccountsPagesNeverReachAnothersNotifications(): void
    {
        // Every read is filtered by the session's own account id, never by
        // a parameter — and a page number must not become a way around it.
        $other = $this->pdo->query('SELECT MAX(id) + 1 FROM user_accounts')->fetchColumn();
        foreach (range(1, NotificationController::PAGE_SIZE + 5) as $n) {
            $this->notificationRepository->create((int) $other, null, 'core.system', "Autre {$n}", 'Corps', null);
        }
        $this->seed(1);

        $body = $this->controller->index(
            new Request('GET', '/notifications', ['page' => '2'], [], [], []),
            []
        )->getBody();

        $this->assertStringNotContainsString('Autre', $body);
    }

    /**
     * Titles are zero-padded on purpose: « Titre 1 » is a substring of
     * « Titre 10 », so an unpadded assertion that page one does NOT hold
     * the oldest row passes or fails for the wrong reason.
     */
    private function seed(int $count): void
    {
        foreach (range(1, $count) as $n) {
            $this->notificationRepository->create(
                $this->userId,
                null,
                'core.system',
                sprintf('Titre %03d', $n),
                'Corps',
                null
            );
        }
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
