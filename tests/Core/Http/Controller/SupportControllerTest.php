<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SupportController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\SecretManager;
use Core\Statistics\InstallationDateService;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportControllerTest extends TestCase
{
    private \PDO $pdo;
    private SupportController $controller;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private Environment $twig;
    private string $projectRoot;
    private SecretManager $secretManager;
    private InstallationIdentityService $identityService;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-support-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $this->registerSettings();

        $this->identityService = new InstallationIdentityService($this->settings, $this->secretManager);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFilter(new \Twig\TwigFilter('french_date', fn($d) => (string) $d));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');

        $this->controller = new SupportController(
            $this->twig,
            $this->settings,
            $journalService,
            new StatisticsPayloadBuilder(
                $this->settings,
                $this->pdo,
                $this->identityService,
                $this->projectRoot
            )
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 1)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->userId, 'admin@test.example', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function registerSettings(): void
    {
        $this->settings->register('base_url', 'https://unite-exemple.be', 'url', 'L', 'D');
        $this->settings->register('statistics_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');
        $this->settings->register('support_email', 'support@scoutmagic.be', 'email', 'L', 'D', null, null, null, false);
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportController::LAST_SUCCESS_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportController::LAST_FAILURE_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportController::LAST_FAILURE_REASON_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        InstallationDateService::register($this->settings);
        $this->settings->clearCache();
    }

    private function issueCsrfToken(): string
    {
        $token = CsrfGuard::generateToken();
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    public function testIndexRendersTheThreeBlocksAndTheWarning(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Statistiques d\'utilisation', $body);
        $this->assertStringContainsString('État des envois', $body);
        $this->assertStringContainsString('Aperçu de ce qui est envoyé', $body);
        $this->assertStringContainsString('Paquet de support', $body);
        $this->assertStringContainsString('alert-warning', $body);
        $this->assertStringContainsString('mais cela ne peut pas être garanti', $body);
        $this->assertStringContainsString('support@scoutmagic.be', $body);
    }

    public function testTheExplanationDoesNotClaimTheReportIsAnonymous(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('n\'est pas anonyme', $body);
    }

    public function testThePreviewIsProducedEvenWhenReportingIsDisabled(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('statistics_schema_version', $body);
        $this->assertStringContainsString('installation_id', $body);
        $this->assertStringContainsString('unite-exemple.be', $body);
    }

    public function testNoSendStateIsShownAsSuchRatherThanAsZero(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Aucun envoi n\'a encore abouti', $body);
    }

    public function testSendStateIsShownWhenPresent(): void
    {
        $this->settingRepository->updateValue(null, SupportController::LAST_SUCCESS_SETTING, '2026-08-19T03:00:00+00:00');
        $this->settingRepository->updateValue(null, SupportController::LAST_FAILURE_SETTING, '2026-08-18T03:00:00+00:00');
        $this->settingRepository->updateValue(null, SupportController::LAST_FAILURE_REASON_SETTING, 'http_500');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('2026-08-19T03:00:00+00:00', $body);
        $this->assertStringContainsString('http_500', $body);
    }

    public function testNoNextScheduledSendIsAdvertised(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('Prochain envoi', $body);
    }

    public function testNoBugReportFormIsOffered(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('name="contact_email"', $body);
        $this->assertStringNotContainsString('name="description"', $body);
        $this->assertStringNotContainsString('name="contact_name"', $body);
    }

    public function testTheSecretNeverAppearsInTheResponse(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('statistics_secret', $body);
    }

    public function testSavingRequiresAValidCsrfToken(): void
    {
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => 'wrong', 'statistics_enabled' => ''], [], []);
        $response = $this->controller->saveStatistics($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('statistics_enabled'));
    }

    public function testDisablingReportingPersistsAndIsJournaled(): void
    {
        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => $token], [], []);

        $this->controller->saveStatistics($request, []);

        $this->settings->clearCache();
        $this->assertSame('0', $this->settings->get('statistics_enabled'));
        $this->assertSame(['statistics_reporting_disabled'], $this->journalEventTypes());
    }

    public function testEnablingReportingIsJournaled(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => $token, 'statistics_enabled' => '1'], [], []);

        $this->controller->saveStatistics($request, []);

        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('statistics_enabled'));
        $this->assertSame(['statistics_reporting_enabled'], $this->journalEventTypes());
    }

    public function testSavingWithoutAChangeIsNotJournaled(): void
    {
        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => $token, 'statistics_enabled' => '1'], [], []);

        $this->controller->saveStatistics($request, []);

        $this->assertSame([], $this->journalEventTypes());
    }

    public function testTheDestinationIsSavedAndValidated(): void
    {
        $token = $this->issueCsrfToken();

        $this->controller->saveStatistics(new Request('POST', '/config/support/statistics', [], [
            '_csrf_token' => $token,
            'statistics_enabled' => '1',
            'statistics_destination' => 'https://stats.exemple.be',
        ], [], []), []);
        $this->settings->clearCache();
        $this->assertSame('https://stats.exemple.be', $this->settings->get('statistics_destination'));

        $this->controller->saveStatistics(new Request('POST', '/config/support/statistics', [], [
            '_csrf_token' => $token,
            'statistics_enabled' => '1',
            'statistics_destination' => 'not a url',
        ], [], []), []);
        $this->settings->clearCache();
        $this->assertSame('https://stats.exemple.be', $this->settings->get('statistics_destination'));
    }

    /**
     * @return array<int, string>
     */
    private function journalEventTypes(): array
    {
        $stmt = $this->pdo->query('SELECT event_type FROM event_log ORDER BY id');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];

        return array_map('strval', $rows);
    }

    // --- RBAC ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/support', SupportController::class, 'index', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_support_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(SupportController::class, $this->controller);

        return $fc;
    }

    public function testSuperadminIsAllowedThrough(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/config/support', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminIsDenied(): void
    {
        AuthSession::logout();
        AuthSession::login($this->userId, 'admin@test.example', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/support', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }
}
