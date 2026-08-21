<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Rental\Controller\RentalConfigController;
use Modules\Rental\Controller\RentalManagementController;
use Modules\Rental\Controller\RentalPublicController;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalSlugGenerator;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Twig\Environment;

/**
 * The route-level RBAC boundary, dispatched through the real Router and
 * FrontController so the guard under test is the one production uses.
 *
 * Two distinct boundaries live here, and they fail in opposite directions:
 *
 * - `/admin/locations*` is `admin`. One level below (`chief`) must be
 *   refused, or asset configuration leaks to every chief.
 * - `/mes-locations` is `identified` — deliberately as low as a logged-in
 *   visitor gets, because a manager need not be a chief. The route guard
 *   therefore grants nearly everything, and
 *   Service\RentalAuthorizationService is the real protection: an
 *   identified visitor who manages nothing must still be refused.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private RentalConfigController $configController;
    private RentalManagementController $managementController;
    private RentalPublicController $publicController;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->encryption = $encryption;

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(
            'asset_type_suggestions',
            'Local, Terrain',
            'text',
            'Types',
            'Types proposés.',
            'rental'
        );

        $scoutYearService = new ScoutYearService($this->pdo);
        // The controllers resolve the year through getCurrentYear(), which
        // derives its label from today's date — hardcoding a label here
        // would put the test's member_years rows in a DIFFERENT year from
        // the one the code under test looks in, and every manager would
        // silently resolve to nobody.
        $this->scoutYearId = $scoutYearService->getCurrentYear()['id'];

        $this->assetRepository = new RentalAssetRepository($this->pdo, $encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);

        $memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            $encryption,
            Connection::withPdo($this->pdo)
        );
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $authorizationService = new RentalAuthorizationService(
            $memberService,
            $this->assetRepository,
            $this->managerRepository
        );
        $assetService = new RentalAssetService(
            $this->assetRepository,
            new RentalSlugGenerator($this->assetRepository),
            $journalService
        );
        $managerService = new RentalManagerService($this->managerRepository, $memberService, $journalService);
        $pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journalService
        );

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );
        $this->twig->addGlobal('site_name', 'Unité Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/admin/locations');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->configController = new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            $assetService,
            $managerService,
            $memberService,
            $scoutYearService,
            $settingService,
            $pricingService
        );
        $this->managementController = new RentalManagementController(
            $this->twig,
            $authorizationService,
            $scoutYearService
        );
        $this->publicController = new RentalPublicController(
            $this->twig,
            $this->assetRepository,
            $authorizationService,
            $scoutYearService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function createAsset(string $name, string $slug, bool $isPublic = true): int
    {
        return $this->assetRepository->create('Local', $name, $slug, null, 1, null, null, null, $isPublic, false);
    }

    /**
     * @param array<string, string> $params
     */
    /**
     * @param array<string, string> $query
     */
    private function dispatch(
        string $routePath,
        string $requestPath,
        string $controllerClass,
        string $action,
        string $roleMin,
        array $query = []
    ): Response {
        $router = new Router();
        $router->addRoute('GET', $routePath, $controllerClass, $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_rental_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController($controllerClass, match ($controllerClass) {
            RentalConfigController::class => $this->configController,
            RentalManagementController::class => $this->managementController,
            \Modules\Rental\Controller\RentalPricingController::class => new \Modules\Rental\Controller\RentalPricingController(
                $this->twig,
                new RentalPricingService(
                    new RentalPricingRepository($this->pdo),
                    new RentalPricingEngine(),
                    new JournalService(new JournalRepository($this->pdo))
                )
            ),
            default => $this->publicController,
        });

        $response = $frontController->handle(new Request('GET', $requestPath, $query, [], [], []));
        @unlink($configFile);

        return $response;
    }

    private function dispatchConfig(): Response
    {
        return $this->dispatch('/admin/locations', '/admin/locations', RentalConfigController::class, 'index', 'admin');
    }

    private function dispatchMyRentals(): Response
    {
        return $this->dispatch('/mes-locations', '/mes-locations', RentalManagementController::class, 'myRentals', 'identified');
    }

    private function dispatchPublicAsset(string $slug): Response
    {
        return $this->dispatch('/locations/{slug}', '/locations/' . $slug, RentalPublicController::class, 'show', 'public');
    }

    // ── Configuration space: admin ──────────────────────────────────────

    public function testAdminReachesTheConfigurationPage(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatchConfig();

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testChiefIsRefusedTheConfigurationPage(): void
    {
        // One level below admin — the boundary that matters.
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(403, $this->dispatchConfig()->getStatusCode());
    }

    public function testAnIdentifiedVisitorIsRefusedTheConfigurationPage(): void
    {
        AuthSession::login(1, 'member@test.be', 'identified');

        $this->assertSame(403, $this->dispatchConfig()->getStatusCode());
    }

    public function testAnAnonymousVisitorIsRefusedTheConfigurationPage(): void
    {
        $this->assertContains($this->dispatchConfig()->getStatusCode(), [302, 401, 403]);
    }

    // ── Managed space: identified + per-asset authorization ─────────────

    public function testAnIdentifiedVisitorWhoManagesNothingIsRefusedMyRentals(): void
    {
        // The route guard lets them through — `identified` is the floor —
        // so this 403 comes from RentalAuthorizationService alone. If this
        // test ever goes green for the wrong reason, the managed space has
        // no protection left at all.
        $this->createAsset('Local', 'local');
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $this->assertSame(403, $this->dispatchMyRentals()->getStatusCode());
    }

    public function testAnAnonymousVisitorIsRefusedMyRentals(): void
    {
        $this->createAsset('Local', 'local');

        $this->assertContains($this->dispatchMyRentals()->getStatusCode(), [302, 401, 403]);
    }

    public function testAManagerReachesMyRentalsAndSeesOnlyTheirOwnAssets(): void
    {
        $mine = $this->createAsset('Mon local', 'mon-local');
        $this->createAsset('Local des autres', 'local-des-autres');

        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-MANAGER');
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, 'manager@test.be');
        $this->managerRepository->grant($mine, $memberId, false);

        AuthSession::login(1, 'manager@test.be', 'identified');
        $response = $this->dispatchMyRentals();

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Mon local', $body);
        $this->assertStringNotContainsString('Local des autres', $body);
    }

    // ── Public space ────────────────────────────────────────────────────

    public function testAnAnonymousVisitorReachesAPublicAssetPage(): void
    {
        $this->createAsset('Local Saint-Georges', 'local-saint-georges', isPublic: true);

        $response = $this->dispatchPublicAsset('local-saint-georges');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Local Saint-Georges', (string) $response->getBody());
    }

    public function testANonPublicAssetIsA404ForAnAnonymousVisitor(): void
    {
        // A 404, never a 403: telling an anonymous visitor "this exists but
        // you may not see it" is itself a disclosure.
        $this->createAsset('Local privé', 'local-prive', isPublic: false);

        $response = $this->dispatchPublicAsset('local-prive');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testANonPublicAssetStaysReachableByItsOwnManager(): void
    {
        $asset = $this->createAsset('Local privé', 'local-prive', isPublic: false);
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-MANAGER');
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, 'manager@test.be');
        $this->managerRepository->grant($asset, $memberId, false);

        AuthSession::login(1, 'manager@test.be', 'identified');
        $response = $this->dispatchPublicAsset('local-prive');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAnUnknownSlugIs404(): void
    {
        $this->assertSame(404, $this->dispatchPublicAsset('nope')->getStatusCode());
    }

    public function testThePublicAssetPageNeverRendersTheEmergencyPhone(): void
    {
        // §6.6: the emergency number is for the renter's own tracking page
        // only. A public page that leaked it would be a real disclosure.
        $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            null,
            1,
            null,
            null,
            '+32 470 12 34 56',
            true,
            false
        );

        $body = (string) $this->dispatchPublicAsset('local-saint-georges')->getBody();

        $this->assertStringNotContainsString('470', $body);
        $this->assertStringNotContainsString('+32', $body);
    }

    public function testTheManageButtonIsHiddenFromAVisitorWhoCannotManage(): void
    {
        // Presentation only — the managed space re-checks server-side — but
        // showing it to everyone would be a confusing dead end.
        $this->createAsset('Local', 'local');

        $body = (string) $this->dispatchPublicAsset('local')->getBody();

        $this->assertStringNotContainsString('Gérer ce bien', $body);
    }

    // ── The pricing section renders, and the simulator is the real engine ──

    public function testTheConfigurationPageRendersTheBillingUnitSelectorAndItsExplanation(): void
    {
        // §6.8 requires the explanatory text under the selector; it is
        // rendered server-side from BillingUnit so the page is already
        // correct before any JavaScript runs.
        $this->createAsset('Local', 'local');
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatchConfig()->getBody();

        $this->assertStringContainsString('Unité de facturation', $body);
        $this->assertStringContainsString('Par personne et par nuit', $body);
        $this->assertStringContainsString('jour de départ reste disponible', $body);
        $this->assertStringContainsString('data-explanation', $body);
        $this->assertStringContainsString('Minimum facturable', $body);
        $this->assertStringContainsString('Simulateur', $body);
    }

    public function testTheSimulatorRendersARealBreakdownFromTheStoredTariff(): void
    {
        // End to end: a tariff saved through the service, priced by the same
        // engine the public page uses, rendered on the configuration screen.
        $assetId = $this->createAsset('Local', 'local');
        $pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            new JournalService(new JournalRepository($this->pdo))
        );
        $pricingService->saveAssetPricing($assetId, 'per_person_night', 250, null, 25);
        $pricingService->addFee($assetId, 'Nettoyage', \Modules\Rental\Pricing\RentalFee::NATURE_FIXED, 5000, null);

        AuthSession::login(1, 'admin@test.be', 'admin');
        $response = $this->dispatch(
            '/admin/locations',
            '/admin/locations',
            RentalConfigController::class,
            'index',
            'admin',
            [
                'asset_id' => (string) $assetId,
                'sim_arrival' => '2027-07-16',
                'sim_departure' => '2027-07-19',
                'sim_persons' => '18',
            ]
        );
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        // 25 × 3 × 2,50 € = 187,50 €, plus 50,00 € cleaning = 237,50 €.
        $this->assertStringContainsString('25 pers. (minimum) × 3 nuits', $body);
        $this->assertStringContainsString('237,50', $body);
        $this->assertStringContainsString('minimum appliqué', $body);
    }

    public function testTheSimulatorShowsNothingUntilDatesAreGiven(): void
    {
        $this->createAsset('Local', 'local');
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatchConfig()->getBody();

        $this->assertStringContainsString('Simulateur', $body);
        $this->assertStringNotContainsString('Détail du prix simulé', $body);
    }

    public function testAChiefCannotReachThePricingActions(): void
    {
        // Same admin boundary as the rest of the configuration space.
        $this->createAsset('Local', 'local');
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->dispatch(
            '/admin/locations/pricing',
            '/admin/locations/pricing',
            \Modules\Rental\Controller\RentalPricingController::class,
            'savePricing',
            'admin'
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheManageButtonIsShownToAManager(): void
    {
        $asset = $this->createAsset('Local', 'local');
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-MANAGER');
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, 'manager@test.be');
        $this->managerRepository->grant($asset, $memberId, false);

        AuthSession::login(1, 'manager@test.be', 'identified');
        $body = (string) $this->dispatchPublicAsset('local')->getBody();

        $this->assertStringContainsString('Gérer ce bien', $body);
    }
}
