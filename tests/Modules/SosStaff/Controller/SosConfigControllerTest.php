<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Controller;

use Core\Config\AppConfig;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\SosStaff\Controller\SosConfigController;
use Modules\SosStaff\Repository\ProviderCredentialRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\SosSettingsService;
use Core\Import\MemberYearRepository;
use Core\Member\UnitStaffSectionService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class SosConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private SosConfigController $controller;
    private ProviderCredentialRepository $credentialRepository;
    private Environment $twig;
    private SectionService $sectionService;
    private SosSettingsService $settingsService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->credentialRepository = new ProviderCredentialRepository($this->pdo, $encryption);
        $providerConfigService = new ProviderConfigService($this->credentialRepository, $this->fakeTransport());

        $memberBadgeRepository = new \Core\Badge\MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $settingService->register('email_notifications_enabled', '1', 'boolean', 'Emails', 'desc', 'sos_staff');
        $this->settingsService = new SosSettingsService(
            new ExcludedSectionRepository($this->pdo),
            new SosSettingsRepository($this->pdo),
            $this->sectionService,
            new MemberYearRepository($this->pdo),
            new UnitStaffSectionService($this->pdo),
            $settingService
        );

        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/sos_staff/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'sos_staff');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new SosConfigController(
            $this->twig,
            $providerConfigService,
            $this->settingsService,
            $this->sectionService,
            $journalService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function fakeTransport(): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            if (str_ends_with($url, '/auth/time')) {
                return ['status' => 200, 'body' => (string) time()];
            }
            if (str_ends_with($url, '/auth/credential')) {
                return ['status' => 200, 'body' => '{"consumerKey":"CK123","validationUrl":"https://ovh.example/auth/CK123"}'];
            }
            if (str_ends_with($url, '/telephony')) {
                return ['status' => 200, 'body' => '["ba-1"]'];
            }
            if (str_ends_with($url, '/telephony/ba-1/line')) {
                return ['status' => 200, 'body' => '["0033100000001"]'];
            }
            return ['status' => 404, 'body' => '{}'];
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data, string $path = '/config/sos/x'): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testIndexRendersPage(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/sos', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SOS Staff', $response->getBody());
        $this->assertStringContainsString('OVH Télécom', $response->getBody());
    }

    public function testIndexListsStaffduAsAlwaysExcluded(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/sos', [], [], [], []), []);

        // STAFFDU is rendered unchecked ("not included") and disabled, so
        // it can never be toggled back into the picker.
        $this->assertMatchesRegularExpression('/id="excluded-section-\d+" value="\d+"\s+disabled/', $response->getBody());
    }

    public function testSaveOvhCredentialsValidatesCsrf(): void
    {
        $response = $this->controller->saveOvhCredentials(
            $this->jsonRequest(['application_key' => 'ak', 'application_secret' => 'as', '_csrf_token' => 'bad']),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSaveOvhCredentialsPersists(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->saveOvhCredentials(
            $this->jsonRequest(['application_key' => 'ak123', 'application_secret' => 'as456', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
    }

    public function testSaveOvhCredentialsRejectsEmptyValues(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->saveOvhCredentials(
            $this->jsonRequest(['application_key' => '', 'application_secret' => '', '_csrf_token' => $token]),
            []
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testFullOvhGuidedFlowEndToEnd(): void
    {
        $token = $this->csrfToken();

        $this->controller->saveOvhCredentials(
            $this->jsonRequest(['application_key' => 'ak', 'application_secret' => 'as', '_csrf_token' => $token]),
            []
        );

        $genResponse = $this->controller->generateConsumerKey($this->jsonRequest(['_csrf_token' => $token]), []);
        $genDecoded = json_decode($genResponse->getBody(), true);
        $this->assertTrue($genDecoded['success']);
        $this->assertSame('https://ovh.example/auth/CK123', $genDecoded['validation_url']);

        $validateResponse = $this->controller->validateConsumerKey($this->jsonRequest(['_csrf_token' => $token]), []);
        $this->assertTrue(json_decode($validateResponse->getBody(), true)['success']);

        $listResponse = $this->controller->listLines($this->jsonRequest(['_csrf_token' => $token]), []);
        $listDecoded = json_decode($listResponse->getBody(), true);
        $this->assertTrue($listDecoded['success']);
        $this->assertSame('ba-1', $listDecoded['lines'][0]['billing_account']);

        $selectResponse = $this->controller->selectLine(
            $this->jsonRequest(['billing_account' => 'ba-1', 'service_name' => '0033100000001', '_csrf_token' => $token]),
            []
        );
        $selectDecoded = json_decode($selectResponse->getBody(), true);
        $this->assertTrue($selectDecoded['success']);
        $this->assertSame('0033100000001', $selectDecoded['sos_number']);
    }

    public function testUpdateExcludedSectionsPersists(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['ROU01', 'ROU01', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['ROU01', $branchId, 'Routiers']);
        $sectionId = (int) $this->pdo->lastInsertId();

        $token = $this->csrfToken();
        $response = $this->controller->updateExcludedSections(
            $this->jsonRequest(['section_ids' => [$sectionId], '_csrf_token' => $token]),
            []
        );

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertContains($sectionId, $this->settingsService->getExcludedSectionIds());
    }

    public function testTestConnectionFailsWhenNoProviderConfigured(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->testConnection($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    /**
     * RBAC boundary for /config/sos (Configuration menu, role_min
     * superadmin): superadmin -> 200, admin ("Chef d'Unité", the
     * espace_admin ceiling) -> 403.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/sos', SosConfigController::class, 'index', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_sos_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(SosConfigController::class, $this->controller);

        return $fc;
    }

    public function testSuperadminGetsPage(): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/sos', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminIsDenied(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/sos', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
