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
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\FeeEstimationRepository;
use Core\Member\FeeEstimationService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\RegistrationConfigController;
use Modules\Registration\Controller\RegistrationRequestController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Twig\Environment;

/**
 * RBAC boundary for the iteration-5 staff routes — role_min: admin (the
 * espace_admin menu's own floor), one level below (chief) must be
 * rejected. Mirrors Tests\Modules\Finance\Controller\FinanceRbacTest's
 * pattern.
 *
 * @group database
 */
class RegistrationRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private RegistrationConfigController $configController;
    private RegistrationRequestController $requestController;
    private int $requestId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'Dispo', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'Limité', 'desc', 'registration');
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $currentYearId = $scoutYearService->ensureYear('2026-2027');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $slotService = new SlotService($this->pdo, $encryption, $settingService, $ageBracketRepository, $slotCapacityRepository, $requestRepository);
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $statusService = new RequestStatusService($requestRepository, $journalService);
        $feeCategoryRepository = new FeeCategoryRepository($this->pdo);
        $feeEstimationService = new FeeEstimationService(new FeeEstimationRepository($this->pdo), $encryption, null);

        $created = $requestRepository->create($currentYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestId = $created['id'];

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/inscriptions');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->configController = new RegistrationConfigController(
            $twig, $ageBracketRepository, $slotCapacityRepository, new RegistrationYearCodeRepository($this->pdo),
            $scoutYearResolver, $scoutYearService, $requestRepository, $slotService,
            $sectionService, $editableContentService, $statusService, $journalService
        );
        $this->requestController = new RegistrationRequestController(
            $twig, $requestRepository, $ageBracketRepository, $sectionService, $feeCategoryRepository, $feeEstimationService,
            $statusService, $this->createMock(\Modules\Registration\Service\RequestEmailService::class),
            $this->createMock(\Modules\Registration\Service\MigrationService::class),
            new MemberRepository($this->pdo), new MemberYearRepository($this->pdo), $scoutYearResolver, $scoutYearService, $slotService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'management page' => ['/config/inscriptions', 'RegistrationConfigController', 'index'],
            'fiche' => ['/config/inscriptions/demandes/{id}', 'RegistrationRequestController', 'show'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    public function testAdminGetsPage(string $path, string $controllerName, string $action): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch($path, $controllerName, $action);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @dataProvider routeProvider
     */
    public function testOneRoleBelowIsRejected(string $path, string $controllerName, string $action): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->dispatch($path, $controllerName, $action);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function dispatch(string $path, string $controllerName, string $action): \Core\Http\Response
    {
        $resolvedPath = str_replace('{id}', (string) $this->requestId, $path);

        $router = new Router();
        $router->addRoute('GET', $path, "Modules\\Registration\\Controller\\{$controllerName}", $action, 'admin');

        $configFile = sys_get_temp_dir() . '/test_registration_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(
            "Modules\\Registration\\Controller\\{$controllerName}",
            $controllerName === 'RegistrationConfigController' ? $this->configController : $this->requestController
        );

        return $fc->handle(new Request('GET', $resolvedPath, [], [], [], []));
    }
}
