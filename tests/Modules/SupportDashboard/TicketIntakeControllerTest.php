<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Controller\TicketIntakeController;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TicketIntakeService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The HTTP end of the ticket intake (roadmap IT-23).
 *
 * `php://input` cannot be set from a test, so every request here lands on
 * the empty-body path — which is enough for what this file is about: the
 * status codes, the absence of an echo, the closed CSRF question, and the
 * category list every answer carries.
 */
class TicketIntakeControllerTest extends TestCase
{
    private \PDO $pdo;
    private TicketIntakeController $controller;
    private Environment $twig;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->twig = new Environment(new ArrayLoader([]));
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

        $this->controller = new TicketIntakeController(
            $this->twig,
            new TicketIntakeService(
                new SupportInstallationRepository($this->pdo),
                new SupportTicketRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
                new JournalService(new JournalRepository($this->pdo))
            )
        );
    }

    private function request(bool $https = true, bool $withCredentials = true): Request
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTPS' => $https ? 'on' : 'off',
            'SERVER_PORT' => $https ? 443 : 80,
        ];
        if ($withCredentials) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('f', 64);
        }

        return new Request('POST', '/api/support/tickets', [], [], [], $server);
    }

    /**
     * An empty body is a refusal, not a rejection: 200, with the reason
     * where the instance can read it. A non-2xx would make the client
     * retry a request that cannot succeed.
     */
    public function testARefusalAnswersTwoHundredWithItsReason(): void
    {
        $response = $this->controller->receive($this->request(), []);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('refused', $body['status']);
        $this->assertSame('malformed_payload', $body['reason']);
    }

    /**
     * Every answer publishes the closed list, which is how an instance
     * renders a picker it was never shipped with — including on a refusal,
     * where it is exactly what the caller needs to correct itself.
     */
    public function testEveryNonRejectedAnswerCarriesTheCategoryList(): void
    {
        $body = json_decode($this->controller->receive($this->request(), [])->getBody(), true);

        $this->assertSame(TicketCategory::published(), $body['categories']);
        $this->assertContains(['value' => 'desk_import', 'label' => 'Import Desk'], $body['categories']);
        $this->assertSame(['value' => 'other', 'label' => 'Autre'], end($body['categories']));
    }

    /**
     * A 403 says only that. No reason, no category list — nothing an
     * unauthenticated caller could learn from, and nothing echoed back.
     */
    public function testARejectedCallerLearnsNothingAtAll(): void
    {
        $response = $this->controller->receive($this->request(withCredentials: false), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['status' => 'rejected'], json_decode($response->getBody(), true));
        $this->assertStringNotContainsString('categories', $response->getBody());
        $this->assertStringNotContainsString(str_repeat('f', 64), $response->getBody());
    }

    public function testCleartextTransportIsRefused(): void
    {
        $body = json_decode($this->controller->receive($this->request(https: false), [])->getBody(), true);

        $this->assertSame('insecure_transport', $body['reason']);
    }

    public function testTheRouteIsPublicAndRequiresNoCsrfToken(): void
    {
        // The fourth deliberate CSRF exception (SECURITY.md §4): a POST
        // with no session, no cookie and no _csrf_token must reach the
        // controller rather than being refused upstream.
        $router = new Router();
        $router->addRoute('POST', '/api/support/tickets', TicketIntakeController::class, 'receive', 'public');

        $configFile = sys_get_temp_dir() . '/test_ticket_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(TicketIntakeController::class, $this->controller);

        $response = $frontController->handle($this->request());

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertSame(200, $response->getStatusCode());
        unlink($configFile);
    }
}
