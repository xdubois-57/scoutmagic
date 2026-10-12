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
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\RegistrationConfigController;
use Modules\Registration\Controller\RegistrationRequestController;
use Modules\Registration\Controller\RequestDecisionController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * RBAC boundary for the iteration-5 staff routes and the sub-pages issue
 * #843 split the management page into — role_min: admin (the espace_admin
 * menu's own floor), one level below (chief) must be rejected. Mirrors
 * Tests\Modules\Finance\Controller\FinanceRbacTest's pattern, with one
 * difference: the router is loaded from the module's real manifest — every
 * route, its `role_min` and its breadcrumb as declared — so a route
 * declared a rung too low, or shadowed by another, fails here.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private RegistrationConfigController $configController;
    private RegistrationRequestController $requestController;
    private RequestDecisionController $decisionController;
    private RegistrationRequestRepository $requestRepository;
    private SettingService $settingService;
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
        $settingService->register('registration_threshold_yes_percent', '70', 'number', 'Oui', 'desc', 'registration');
        $settingService->register(
            'registration_threshold_maybe_percent',
            '100',
            'number',
            'Peut-être',
            'desc',
            'registration'
        );
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register('registration_waitlist_enabled', '1', 'boolean', 'Listes', 'desc', 'registration');
        $settingService->register(
            'registration_priority_order',
            '',
            'text',
            'Priorités',
            'desc',
            'registration',
            null,
            null,
            false
        );
        $this->settingService = $settingService;

        $scoutYearService = new ScoutYearService($this->pdo);
        $currentYearId = $scoutYearService->ensureYear('2026-2027');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $slotService = RegistrationTestHelper::slotService($this->pdo, $encryption, $settingService);
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
);
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $statusService = new RequestStatusService($requestRepository, $journalService);
        $feeCategoryRepository = new FeeCategoryRepository($this->pdo);
        $feeEstimationService = new FeeEstimationService(new FeeEstimationRepository($this->pdo, $encryption), null);

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

        $decisionService = RegistrationTestHelper::decisionService(
            $this->pdo,
            $encryption,
            $settingService,
            $statusService,
            new \Modules\Registration\Service\RequestEmailService(
                $requestRepository,
                $this->createStub(\Core\Mail\MailService::class),
                $editableContentService,
                $journalService,
                'https://example.com',
                'Unité Test',
                new \Modules\Registration\Service\DecisionEmailService(
                    new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo),
                    $journalService
                )
            )
        );
        $this->decisionController = new RequestDecisionController($twig, $requestRepository, $decisionService);
        $this->requestRepository = $requestRepository;
        $this->configController = new RegistrationConfigController(
            $twig, $ageBracketRepository, $slotCapacityRepository, new RegistrationYearCodeRepository($this->pdo),
            $scoutYearResolver, $scoutYearService, $requestRepository, $slotService,
            $sectionService, $editableContentService, $statusService, $decisionService, $journalService,
            $settingService,
            new \Modules\Registration\Service\RequestExportService(),
            new \Modules\Registration\Service\PriorityOrderService($settingService)
        );
        $this->requestController = new RegistrationRequestController(
            $twig, $requestRepository, $ageBracketRepository, $sectionService, $feeCategoryRepository, $feeEstimationService,
            $statusService, $this->createStub(\Modules\Registration\Service\RequestEmailService::class),
            $this->createStub(\Modules\Registration\Service\MigrationService::class),
            new MemberRepository($this->pdo), new MemberYearRepository($this->pdo), $scoutYearResolver, $scoutYearService, $slotService,
            new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $encryption)
)
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
            'dashboard' => ['/config/inscriptions', 'RegistrationConfigController', 'index'],
            'requests' => ['/config/inscriptions/demandes', 'RegistrationConfigController', 'requests'],
            'settings' => ['/config/inscriptions/reglages', 'RegistrationConfigController', 'settings'],
            // The export takes the role of the page it lives on, never a
            // rung below it — the requests it writes out are the same
            // ones that page renders.
            'export' => ['/config/inscriptions/export', 'RegistrationConfigController', 'export'],
            'fiche' => ['/config/inscriptions/demandes/{id}', 'RegistrationRequestController', 'show'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testAdminGetsPage(string $path, string $controllerName, string $action): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch($path, $controllerName, $action);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testOneRoleBelowIsRejected(string $path, string $controllerName, string $action): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->dispatch($path, $controllerName, $action);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The three saves issue #843 (IT-03) added to the « Réglages » sub-page:
     * the waitlist switch (JSON, saved on change), the thresholds and the
     * priorities. Each carries a valid CSRF token, so what answers is the
     * role check and nothing else.
     *
     * @return array<string, array{string, string}>
     */
    public static function settingsPostProvider(): array
    {
        return [
            'waitlist' => ['/config/inscriptions/listes-attente', 'saveWaitlist'],
            'thresholds' => ['/config/inscriptions/seuils', 'saveThresholds'],
            'priorities' => ['/config/inscriptions/priorites', 'savePriorities'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('settingsPostProvider')]
    public function testAdminMaySaveTheSettings(string $path, string $action): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatchSettingsPost($path, $action);

        $this->assertContains($response->getStatusCode(), [200, 302], (string) $response->getBody());
        // The save went through, which a redirect alone would not prove: a
        // refused token redirects too.
        match ($action) {
            'saveWaitlist' => $this->assertSame(
                '0',
                $this->settingService->get('registration_waitlist_enabled', 'registration', '')
            ),
            'saveThresholds' => $this->assertSame(
                '60',
                $this->settingService->get('registration_threshold_yes_percent', 'registration', '')
            ),
            default => $this->assertSame(
                '{"always":[],"rank":["arrival","previous_no_room"],"off":["sibling"]}',
                $this->settingService->get('registration_priority_order', 'registration', '')
            ),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('settingsPostProvider')]
    public function testOneRoleBelowMayNotSaveTheSettings(string $path, string $action): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->dispatchSettingsPost($path, $action);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('1', $this->settingService->get('registration_waitlist_enabled', 'registration', ''));
        $this->assertSame('', $this->settingService->get('registration_priority_order', 'registration', ''));
    }

    /**
     * The decision dialog's route (issue #843, IT-04) takes the role of the
     * list it lives on: an admin decides — the request is accepted — and a
     * chief, one rung below, is refused before anything changes. A valid
     * token on both, so what answers is the role check.
     */
    public function testAdminMayDecideFromTheDialog(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatchDecisionPost();

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('accepted', $this->requestRepository->findById($this->requestId)?->status);
        $this->assertSame('Note du dialogue', $this->requestRepository->findById($this->requestId)?->internalNotes);
    }

    public function testOneRoleBelowMayNotDecideFromTheDialog(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->dispatchDecisionPost();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('pending', $this->requestRepository->findById($this->requestId)?->status);
        $this->assertNull($this->requestRepository->findById($this->requestId)?->internalNotes);
    }

    /**
     * The two pages issue #843 gave a full breadcrumb: a sub-page hangs
     * under « Formulaire d'inscription », the fiche under « Demandes » too,
     * each a real link (design.md §7.3), the menu label at the root.
     */
    public function testTheSubPagesAndTheFicheCarryTheirFullBreadcrumb(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $requests = (string) $this->dispatch(
            '/config/inscriptions/demandes',
            'RegistrationConfigController',
            'requests'
        )->getBody();
        $fiche = (string) $this->dispatch(
            '/config/inscriptions/demandes/{id}',
            'RegistrationRequestController',
            'show'
        )->getBody();

        foreach ([$requests, $fiche] as $body) {
            $this->assertStringContainsString("Espace chefs d&#039;U", $body);
            $this->assertMatchesRegularExpression(
                '#<a href="/config/inscriptions" class="text-decoration-none">Formulaire d&\#039;inscription</a>#',
                $body
            );
        }
        $this->assertMatchesRegularExpression('#aria-current="page">Demandes</li>#', $requests);
        $this->assertMatchesRegularExpression(
            '#<a href="/config/inscriptions/demandes" class="text-decoration-none">Demandes</a>#',
            $fiche
        );
        $this->assertMatchesRegularExpression('#aria-current="page">Demande \#' . $this->requestId . '</li>#', $fiche);
    }

    /**
     * `/config/inscriptions/demandes` and `/config/inscriptions/demandes/{id}`
     * both resolve to their own action, through the manifest's own order.
     */
    public function testTheRequestListAndTheFicheDoNotShadowEachOther(): void
    {
        $router = $this->manifestRouter();

        $list = $router->resolve(new Request('GET', '/config/inscriptions/demandes', [], [], [], []));
        $fiche = $router->resolve(new Request('GET', '/config/inscriptions/demandes/42', [], [], [], []));

        $this->assertNotNull($list);
        $this->assertSame('requests', $list->action);
        $this->assertNotNull($fiche);
        $this->assertSame('show', $fiche->action);
        $this->assertSame(['id' => '42'], $fiche->params);
    }

    /**
     * Every route of the module, as its manifest declares it.
     */
    private function manifestRouter(): Router
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $router = new Router();
        foreach ($manifest['routes'] as $route) {
            $router->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min'],
                $route['breadcrumb'] ?? null,
                'registration'
            );
        }

        return $router;
    }

    /**
     * POSTs one of the settings saves through the manifest's router and the
     * real front controller, with a valid token and a body that would be
     * accepted: switching waitlists off, a valid threshold pair, the
     * default priorities moved around.
     */
    private function dispatchSettingsPost(string $path, string $action): \Core\Http\Response
    {
        $token = \Core\Security\CsrfGuard::generateToken();
        $body = [
            '_csrf_token' => $token,
            'threshold_yes_percent' => '60',
            'threshold_maybe_percent' => '90',
            'priority' => ['always' => [], 'rank' => ['arrival', 'previous_no_room'], 'off' => ['sibling']],
        ];
        $request = new \Tests\RequestWithInput(
            'POST',
            $path,
            [],
            $body,
            [],
            [],
            json_encode(['_csrf_token' => $token, 'enabled' => false])
        );

        $router = $this->manifestRouter();
        $resolved = $router->resolve($request);
        $this->assertNotNull($resolved, "the manifest declares POST {$path}");
        $this->assertSame(RegistrationConfigController::class, $resolved->controllerClass);
        $this->assertSame($action, $resolved->action);

        $configFile = sys_get_temp_dir() . '/test_registration_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $fc = new FrontController($router, $this->twig, new AppConfig($configFile));
        $fc->registerController(RegistrationConfigController::class, $this->configController);

        return $fc->handle($request);
    }

    /**
     * POSTs an acceptance from the decision dialog through the manifest's
     * router and the real front controller.
     */
    private function dispatchDecisionPost(): \Core\Http\Response
    {
        $path = '/config/inscriptions/demandes/' . $this->requestId . '/decision';
        $request = new Request('POST', $path, [], [
            '_csrf_token' => \Core\Security\CsrfGuard::generateToken(),
            'decision' => 'accept',
            'internal_notes' => 'Note du dialogue',
        ], [], []);

        $router = $this->manifestRouter();
        $resolved = $router->resolve($request);
        $this->assertNotNull($resolved, 'the manifest declares POST /config/inscriptions/demandes/{id}/decision');
        $this->assertSame(RequestDecisionController::class, $resolved->controllerClass);
        $this->assertSame('decide', $resolved->action);

        $configFile = sys_get_temp_dir() . '/test_registration_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $fc = new FrontController($router, $this->twig, new AppConfig($configFile));
        $fc->registerController(RequestDecisionController::class, $this->decisionController);

        return $fc->handle($request);
    }

    private function dispatch(string $path, string $controllerName, string $action): \Core\Http\Response
    {
        $resolvedPath = str_replace('{id}', (string) $this->requestId, $path);

        $router = $this->manifestRouter();
        $resolved = $router->resolve(new Request('GET', $resolvedPath, [], [], [], []));
        $this->assertNotNull($resolved, "the manifest declares GET {$path}");
        $this->assertSame("Modules\\Registration\\Controller\\{$controllerName}", $resolved->controllerClass);
        $this->assertSame($action, $resolved->action);

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
