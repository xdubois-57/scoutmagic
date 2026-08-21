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
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\MonthGrid\DayStateGridBuilder;
use Core\View\TwigFactory;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Controller\RentalManagementController;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingEventRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBlockService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Twig\Environment;

/**
 * The managed space, dispatched through the real Router and FrontController.
 *
 * The point of this file is the **authorisation matrix**, because the route
 * guard here is deliberately almost nothing: every route is `identified`,
 * since a manager need not be a chief (§6.3). What actually protects the
 * data is `RentalAuthorizationService`, re-checked at the top of every
 * action — so an identified visitor who manages nothing, and a manager of
 * one asset reaching for another, are the two cases that matter most.
 *
 * The second theme is what must never cross the boundary the other way:
 * internal comments are written here and must be absent from every renter-
 * facing surface.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalManagementControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private RentalManagementController $controller;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalChangeRequestRepository $changeRequestRepository;
    private RentalBookingCommentRepository $commentRepository;
    private RentalBlockRepository $blockRepository;
    private RentalOperationsService $operationsService;
    private RentalPricingService $pricingService;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $assetId;
    private int $otherAssetId;
    private string $storagePath;
    private \Core\File\FileRepository $fileRepository;
    private \Modules\Rental\Service\RentalDocumentService $documentService;
    private \Modules\Rental\Service\RentalStayService $stayService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        // Derived from today's date, exactly as the controller does — a
        // hardcoded label would put the managers in a different year from
        // the one the code looks in, and every one of them would resolve to
        // nobody.
        $this->scoutYearId = $scoutYearService->getCurrentYear()['id'];

        $connection = Connection::withPdo($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->changeRequestRepository = new RentalChangeRequestRepository($this->pdo, $this->encryption);
        $this->commentRepository = new RentalBookingCommentRepository($this->pdo, $this->encryption);
        $this->blockRepository = new RentalBlockRepository($this->pdo);
        $eventRepository = new RentalBookingEventRepository($this->pdo);

        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );
        $availabilityService = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [new RentalBookingService($this->bookingRepository, $journal), $this->blockRepository]
        );
        $this->stayService = new \Modules\Rental\Service\RentalStayService(
            new \Modules\Rental\Repository\RentalStayRepository($this->pdo, $this->encryption),
            $eventRepository,
            $this->pricingService,
            new \Modules\Rental\Stay\SettlementCalculator(),
            $journal
        );

        $this->operationsService = new RentalOperationsService(
            $this->bookingRepository,
            $eventRepository,
            $this->commentRepository,
            $this->changeRequestRepository,
            $availabilityService,
            $this->pricingService,
            new QuoteEditor(),
            $journal,
            null,
            // Wired exactly as public/index.php does, so the inventory
            // snapshot at confirmation is genuinely covered here rather
            // than only in production.
            $this->stayService
        );

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );
        $this->twig->addGlobal('site_name', 'Unité Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'identified');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/mes-locations');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->storagePath = sys_get_temp_dir() . '/rental_mgmt_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0755, true);
        $this->fileRepository = new \Core\File\FileRepository($this->pdo);
        $this->documentService = new \Modules\Rental\Service\RentalDocumentService(
            new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
            $this->bookingRepository,
            $eventRepository,
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            $this->fileRepository,
            new \Core\Pdf\DocumentPdfService(),
            new \Core\Security\HtmlSanitizer(),
            $settingService,
            $journal,
            $this->storagePath
        );

        $this->controller = new RentalManagementController(
            $this->twig,
            new RentalAuthorizationService($memberService, $this->assetRepository, $this->managerRepository),
            $scoutYearService,
            $this->assetRepository,
            $this->bookingRepository,
            $eventRepository,
            $this->commentRepository,
            $this->changeRequestRepository,
            $this->operationsService,
            new RentalBlockService($this->blockRepository, $journal),
            $availabilityService,
            $this->pricingService,
            $memberService,
            new DayStateGridBuilder(),
            null,
            $this->documentService,
            $this->createMock(\Modules\Rental\Service\RentalBookingMailService::class),
            new \Core\File\UploadHandler($this->fileRepository, $this->storagePath),
            $this->stayService
        );

        $this->assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');
        $this->otherAssetId = $this->createAsset('Local des autres', 'local-des-autres');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_POST = [];

        // The document tests write real PDFs; leaving them behind would
        // fill the runner's temp directory over a full suite.
        foreach (glob($this->storagePath . '/rental/documents/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/rental/documents');
        @rmdir($this->storagePath . '/rental');
        @rmdir($this->storagePath);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function createAsset(string $name, string $slug): int
    {
        $id = $this->assetRepository->create('Local', $name, $slug, 60, 1, null, null, null, true, false);
        $this->pricingService->saveAssetPricing($id, 'per_night', 12000, null, null);

        return $id;
    }

    private function addManager(int $assetId, string $email): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email . $assetId), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($assetId, $memberId, false);

        return $memberId;
    }

    private function createBooking(?int $assetId = null, string $reference = 'LOC-2027-0001'): RentalBooking
    {
        $created = $this->bookingRepository->create(
            $assetId ?? $this->assetId,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    // ── Dispatch helpers ────────────────────────────────────────────────

    /**
     * @param array<string, string> $query
     */
    private function get(string $routePath, string $requestPath, string $action, array $query = []): Response
    {
        $router = new Router();
        $router->addRoute('GET', $routePath, RentalManagementController::class, $action, 'identified');

        return $this->dispatch($router, new Request('GET', $requestPath, $query, [], [], []));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, string $action, array $body): Response
    {
        $body['_csrf_token'] ??= CsrfGuard::generateToken();
        $_POST = $body;

        $router = new Router();
        $router->addRoute('POST', $path, RentalManagementController::class, $action, 'identified');

        return $this->dispatch($router, new Request('POST', $path, [], $body, [], []));
    }

    private function dispatch(Router $router, Request $request): Response
    {
        $configFile = sys_get_temp_dir() . '/test_rental_mgmt_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(RentalManagementController::class, $this->controller);
        $response = $frontController->handle($request);
        @unlink($configFile);

        return $response;
    }

    private function overview(string $slug): Response
    {
        return $this->get('/mes-locations/{slug}', '/mes-locations/' . $slug, 'overview');
    }

    private function bookingPage(string $slug, int $bookingId): Response
    {
        return $this->get(
            '/mes-locations/{slug}/reservations/{id}',
            '/mes-locations/' . $slug . '/reservations/' . $bookingId,
            'booking'
        );
    }

    // ── The authorisation matrix ────────────────────────────────────────

    public function testAnAnonymousVisitorIsRefusedTheManagedSpace(): void
    {
        $this->assertContains($this->overview('local-saint-georges')->getStatusCode(), [302, 401, 403]);
    }

    public function testAnIdentifiedVisitorWhoManagesNothingGetsA404(): void
    {
        // A 404 and not a 403: "this exists but is not yours" is itself a
        // disclosure about the unit's assets (§6.6).
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $this->assertSame(404, $this->overview('local-saint-georges')->getStatusCode());
    }

    public function testAManagerReachesTheirOwnAssetsSpace(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $response = $this->overview('local-saint-georges');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Local Saint-Georges', (string) $response->getBody());
    }

    public function testAManagerOfOneAssetCannotReachAnother(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $this->assertSame(404, $this->overview('local-des-autres')->getStatusCode());
    }

    public function testAnOrdinaryChiefWhoManagesNothingIsStillRefused(): void
    {
        // The route guard would let a chief through — it is `identified`.
        // Only RentalAuthorizationService stops them, which is the whole
        // point of this test.
        AuthSession::login(1, 'chief@test.be', 'chief');

        $this->assertSame(404, $this->overview('local-saint-georges')->getStatusCode());
    }

    public function testEveryManagedPageIsRefusedToANonManager(): void
    {
        AuthSession::login(1, 'nobody@test.be', 'identified');
        $booking = $this->createBooking();

        $this->assertSame(404, $this->overview('local-saint-georges')->getStatusCode());
        $this->assertSame(404, $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar'
        )->getStatusCode());
        $this->assertSame(404, $this->get(
            '/mes-locations/{slug}/reservations',
            '/mes-locations/local-saint-georges/reservations',
            'bookings'
        )->getStatusCode());
        $this->assertSame(404, $this->bookingPage('local-saint-georges', $booking->id)->getStatusCode());
    }

    public function testEveryWriteActionIsRefusedToANonManager(): void
    {
        $booking = $this->createBooking();
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $actions = [
            ['/mes-locations/statut', 'changeStatus', ['status' => 'reviewing']],
            ['/mes-locations/option', 'placeOption', ['until' => '2027-06-01T18:00']],
            ['/mes-locations/commentaire', 'addComment', ['body' => 'Interne']],
            ['/mes-locations/ligne', 'priceLine', ['line_action' => 'recalculate']],
            ['/mes-locations/proposition', 'propose', ['kind' => 'dates']],
            ['/mes-locations/demande', 'decideChange', ['request_id' => '1', 'decision' => 'accept']],
            ['/mes-locations/document-texte', 'saveDocumentText', ['document_type' => 'contract', 'body' => 'x']],
            ['/mes-locations/document-generer', 'generateDocument', ['document_type' => 'contract']],
            ['/mes-locations/document-envoyer', 'sendDocument', ['document_id' => '1']],
            ['/mes-locations/document-supprimer', 'deleteDocument', ['document_id' => '1']],
            ['/mes-locations/facturation', 'saveBillingIdentity', ['billing_name' => 'x']],
            ['/mes-locations/releve', 'recordReading', ['meter_id' => '1', 'phase' => 'arrival', 'value' => '1000']],
            ['/mes-locations/inventaire', 'recordInventory', ['inventory_id' => '1', 'phase' => 'arrival', 'state' => 'ok']],
            ['/mes-locations/incident', 'reportIncident', ['description' => 'x']],
            ['/mes-locations/incident-decision', 'decideIncident', ['incident_id' => '1', 'decision' => 'charge']],
            ['/mes-locations/decompte', 'recordSettlement', ['final_persons' => '10']],
            ['/mes-locations/decompte-valider', 'validateSettlement', ['settlement_id' => '1']],
        ];

        foreach ($actions as [$path, $action, $body]) {
            $response = $this->post($path, $action, array_merge($body, [
                'asset_id' => (string) $this->assetId,
                'booking_id' => (string) $booking->id,
            ]));
            $this->assertSame(404, $response->getStatusCode(), $path . ' must be refused.');
        }

        $this->assertSame(404, $this->post('/mes-locations/blocage', 'createBlock', [
            'asset_id' => (string) $this->assetId,
            'start' => '2027-09-01',
            'end' => '2027-09-05',
        ])->getStatusCode());
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testAWriteWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/statut', 'changeStatus', [
            '_csrf_token' => 'forged',
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'reviewing',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($booking->id)?->status);
    }

    // ── IDOR on the booking id ──────────────────────────────────────────

    public function testABookingOfAnotherAssetIsA404EvenForItsOwnManager(): void
    {
        // Bookings are numbered across the whole installation and the id is
        // in the URL, so without the asset check a manager of one asset
        // could read every booking of every other by walking the ids.
        $this->addManager($this->assetId, 'manager@test.be');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $this->assertSame(404, $this->bookingPage('local-saint-georges', $foreign->id)->getStatusCode());
    }

    public function testAWriteAgainstAnotherAssetsBookingIsA404(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $response = $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $foreign->id,
            'status' => 'reviewing',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($foreign->id)?->status);
    }

    public function testABlockCannotBeDeletedThroughAnAssetTheManagerDoesControl(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        $foreignBlockId = $this->blockRepository->create($this->otherAssetId, '2027-09-01', '2027-09-05', 1, null, null);
        AuthSession::login(1, 'manager@test.be', 'identified');

        $this->post('/mes-locations/blocage-supprimer', 'deleteBlock', [
            'asset_id' => (string) $this->assetId,
            'block_id' => (string) $foreignBlockId,
        ]);

        $this->assertNotNull($this->blockRepository->findById($foreignBlockId));
    }

    // ── What a manager actually does ────────────────────────────────────

    private function loginAsManager(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');
    }

    public function testAManagerMovesABookingThroughItsLifecycle(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'reviewing',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(BookingStatus::REVIEWING, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testAnInvalidTransitionLeavesTheBookingAloneAndSaysSo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'cancelled',
        ]);

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertSame(BookingStatus::CANCELLED, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'annule-tout',
        ]);

        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testAManagerPlacesAndLiftsAnOption(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $deadline = (new \DateTimeImmutable('+10 days'))->format('Y-m-d\TH:i');

        $this->post('/mes-locations/option', 'placeOption', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'until' => $deadline,
        ]);
        $this->assertNotNull($this->bookingRepository->findById($booking->id)?->holdUntil);

        $this->post('/mes-locations/option', 'placeOption', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'until' => '',
        ]);
        $this->assertNull($this->bookingRepository->findById($booking->id)?->holdUntil);
    }

    public function testAManagerEditsThePriceAndTheRenterSeesTheNewTotal(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/ligne', 'priceLine', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'line_action' => 'add',
            'label' => 'Remise exceptionnelle',
            'quantity' => '1',
            'amount' => '-50,00',
        ]);

        $fresh = $this->bookingRepository->findById($booking->id);
        // The French decimal comma is what an operator actually types.
        $this->assertSame(31000, $fresh?->effectiveTotalCents());
    }

    public function testABlockIsAcceptedOverABookedPeriodAndTheManagerIsWarned(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $response = $this->post('/mes-locations/blocage', 'createBlock', [
            'asset_id' => (string) $this->assetId,
            'start' => '2027-07-01',
            'end' => '2027-07-04',
            'reason' => 'Chantier toiture',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        // Neither failed nor overwrote the booking (§6.18).
        $this->assertCount(1, $this->blockRepository->findUpcoming($this->assetId, '2027-01-01'));
        $this->assertSame(BookingStatus::CONFIRMED, $this->bookingRepository->findById($booking->id)?->status);
        // Accepted, but said out loud, so an accidental overlap is visible
        // rather than silent.
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString('coexistent', $flash['message'] ?? '');
    }

    // ── Internal comments never cross the boundary ──────────────────────

    public function testAnInternalCommentIsVisibleToTheManager(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/commentaire', 'addComment', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'body' => 'Groupe déjà venu, cuisine laissée sale.',
        ]);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('cuisine laiss', $body);
    }

    public function testTheBookingPageShowsTheMilestoneChecklistAndTheHistory(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'reviewing',
        ]);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('Demande re', $body);
        $this->assertStringContainsString('Historique', $body);
        $this->assertStringContainsString('Location cl', $body);
    }

    public function testTheBookingsListFiltersOnWhatNeedsAttention(): void
    {
        $this->loginAsManager();
        $needsMe = $this->createBooking(null, 'LOC-2027-0001');
        $done = $this->createBooking(null, 'LOC-2027-0002');
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $done->id,
            'status' => 'cancelled',
        ]);

        $body = (string) $this->get(
            '/mes-locations/{slug}/reservations',
            '/mes-locations/local-saint-georges/reservations',
            'bookings',
            ['statut' => 'a_traiter']
        )->getBody();

        $this->assertStringContainsString($needsMe->reference, $body);
        $this->assertStringNotContainsString($done->reference, $body);
    }

    public function testTheCalendarDistinguishesBookingsFromBlocks(): void
    {
        $this->loginAsManager();
        $this->createBooking();
        $this->blockRepository->create($this->assetId, '2027-07-10', '2027-07-12', 1, 'Chantier toiture', null);

        $body = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar',
            ['month' => '2027-07']
        )->getBody();

        $this->assertStringContainsString('LOC-2027-0001', $body);
        $this->assertStringContainsString('Chantier toiture', $body);
        $this->assertStringContainsString('Blocages manuels', $body);
    }

    public function testThePrivateCalendarCanBePagedIntoThePastUnlikeThePublicOne(): void
    {
        // Half a manager's work is about stays that already happened.
        $this->loginAsManager();
        $lastMonth = (new \DateTimeImmutable('today'))->modify('first day of last month');

        $body = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar',
            ['month' => $lastMonth->format('Y-m')]
        )->getBody();

        $this->assertStringContainsString((string) (int) $lastMonth->format('Y'), $body);
    }

    public function testMyRentalsCountsWhatIsWaitingOnEachAsset(): void
    {
        $this->loginAsManager();
        $this->createBooking(null, 'LOC-2027-0001');
        $this->createBooking(null, 'LOC-2027-0002');

        $body = (string) $this->get('/mes-locations', '/mes-locations', 'myRentals')->getBody();

        $this->assertStringContainsString('2 à traiter', $body);
        $this->assertStringContainsString('LOC-2027-0001', $body);
    }

    public function testMyRentalsNeverListsAnotherManagersBookings(): void
    {
        $this->loginAsManager();
        $this->createBooking($this->otherAssetId, 'LOC-2027-0099');

        $body = (string) $this->get('/mes-locations', '/mes-locations', 'myRentals')->getBody();

        $this->assertStringNotContainsString('LOC-2027-0099', $body);
        $this->assertStringNotContainsString('Local des autres', $body);
    }

    public function testAManagerDecidesARentersChangeRequest(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $requestId = $this->operationsService->requestChange(
            $booking,
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null
        );

        $this->post('/mes-locations/demande', 'decideChange', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'request_id' => (string) $requestId,
            'decision' => 'accept',
        ]);

        $this->assertSame('2027-07-08', $this->bookingRepository->findById($booking->id)?->arrivalDate);
    }

    // ── Documents (§6.24, §6.25) ────────────────────────────────────────

    private function setContractTemplate(string $body = '<p>Contrat pour {{ locataire_nom }}.</p>'): void
    {
        (new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)))
            ->set(\Modules\Rental\Document\DocumentType::CONTRACT->templateKey($this->assetId), $body, 'rich_text', 1);
    }

    public function testAManagerGeneratesAContractAndItIsListedOnTheBooking(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $documents = $this->documentService->forBooking($booking->id);
        $this->assertCount(1, $documents);
        $this->assertSame('contrat-LOC-2027-0001-v1.pdf', $documents[0]->originalName);
    }

    public function testRegeneratingAddsAVersionRatherThanReplacingTheFirst(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();

        for ($i = 0; $i < 2; $i++) {
            $this->post('/mes-locations/document-generer', 'generateDocument', [
                'asset_id' => (string) $this->assetId,
                'booking_id' => (string) $booking->id,
                'document_type' => 'contract',
            ]);
        }

        $versions = array_map(
            static fn($d) => $d->version,
            $this->documentService->forBooking($booking->id)
        );
        sort($versions);
        $this->assertSame([1, 2], $versions);
    }

    public function testAContractOfAnotherAssetsBookingCannotBeGeneratedHere(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');

        $response = $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $foreign->id,
            'document_type' => 'contract',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->documentService->forBooking($foreign->id));
    }

    public function testADocumentOfAnotherBookingCannotBeDeletedThroughThisOne(): void
    {
        // A document id alone must not be enough: the booking check is the
        // guard that matters.
        $this->loginAsManager();
        $this->setContractTemplate();
        $mine = $this->createBooking(null, 'LOC-2027-0001');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');
        $foreignDocumentId = $this->documentService->attachUploaded(
            $foreign,
            $this->fileRepository->create('rental/documents/x.pdf', 'x.pdf', 'application/pdf', 1, 'identified', 'rental', null),
            \Modules\Rental\Document\DocumentType::OTHER,
            false
        );

        $this->post('/mes-locations/document-supprimer', 'deleteDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $mine->id,
            'document_id' => (string) $foreignDocumentId,
        ]);

        $this->assertNotNull($this->documentService->find($foreignDocumentId));
    }

    public function testAnEmptyTemplateIsReportedRatherThanProducingABlankContract(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        $this->assertSame([], $this->documentService->forBooking($booking->id));
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('danger', $flash['type'] ?? null);
    }

    public function testAPhotoCannotBeGeneratedBecauseItIsUploadOnly(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'photo',
        ]);

        $this->assertSame([], $this->documentService->forBooking($booking->id));
    }

    public function testAnUnknownKeywordIsReportedWhenSavingABookingsText(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/document-texte', 'saveDocumentText', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
            'body' => '<p>{{ reference }} et {{ prix_ttc }}</p>',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString('prix_ttc', $flash['message'] ?? '');
    }

    public function testTheTemplateEditorIsRefusedToANonManager(): void
    {
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $this->assertSame(404, $this->get(
            '/mes-locations/{slug}/gabarits',
            '/mes-locations/local-saint-georges/gabarits',
            'templates'
        )->getStatusCode());
    }

    public function testTheTemplateEditorIsReachableByAManager(): void
    {
        $this->loginAsManager();

        $body = (string) $this->get(
            '/mes-locations/{slug}/gabarits',
            '/mes-locations/local-saint-georges/gabarits',
            'templates'
        )->getBody();

        $this->assertStringContainsString('Gabarits', $body);
        $this->assertStringContainsString('{{ locataire_nom }}', $body);
    }

    public function testTheDocumentEditorIsRefusedForAnotherAssetsBooking(): void
    {
        $this->loginAsManager();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');

        $router = new Router();
        $router->addRoute(
            'GET',
            '/mes-locations/{slug}/reservations/{id}/document/{type}',
            RentalManagementController::class,
            'documentEditor',
            'identified'
        );
        $response = $this->dispatch($router, new Request(
            'GET',
            '/mes-locations/local-saint-georges/reservations/' . $foreign->id . '/document/contract',
            [],
            [],
            [],
            []
        ));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheBillingIdentityIsSavedFromTheBookingPage(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/facturation', 'saveBillingIdentity', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'billing_name' => 'ASBL Les Scouts',
            'billing_country' => 'be',
            'billing_vat_number' => 'BE0123456789',
        ]);

        $identity = $this->bookingRepository->findBillingIdentity($booking->id);
        $this->assertSame('ASBL Les Scouts', $identity['name']);
        $this->assertSame('BE', $identity['country']);
    }

    // ── The stay (§6.21–§6.23) ──────────────────────────────────────────

    private function stayPage(string $slug, int $bookingId): \Core\Http\Response
    {
        return $this->get(
            '/mes-locations/{slug}/reservations/{id}/sejour',
            '/mes-locations/' . $slug . '/reservations/' . $bookingId . '/sejour',
            'stay'
        );
    }

    public function testTheStayPageIsRefusedToANonManager(): void
    {
        $booking = $this->createBooking();
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $this->assertSame(404, $this->stayPage('local-saint-georges', $booking->id)->getStatusCode());
    }

    public function testTheStayPageOfAnotherAssetsBookingIsA404(): void
    {
        $this->loginAsManager();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');

        $this->assertSame(404, $this->stayPage('local-saint-georges', $foreign->id)->getStatusCode());
    }

    public function testTheStayPageSaysItDoesNotWorkOffline(): void
    {
        // §6.23: these are write pages, never cached. The page tells a
        // manager the workaround rather than letting them discover it by
        // losing an inventory on the way home.
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = (string) $this->stayPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('en ligne', $body);
        $this->assertStringContainsString('hotographiez sur place', $body);
    }

    public function testAManagerRecordsAReadingFromTheStayPage(): void
    {
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Électricité', \Modules\Rental\Stay\MeterKind::ELECTRICITY, 'kWh', null
        );
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/releve', 'recordReading', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'meter_id' => (string) $meterId,
            'phase' => 'arrival',
            'value' => '1234,567',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            1234567,
            (int) $this->pdo->query('SELECT value_milli FROM rental_meter_readings')->fetchColumn()
        );
    }

    public function testAReadingRedirectsBackToTheStayPageNotTheBookingFile(): void
    {
        // A manager recording eight readings should land where they were.
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Eau', \Modules\Rental\Stay\MeterKind::WATER, 'm³', null
        );
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/releve', 'recordReading', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'meter_id' => (string) $meterId,
            'phase' => 'arrival',
            'value' => '12',
        ]);

        $this->assertStringEndsWith('/sejour', (string) $response->getHeaders()['Location']);
    }

    public function testAnUnknownPhaseIsRefused(): void
    {
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Eau', \Modules\Rental\Stay\MeterKind::WATER, 'm³', null
        );
        $booking = $this->createBooking();

        $this->post('/mes-locations/releve', 'recordReading', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'meter_id' => (string) $meterId,
            'phase' => 'milieu',
            'value' => '12',
        ]);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_meter_readings')->fetchColumn());
    }

    public function testAManagerReportsAndThenDecidesAnIncident(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/incident', 'reportIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'description' => 'Vitre cassée',
            'amount' => '50,00',
        ]);

        $incidents = $this->stayService->incidentsFor($booking->id);
        $this->assertCount(1, $incidents);
        $this->assertSame(5000, $incidents[0]->proposedAmountCents);
        // Nothing is charged until a human says so.
        $this->assertSame(\Modules\Rental\Stay\IncidentDecision::PENDING, $incidents[0]->decision);

        $this->post('/mes-locations/incident-decision', 'decideIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'incident_id' => (string) $incidents[0]->id,
            'decision' => 'withhold',
            'amount' => '30,00',
        ]);

        $decided = $this->stayService->incidentsFor($booking->id)[0];
        $this->assertSame(\Modules\Rental\Stay\IncidentDecision::WITHHOLD, $decided->decision);
        $this->assertSame(3000, $decided->decidedAmountCents);
    }

    public function testAnIncidentOfAnotherBookingCannotBeDecidedHere(): void
    {
        $this->loginAsManager();
        $mine = $this->createBooking(null, 'LOC-2027-0001');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');
        $foreignId = $this->stayService->reportIncident($foreign, 'Vitre cassée', 5000, null, 1);

        $this->post('/mes-locations/incident-decision', 'decideIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $mine->id,
            'incident_id' => (string) $foreignId,
            'decision' => 'charge',
        ]);

        $this->assertSame(
            \Modules\Rental\Stay\IncidentDecision::PENDING,
            $this->stayService->incidentsFor($foreign->id)[0]->decision
        );
    }

    public function testAManagerRecordsAndValidatesASettlement(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/decompte', 'recordSettlement', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'final_persons' => '28',
        ]);

        $settlement = $this->stayService->latestSettlement($booking->id);
        $this->assertNotNull($settlement);
        $this->assertSame(28, $settlement->finalPersons);
        $this->assertFalse($settlement->isValidated);

        $this->post('/mes-locations/decompte-valider', 'validateSettlement', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'settlement_id' => (string) $settlement->id,
        ]);

        $this->assertTrue($this->stayService->latestSettlement($booking->id)?->isValidated);
    }

    public function testValidatingTwiceIsRefusedAndSaysSo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $settlement = $this->stayService->recordSettlement($booking, $this->assetId, 28, [], 1);
        $this->stayService->validateSettlement($booking, $settlement->id, 1);

        $this->post('/mes-locations/decompte-valider', 'validateSettlement', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'settlement_id' => (string) $settlement->id,
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('danger', $flash['type'] ?? null);
        $this->assertStringContainsString('déjà validé', $flash['message'] ?? '');
    }

    public function testTheChecklistIsSnapshottedWhenTheBookingIsConfirmed(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés', 0);
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertCount(1, $this->stayService->inventoryFor($booking->id));
    }

    public function testAManagerConfiguresAMeterFromTheTemplatesPage(): void
    {
        $this->loginAsManager();

        $this->post('/mes-locations/compteur', 'saveMeter', [
            'asset_id' => (string) $this->assetId,
            'label' => 'Électricité',
            'kind' => 'electricity',
            'unit' => 'kWh',
        ]);

        $this->assertCount(1, $this->stayService->metersFor($this->assetId));
    }

    public function testAMeterCannotBeConfiguredOnAnAssetTheManagerDoesNotManage(): void
    {
        $this->loginAsManager();

        $response = $this->post('/mes-locations/compteur', 'saveMeter', [
            'asset_id' => (string) $this->otherAssetId,
            'label' => 'Électricité',
            'kind' => 'electricity',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->stayService->metersFor($this->otherAssetId));
    }

    public function testAChangeRequestOfAnotherBookingCannotBeDecidedHere(): void
    {
        $this->loginAsManager();
        $mine = $this->createBooking(null, 'LOC-2027-0001');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');
        $foreignRequestId = $this->operationsService->requestChange(
            $foreign,
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::CANCELLATION,
            null,
            null,
            null,
            null,
            null,
            null
        );

        $this->post('/mes-locations/demande', 'decideChange', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $mine->id,
            'request_id' => (string) $foreignRequestId,
            'decision' => 'accept',
        ]);

        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($foreign->id)?->status);
    }
}
