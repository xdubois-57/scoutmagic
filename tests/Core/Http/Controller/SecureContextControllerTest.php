<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SecureContextController;
use Core\Http\FlashMessage;
use Core\Http\FrontController;
use Core\Http\InsecureBrowserAccess;
use Core\Http\Request;
use Core\Http\RequestScheme;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two writes of the secure-context state (#751): the beacon, public
 * and checked by its Origin rather than a CSRF token, and « Ignorer »,
 * superadmin and CSRF-checked.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SecureContextControllerTest extends TestCase
{
    private const HOST = 'unite.example.org';

    private \PDO $pdo;
    private SettingService $settings;
    private SecureContextController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->controller = new SecureContextController(
            \Tests\TestTwig::create(),
            new InsecureBrowserAccess($this->settings),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        // tests/bootstrap.php runs the suite with the development exception.
        RequestScheme::setHttpsRequired(false);
        $_SESSION = [];
    }

    private function beacon(?string $origin): Request
    {
        $server = ['HTTP_HOST' => self::HOST];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return new Request('POST', InsecureBrowserAccess::BEACON_PATH, [], [], [], $server);
    }

    private function lastObservedAt(): ?int
    {
        return (new InsecureBrowserAccess($this->settings))->lastObservedAt();
    }

    /** The meta tag, the constant and the route must name one path. */
    public function testTheBeaconPathIsTheOneRoutedAndTheOneThePageAnnounces(): void
    {
        $root = dirname(__DIR__, 4);

        $this->assertSame('/api/connexion-non-securisee', InsecureBrowserAccess::BEACON_PATH);
        $this->assertMatchesRegularExpression(
            "~addRoute\\(\\s*'POST',\\s*'/api/connexion-non-securisee',\\s*\\\\Core\\\\Http\\\\Controller\\\\"
                . "SecureContextController::class,\\s*'report',\\s*'public'~",
            (string) file_get_contents($root . '/public/index.php')
        );
        $this->assertMatchesRegularExpression(
            "~addRoute\\(\\s*'POST',\\s*'/config/maintenance/connexion-securisee/ignorer',\\s*\\\\Core\\\\Http\\\\"
                . "Controller\\\\SecureContextController::class,\\s*'dismiss',\\s*'superadmin'~",
            (string) file_get_contents($root . '/public/index.php')
        );
        $this->assertStringContainsString(
            'content="/api/connexion-non-securisee"',
            (string) file_get_contents($root . '/core/View/templates/base.html.twig')
        );
    }

    /**
     * The case the route exists for: a page in clear, so no session and no
     * CSRF token — only its own Origin.
     */
    public function testABeaconFromThisSiteRecordsTheObservationWithoutASession(): void
    {
        RequestScheme::setHttpsRequired(true);

        $response = $this->controller->report($this->beacon('http://' . self::HOST), []);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($this->lastObservedAt());
    }

    public function testABeaconFromAnotherSiteRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(true);

        $response = $this->controller->report($this->beacon('https://evil.example.net'), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->lastObservedAt());
    }

    public function testABeaconWithoutAnOriginRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(true);

        $this->assertSame(403, $this->controller->report($this->beacon(null), [])->getStatusCode());
        $this->assertNull($this->lastObservedAt());
    }

    public function testAnInstallationThatToleratesHttpRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(false);

        $this->assertSame(204, $this->controller->report($this->beacon('http://' . self::HOST), [])->getStatusCode());
        $this->assertNull($this->lastObservedAt());
    }

    public function testIgnoringClearsTheStateAndIsJournaled(): void
    {
        RequestScheme::setHttpsRequired(true);
        (new InsecureBrowserAccess($this->settings))->record(time());
        AuthSession::login(7, 'admin@example.invalid', 'superadmin');

        $response = $this->controller->dismiss(
            new Request('POST', '/config/maintenance/connexion-securisee/ignorer', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/maintenance#host-check-secure_connection', $response->getHeaders()['Location']);
        $this->assertNull($this->lastObservedAt());
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM event_log WHERE category = ? AND event_type = ? AND level = ? AND user_account_id = ?'
        );
        $stmt->execute(['core', 'insecure_access_dismissed', 'security', 7]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testIgnoringWithoutAValidTokenChangesNothing(): void
    {
        RequestScheme::setHttpsRequired(true);
        (new InsecureBrowserAccess($this->settings))->record(time());
        AuthSession::login(7, 'admin@example.invalid', 'superadmin');
        CsrfGuard::generateToken();

        $response = $this->controller->dismiss(
            new Request('POST', '/config/maintenance/connexion-securisee/ignorer', [], [
                '_csrf_token' => 'forged',
            ], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->lastObservedAt());
    }

    /**
     * RBAC boundary (AGENTS.md § Tests), through the real router and front
     * controller: the beacon is public; « Ignorer » is superadmin.
     */
    private function throughTheRouter(Request $request): \Core\Http\Response
    {
        $router = new Router();
        $router->addRoute('POST', InsecureBrowserAccess::BEACON_PATH, SecureContextController::class, 'report', 'public');
        $router->addRoute(
            'POST',
            '/config/maintenance/connexion-securisee/ignorer',
            SecureContextController::class,
            'dismiss',
            'superadmin'
        );

        $configFile = sys_get_temp_dir() . '/test_secure_context_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $front = new FrontController($router, \Tests\TestTwig::create(), new AppConfig($configFile));
        $front->registerController(SecureContextController::class, $this->controller);
        $response = $front->handle($request);
        @unlink($configFile);

        return $response;
    }

    public function testAPublicVisitorReachesTheBeacon(): void
    {
        RequestScheme::setHttpsRequired(true);
        AuthSession::logout();

        $response = $this->throughTheRouter($this->beacon('http://' . self::HOST));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($this->lastObservedAt());
    }

    public function testAnAdminBelowSuperadminCannotIgnore(): void
    {
        RequestScheme::setHttpsRequired(true);
        (new InsecureBrowserAccess($this->settings))->record(time());
        AuthSession::login(8, 'chef@example.invalid', 'admin');

        $response = $this->throughTheRouter(new Request(
            'POST',
            '/config/maintenance/connexion-securisee/ignorer',
            [],
            ['_csrf_token' => CsrfGuard::generateToken()],
            [],
            []
        ));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->lastObservedAt());
    }

    public function testASuperadminCanIgnore(): void
    {
        RequestScheme::setHttpsRequired(true);
        (new InsecureBrowserAccess($this->settings))->record(time());
        AuthSession::login(7, 'admin@example.invalid', 'superadmin');

        $response = $this->throughTheRouter(new Request(
            'POST',
            '/config/maintenance/connexion-securisee/ignorer',
            [],
            ['_csrf_token' => CsrfGuard::generateToken()],
            [],
            []
        ));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->lastObservedAt());
    }
}
