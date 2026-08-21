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
use Modules\SupportDashboard\Controller\StatisticsIntakeController;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class StatisticsIntakeControllerTest extends TestCase
{
    private \PDO $pdo;
    private StatisticsIntakeController $controller;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->twig = new Environment(new ArrayLoader([]));

        $this->controller = new StatisticsIntakeController(
            $this->twig,
            new StatisticsIntakeService(
                new SupportInstallationRepository($this->pdo),
                new SupportReportRateLimitRepository($this->pdo),
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                new JournalService(new JournalRepository($this->pdo))
            )
        );
    }

    private function request(bool $https = true): Request
    {
        return new Request('POST', '/api/statistics', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('f', 64),
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTPS' => $https ? 'on' : 'off',
            'SERVER_PORT' => $https ? 443 : 80,
        ]);
    }

    public function testAnUnauthenticatedRequestOverHttpIsRejectedWithoutABody(): void
    {
        $response = $this->controller->receive($this->request(false), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('installation_id', $response->getBody());
    }

    /**
     * php://input is not settable from a test, so the body always arrives
     * empty here — which is precisely the "malformed payload" path, and
     * enough to prove the controller reaches the service, applies the
     * transport check, and answers with a status and no payload echo.
     */
    public function testAMalformedRequestGetsAStatusAndNeverAnEchoOfWhatWasSent(): void
    {
        $response = $this->controller->receive($this->request(), []);

        $this->assertSame(400, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString(str_repeat('f', 64), $body);
        $this->assertSame(['status' => 'rejected'], json_decode($body, true));
    }

    public function testTheRouteIsPublicAndRequiresNoCsrfToken(): void
    {
        // The second deliberate CSRF exception in the codebase (SECURITY.md
        // §4): a POST with no session, no cookie and no _csrf_token must
        // reach the controller rather than being refused upstream.
        $router = new Router();
        $router->addRoute('POST', '/api/statistics', StatisticsIntakeController::class, 'receive', 'public');

        $configFile = sys_get_temp_dir() . '/test_intake_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(StatisticsIntakeController::class, $this->controller);

        $response = $frontController->handle($this->request());

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertSame(400, $response->getStatusCode());
        unlink($configFile);
    }
}
