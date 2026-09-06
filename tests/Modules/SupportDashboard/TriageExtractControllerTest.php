<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Controller\TriageExtractController;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TriageExtractBuilder;
use Modules\SupportDashboard\Service\TriageExtractService;
use Modules\SupportDashboard\Service\TriageTokenService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `POST /api/support/tickets/{reference}/triage-extract`: a public route
 * with no session, the seventh CSRF exception (SECURITY.md §4), whose
 * every refusal is the same bare 403.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TriageExtractControllerTest extends TestCase
{
    private \PDO $pdo;
    private TriageExtractController $controller;
    private Environment $twig;
    private string $token;
    private string $reference;
    private string $storagePath;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $settingRepository = new SettingRepository($this->pdo);
        $settingRepository->insert(
            TriageTokenService::MODULE_ID,
            TriageTokenService::SETTING_KEY,
            '',
            'secret',
            'Jeton',
            'Empreinte.',
            null,
            null,
            false,
            0
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-triage-ctrl-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);

        $files = new FileRepository($this->pdo);
        $storage = new EncryptedFileStorageService($files, $encryption, $this->storagePath);
        $tickets = new SupportTicketRepository($this->pdo, $encryption);
        $tokens = new TriageTokenService(new SettingService($settingRepository), $journal);
        $this->token = $tokens->issue();

        $installationId = (new SupportInstallationRepository($this->pdo))->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            '{}',
            []
        );
        $this->reference = $tickets->create(
            $installationId,
            TicketCategory::of('other'),
            'Description.',
            'chef@unite.be',
            '1.0.41',
            '8.4.0',
            null,
            TriageExtractService::REQUIRED_CONSENT_SCOPE
        );

        $path = tempnam(sys_get_temp_dir(), 'sm-triage-ctrl-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('statistics.json', '{}');
        $zip->close();
        $fileId = $storage->store((string) file_get_contents($path), 'application/zip', 'support.zip', 'support-tickets', 'superadmin');
        @unlink($path);
        $tickets->attachArchive((int) $tickets->findByReference($this->reference)['id'], $fileId);

        $this->twig = new Environment(new ArrayLoader([]));
        $this->controller = new TriageExtractController(
            $this->twig,
            new TriageExtractService(
                $tokens,
                $tickets,
                new StoredFileReader($files, $storage, $this->storagePath),
                new TriageExtractBuilder(),
                $journal,
                new SupportReportRateLimitRepository($this->pdo),
                $encryption
            )
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->storagePath . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->storagePath);
    }

    public function testAValidCallAnswersTheZip(): void
    {
        $response = $this->controller->serve($this->request(), ['reference' => $this->reference]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('PK', $response->getBody());
        $this->assertSame('application/zip', $response->getHeaders()['Content-Type']);
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
        $this->assertStringContainsString($this->reference, $response->getHeaders()['Content-Disposition']);
    }

    /**
     * The end-to-end half of the uniform 403: a real service, a real
     * database, every refusal reason the service can reach. The half that
     * needs no database — that the CONTROLLER answers the same bytes
     * whatever reason the service gives — lives in
     * TriageExtractControllerRefusalTest, which cannot skip.
     */
    public function testEveryRefusalTheServiceReachesIsTheSameBareForbidden(): void
    {
        $refusals = [
            'wrong token' => $this->controller->serve($this->request(token: 'nope'), ['reference' => $this->reference]),
            'no token' => $this->controller->serve($this->request(token: ''), ['reference' => $this->reference]),
            'unknown reference' => $this->controller->serve($this->request(), ['reference' => 'SUP-ZZZZZZ']),
            'cleartext' => $this->controller->serve($this->request(https: false), ['reference' => $this->reference]),
            'no issue' => $this->controller->serve($this->request(body: '{}'), ['reference' => $this->reference]),
        ];

        foreach ($refusals as $case => $response) {
            $this->assertSame(403, $response->getStatusCode(), $case);
            $this->assertSame('{"status":"rejected"}', $response->getBody(), $case);
        }
    }

    public function testTheRouteIsPublicAndRequiresNoCsrfToken(): void
    {
        $router = new Router();
        $router->addRoute(
            'POST',
            '/api/support/tickets/{reference}/triage-extract',
            TriageExtractController::class,
            'serve',
            'public'
        );

        $configFile = sys_get_temp_dir() . '/test_triage_extract_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(TriageExtractController::class, $this->controller);

        $response = $frontController->handle($this->request());

        $this->assertSame(200, $response->getStatusCode());
        unlink($configFile);
    }

    private function request(?string $token = null, bool $https = true, ?string $body = null): Request
    {
        $token ??= $this->token;
        $server = [
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTPS' => $https ? 'on' : 'off',
            'SERVER_PORT' => $https ? 443 : 80,
        ];
        if ($token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        // The body arrives on php://input, which a test cannot write to;
        // the same shape Tests\Core\Http\Controller\WebhookControllerTest
        // uses.
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/api/support/tickets/' . $this->reference . '/triage-extract', [], [], [], $server])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn($body ?? '{"github_issue_number":181}');

        return $request;
    }
}
