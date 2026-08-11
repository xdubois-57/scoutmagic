<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\DeparturesController;
use Modules\Registration\Controller\ForecastController;
use Modules\Registration\Controller\PassageController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Twig\Environment;

/**
 * RBAC boundary for the "Départs"/"Passage"/"Prévisions" routes —
 * role_min: chief (the espace_chefs menu's own floor), one level below
 * (intendant) must be rejected.
 *
 * @group database
 */
class RegistrationChefsRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private DeparturesController $departuresController;
    private PassageController $passageController;
    private ForecastController $forecastController;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'D', 'd', 'registration');
        $settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'L', 'l', 'registration');
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);

        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $sectionStaffAuth = new SectionStaffAuthorizationService($connection, $encryption, $sectionService);
        $departureService = new DepartureService(new DepartureRepository($this->pdo, $encryption), new JournalService(new JournalRepository($this->pdo)));

        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $slotService = new SlotService($this->pdo, $encryption, $settingService, $ageBracketRepository, $slotCapacityRepository, $requestRepository);
        $transferRepository = new SectionTransferRepository($this->pdo);
        $passageService = new PassageService($this->pdo, $encryption, $sectionService, $transferRepository, $requestRepository, $ageBracketRepository);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->departuresController = new DeparturesController($twig, $sectionStaffAuth, $sectionService, $departureService, $scoutYearResolver);
        $this->passageController = new PassageController(
            $twig, $passageService, $requestRepository, $transferRepository, $sectionService,
            $ageBracketRepository, $slotService, $scoutYearResolver, $scoutYearService
        );
        $forecastService = new ForecastService($this->pdo, $encryption, $sectionService, $passageService);
        $this->forecastController = new ForecastController($twig, $forecastService, $scoutYearResolver, $scoutYearService, $slotService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'departs' => ['/departs', 'DeparturesController', 'index'],
            'passage' => ['/passage', 'PassageController', 'index'],
            'previsions' => ['/previsions', 'ForecastController', 'index'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    public function testChiefGetsPage(string $path, string $controllerName, string $action): void
    {
        AuthSession::login(1, 'chief@example.com', 'chief');

        $response = $this->dispatch($path, $controllerName, $action);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @dataProvider routeProvider
     */
    public function testIntendantIsRejected(string $path, string $controllerName, string $action): void
    {
        AuthSession::login(1, 'intendant@example.com', 'intendant');

        $response = $this->dispatch($path, $controllerName, $action);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function dispatch(string $path, string $controllerName, string $action): \Core\Http\Response
    {
        $router = new Router();
        $router->addRoute('GET', $path, "Modules\\Registration\\Controller\\{$controllerName}", $action, 'chief');

        $configFile = sys_get_temp_dir() . '/test_registration_chefs_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $controller = match ($controllerName) {
            'DeparturesController' => $this->departuresController,
            'PassageController' => $this->passageController,
            'ForecastController' => $this->forecastController,
        };
        $fc->registerController("Modules\\Registration\\Controller\\{$controllerName}", $controller);

        return $fc->handle(new Request('GET', $path, [], [], [], []));
    }
}
