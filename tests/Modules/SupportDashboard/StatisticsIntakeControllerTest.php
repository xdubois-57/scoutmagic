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
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->twig = new Environment(new ArrayLoader([]));
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

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

    /**
     * The two ways this codebase decides "this request came over TLS"
     * (Core\Security\SessionManager's convention): the `HTTPS` server
     * variable, or port 443 behind a proxy that does not set it. Either one
     * alone is enough, and neither being present is cleartext.
     *
     * php://input is not settable from a test, so every request here lands
     * on the malformed-payload path — which is exactly what makes the
     * journalled reason the readable signal: reaching `malformed_payload`
     * proves the transport check passed, and `insecure_transport` proves it
     * did not.
     */
    public function testPortFourFourThreeAloneCountsAsSecureTransport(): void
    {
        $this->controller->receive(new Request('POST', '/api/statistics', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('f', 64),
            'REMOTE_ADDR' => '203.0.113.1',
            'SERVER_PORT' => 443,
        ]), []);

        $this->assertSame('malformed_payload', $this->lastRejectionReason());
    }

    public function testTheHttpsServerVariableAloneCountsAsSecureTransport(): void
    {
        $this->controller->receive(new Request('POST', '/api/statistics', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('f', 64),
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTPS' => 'on',
            'SERVER_PORT' => 8443,
        ]), []);

        $this->assertSame('malformed_payload', $this->lastRejectionReason());
    }

    public function testNeitherHttpsNorPortFourFourThreeIsCleartext(): void
    {
        $this->controller->receive(new Request('POST', '/api/statistics', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('f', 64),
            'REMOTE_ADDR' => '203.0.113.1',
            'SERVER_PORT' => 8080,
        ]), []);

        $this->assertSame('insecure_transport', $this->lastRejectionReason());
    }

    public function testAnHttpsValueOfOffIsCleartext(): void
    {
        $this->controller->receive(new Request('POST', '/api/statistics', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('f', 64),
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTPS' => 'off',
            'SERVER_PORT' => 8080,
        ]), []);

        $this->assertSame('insecure_transport', $this->lastRejectionReason());
    }

    /**
     * A rejection is journalled with its source IP and a fixed reason
     * category — the one place the spec asks for the raw address, because
     * an intake endpoint under abuse is unreadable without it — and never
     * with free text or an echo of the payload.
     */
    public function testARejectionIsJournalledWithItsSourceIpAndACategory(): void
    {
        $this->controller->receive($this->request(), []);

        $stmt = $this->pdo->query("SELECT level, context FROM event_log WHERE event_type = 'statistics_report_rejected' ORDER BY id DESC LIMIT 1");
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
        $this->assertIsArray($row);
        $this->assertSame('warning', $row['level']);

        $context = json_decode((string) $row['context'], true);
        $this->assertSame('203.0.113.1', $context['source_ip']);
        $this->assertSame('malformed_payload', $context['reason']);
    }

    private function lastRejectionReason(): ?string
    {
        $stmt = $this->pdo->query("SELECT context FROM event_log WHERE event_type = 'statistics_report_rejected' ORDER BY id DESC LIMIT 1");
        $context = $stmt !== false ? $stmt->fetchColumn() : false;
        if (!is_string($context)) {
            return null;
        }

        $decoded = json_decode($context, true);

        return is_array($decoded) ? (string) ($decoded['reason'] ?? '') : null;
    }
}
