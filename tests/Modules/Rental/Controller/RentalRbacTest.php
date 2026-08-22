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
use Core\View\MonthGrid\DayStateGridBuilder;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Service\RentalAvailabilityService;
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
        $availabilityService = $this->availabilityServiceFor();
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
            $pricingService,
            $availabilityService
        );
        $bookingRepository = new \Modules\Rental\Repository\RentalBookingRepository($this->pdo, $encryption);
        $eventRepository = new \Modules\Rental\Repository\RentalBookingEventRepository($this->pdo);
        $commentRepository = new \Modules\Rental\Repository\RentalBookingCommentRepository($this->pdo, $encryption);
        $changeRequestRepository = new \Modules\Rental\Repository\RentalChangeRequestRepository($this->pdo, $encryption);
        $blockRepository = new \Modules\Rental\Repository\RentalBlockRepository($this->pdo);

        $this->managementController = new RentalManagementController(
            $this->twig,
            $authorizationService,
            $scoutYearService,
            $this->assetRepository,
            $bookingRepository,
            $eventRepository,
            $commentRepository,
            $changeRequestRepository,
            new \Modules\Rental\Service\RentalOperationsService(
                $bookingRepository,
                $eventRepository,
                $commentRepository,
                $changeRequestRepository,
                $availabilityService,
                $pricingService,
                new \Modules\Rental\Pricing\QuoteEditor(),
                $journalService
            ),
            new \Modules\Rental\Service\RentalBlockService($blockRepository, $journalService),
            $availabilityService,
            $pricingService,
            $memberService,
            new DayStateGridBuilder()
        );
        $this->publicController = new RentalPublicController(
            $this->twig,
            $this->assetRepository,
            $authorizationService,
            $scoutYearService,
            $availabilityService,
            $pricingService,
            new DayStateGridBuilder()
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
        return $this->assetRepository->create('Local', $name, $slug, null, 1, null, null, null, $isPublic);
    }

    private function pricingServiceFor(): RentalPricingService
    {
        return new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function availabilityServiceFor(): RentalAvailabilityService
    {
        return new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            // No occupancy providers: bookings and manual blocks arrive in
            // later iterations, so every asset reads as entirely free here.
            []
        );
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
                $this->pricingServiceFor(),
                $this->availabilityServiceFor()
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

    private function dispatchAssetFragment(string $slug): Response
    {
        return $this->dispatch(
            '/locations/{slug}/apercu',
            '/locations/' . $slug . '/apercu',
            RentalPublicController::class,
            'fragment',
            'public'
        );
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

    public function testTheFragmentEndpointAnswersWithBothBlocks(): void
    {
        // What a calendar tap and « Estimer » fetch instead of reloading the
        // whole page: the same two Twig partials the page itself renders.
        $this->createAsset('Local Saint-Georges', 'local-saint-georges', isPublic: true);

        $response = $this->dispatchAssetFragment('local-saint-georges');

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('calendar', $payload);
        $this->assertArrayHasKey('estimate', $payload);
        $this->assertStringContainsString('rental-calendar', (string) $payload['calendar']);
        $this->assertStringContainsString('Votre séjour', (string) $payload['estimate']);
    }

    public function testTheFragmentEndpointRefusesANonPublicAssetTheSameWayThePageDoes(): void
    {
        // A fragment endpoint that trusted its caller would be a way around
        // the page's own rules — the exact shape of bug an "internal" AJAX
        // route invites.
        $this->createAsset('Local privé', 'local-prive', isPublic: false);

        $this->assertSame(404, $this->dispatchAssetFragment('local-prive')->getStatusCode());
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

    // ── The public calendar (§6.7) ──────────────────────────────────────

    public function testThePublicPageRendersAnAvailabilityCalendar(): void
    {
        $this->createAsset('Local Saint-Georges', 'local-saint-georges');

        $body = (string) $this->dispatchPublicAsset('local-saint-georges')->getBody();

        $this->assertStringContainsString('daygrid', $body);
        $this->assertStringContainsString('data-date=', $body);
        $this->assertStringContainsString('Lun', $body, 'The grid is Monday-first.');
        $this->assertStringContainsString('id="rental-calendar"', $body);
    }

    public function testTheCalendarCannotBePagedIntoThePastEvenViaTheQueryString(): void
    {
        // §6.7: a visitor can never go into the past. Clamping in the
        // controller — not just hiding the arrow — is what makes that true,
        // since the month is a query parameter.
        $this->createAsset('Local', 'local');
        $currentMonth = (new \DateTimeImmutable('today'))->format('Y-m');

        $response = $this->dispatch(
            '/locations/{slug}',
            '/locations/local',
            RentalPublicController::class,
            'show',
            'public',
            ['month' => '2020-01']
        );
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-month="' . $currentMonth . '"', $body);
        $this->assertStringNotContainsString('data-month="2020-01"', $body);
    }

    public function testThePreviousMonthArrowIsDisabledAtTheCurrentMonth(): void
    {
        $this->createAsset('Local', 'local');

        $body = (string) $this->dispatchPublicAsset('local')->getBody();

        // Disabled rather than absent: the greyed arrow says "you cannot go
        // back" instead of leaving the visitor hunting for a missing control.
        $this->assertStringContainsString('Mois précédent (indisponible)', $body);
    }

    public function testAFutureMonthCanBeReachedAndOffersAWayBack(): void
    {
        $this->createAsset('Local', 'local');
        $nextMonth = (new \DateTimeImmutable('first day of next month'))->format('Y-m');

        $body = (string) $this->dispatch(
            '/locations/{slug}',
            '/locations/local',
            RentalPublicController::class,
            'show',
            'public',
            ['month' => $nextMonth]
        )->getBody();

        $this->assertStringContainsString('data-month="' . $nextMonth . '"', $body);
        $this->assertStringContainsString('aria-label="Mois précédent"', $body);
    }

    public function testTheCalendarExplainsTheNightsOrDaysConsequence(): void
    {
        // §6.8's explanatory text, on the public page too — a visitor needs
        // to know whether the departure day is theirs.
        $assetId = $this->createAsset('Local', 'local');
        $this->pricingServiceFor()->saveAssetPricing($assetId, 'per_night', 8000, null, null);

        $body = (string) $this->dispatchPublicAsset('local')->getBody();

        $this->assertStringContainsString('jour de départ reste disponible', $body);
    }

    public function testSelectingARangeProducesAPriceEstimateFromTheSameEngine(): void
    {
        // The iteration's acceptance criterion: a visitor picks a valid range
        // and gets a correct estimate, without sending a request yet.
        $assetId = $this->createAsset('Local', 'local');
        $this->pricingServiceFor()->saveAssetPricing($assetId, 'per_person_night', 250, null, null);

        $arrival = (new \DateTimeImmutable('today'))->modify('+30 days');
        $departure = $arrival->modify('+3 days');

        $body = (string) $this->dispatch(
            '/locations/{slug}',
            '/locations/local',
            RentalPublicController::class,
            'show',
            'public',
            [
                'arrival' => $arrival->format('Y-m-d'),
                'departure' => $departure->format('Y-m-d'),
                'persons' => '20',
                'month' => $arrival->format('Y-m'),
            ]
        )->getBody();

        // 20 people × 3 nights × 2,50 € = 150,00 €.
        $this->assertStringContainsString('20 pers. × 3 nuits', $body);
        $this->assertStringContainsString('150,00', $body);
        $this->assertStringContainsString('Total estimé', $body);
    }

    public function testAnIncompleteEstimateIsPresentedAsAStartingPriceNotAFirmOne(): void
    {
        // §6.7's "dès X €": with no category chosen the quote is incomplete,
        // and the page must not show a number it would have to walk back.
        $assetId = $this->createAsset('Local', 'local');
        $pricingService = $this->pricingServiceFor();
        $pricingService->saveAssetPricing($assetId, 'per_night', 8000, null, null);
        $pricingService->addCategory($assetId, 'Mouvement de jeunesse', true);

        $arrival = (new \DateTimeImmutable('today'))->modify('+30 days');

        $body = (string) $this->dispatch(
            '/locations/{slug}',
            '/locations/local',
            RentalPublicController::class,
            'show',
            'public',
            [
                'arrival' => $arrival->format('Y-m-d'),
                'departure' => $arrival->modify('+2 days')->format('Y-m-d'),
                'month' => $arrival->format('Y-m'),
            ]
        )->getBody();

        $this->assertStringContainsString('Dès', $body);
        $this->assertStringNotContainsString('Total estimé', $body);
    }

    public function testARangeViolatingAConstraintIsReportedWithoutClaimingUnavailability(): void
    {
        // A notice-period refusal must never read as "occupé" (§6.7).
        $assetId = $this->createAsset('Local', 'local');
        $this->pricingServiceFor()->saveAssetPricing($assetId, 'per_night', 8000, null, null);
        $this->availabilityServiceFor()->saveConstraints($assetId, 0, 0, 60, 0, [], null, 0);

        $arrival = (new \DateTimeImmutable('today'))->modify('+5 days');

        $body = (string) $this->dispatch(
            '/locations/{slug}',
            '/locations/local',
            RentalPublicController::class,
            'show',
            'public',
            [
                'arrival' => $arrival->format('Y-m-d'),
                'departure' => $arrival->modify('+2 days')->format('Y-m-d'),
                'month' => $arrival->format('Y-m'),
            ]
        )->getBody();

        // Twig escapes the apostrophe, so the rendered text is
        // "60 jours à l&#039;avance" — assert on the parts either side of it
        // rather than on a literal that only matches before escaping.
        $this->assertStringContainsString('60 jours à l', $body);
        $this->assertStringContainsString('avance', $body);
        $this->assertStringNotContainsString("n'est pas disponible", $body);
        $this->assertStringNotContainsString('est pas disponible', $body);
    }

    public function testTheNoticeWindowIsGreyedOutRatherThanShownAsOccupied(): void
    {
        $assetId = $this->createAsset('Local', 'local');
        $this->availabilityServiceFor()->saveConstraints($assetId, 0, 0, 60, 0, [], null, 0);

        $body = (string) $this->dispatchPublicAsset('local')->getBody();

        $this->assertStringContainsString('Trop tôt pour réserver', $body);
        $this->assertStringNotContainsString('Occupé', $body, 'A free day must never be labelled taken.');
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
            true
        );

        $body = (string) $this->dispatchPublicAsset('local-saint-georges')->getBody();

        // Distinctive fragments of the number, never the bare '470' or
        // '+32' this used to look for: the page carries a CSP nonce and a
        // CSRF token, both random base64, and a run whose token happened to
        // contain "470" failed a test about an emergency phone. A flake in a
        // disclosure test is worse than useless — it teaches everybody to
        // re-run it.
        $this->assertStringNotContainsString('+32 470 12 34 56', $body);
        $this->assertStringNotContainsString('470 12 34', $body);
        $this->assertStringNotContainsString('12 34 56', $body);
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
