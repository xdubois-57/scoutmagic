<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * RBAC boundary for POST /members/{id}/scout-year-offset (mirrors the
 * public/index.php route: role_min "chief"). The "Décalage année scoute"
 * control is chief/admin only: chief → 200, intendant → 403.
 */
class MemberScoutYearOffsetRbacTest extends TestCase
{
    private AppConfig $config;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        ScoutYearOffsetStubController::$reached = 0;

        $configFile = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $this->config = new AppConfig($configFile);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        // Mirrors the module route registered in public/index.php.
        $router->addRoute('POST', '/members/{id}/scout-year-offset', ScoutYearOffsetStubController::class, 'update', 'chief');

        $twig = $this->createMock(Environment::class);
        $fc = new FrontController($router, $twig, $this->config);
        $fc->registerController(ScoutYearOffsetStubController::class, new ScoutYearOffsetStubController($twig));

        return $fc;
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    public function testChiefIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief@test.com', 'chief');

        $response = $this->buildFrontController()->handle(new Request('POST', '/members/1/scout-year-offset', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminIsAllowed(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');

        $response = $this->buildFrontController()->handle(new Request('POST', '/members/1/scout-year-offset', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIntendantIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'intendant@test.com', 'intendant');

        $response = $this->buildFrontController()->handle(new Request('POST', '/members/1/scout-year-offset', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            ScoutYearOffsetStubController::$reached,
            'the refusal must happen before the action, not after it'
        );
    }

    public function testIdentifiedIsDenied(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'member@test.com', 'identified');

        $response = $this->buildFrontController()->handle(new Request('POST', '/members/1/scout-year-offset', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            ScoutYearOffsetStubController::$reached,
            'the refusal must happen before the action, not after it'
        );
    }

    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->startTestSession();

        $response = $this->buildFrontController()->handle(new Request('POST', '/members/1/scout-year-offset', [], [], [], []));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
        $this->assertSame(
            0,
            ScoutYearOffsetStubController::$reached,
            'a visitor sent to the login page must not have written anything on the way'
        );
    }
}

class ScoutYearOffsetStubController extends AbstractController
{
    /**
     * How many times the action was actually reached.
     *
     * A refusal test that only reads the status code is satisfied by a
     * dispatch that runs the action and answers 403 afterwards — the write
     * would already have happened. Here the action stands for the write,
     * so counting it is how « denied » stops being a claim about the
     * response alone (issue #387).
     */
    public static int $reached = 0;

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        self::$reached++;

        return $this->json(['success' => true]);
    }
}
