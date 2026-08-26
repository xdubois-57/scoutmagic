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
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\StructuredCommunicationService;
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
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBlockService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
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
    private RentalPaymentService $paymentService;
    private int $financeAccountId;
    /** Every decision email the controller asked for, in order. */
    /** @var list<array{decision: string, token: ?string, word: ?string, booking_id: int}> */
    private array $renterEmails = [];
    /** Every new tracking link the controller asked to be mailed out. */
    /** @var list<array{booking_id: int, token: string}> */
    private array $trackingLinkEmails = [];

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
        $bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $this->encryption);

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
            $bookingAudit,
            $this->pricingService,
            new \Modules\Rental\Stay\SettlementCalculator(),
            $journal
        );

        // Finance, wired exactly as public/index.php does: the payment
        // panel and the "still owed" warning are only real when the
        // receivables behind them are.
        FinanceTestHelper::createTables($this->pdo);
        $this->financeAccountId = (new AccountRepository($this->pdo, $this->encryption))->create(
            'Compte unité', 'bank', null, 'BE68539007547034', 'Unité Test', 'intendant'
        );
        $this->pdo->exec("UPDATE finance_accounts SET status = 'active'");
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->paymentService = new RentalPaymentService(
            new \Modules\Rental\Repository\RentalPaymentRepository($this->pdo, $this->encryption),
            $bookingAudit,
            $journal,
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository)
        );

        $this->operationsService = new RentalOperationsService(
            $this->bookingRepository,
            $bookingAudit,
            $this->commentRepository,
            $this->changeRequestRepository,
            $availabilityService,
            $this->pricingService,
            new QuoteEditor(),
            $journal,
            $this->paymentService,
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
            $bookingAudit,
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
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
            new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($this->pdo, $this->encryption)),
            $this->commentRepository,
            $this->changeRequestRepository,
            $this->operationsService,
            new RentalBlockService($this->blockRepository, $journal),
            $availabilityService,
            $this->pricingService,
            $memberService,
            new DayStateGridBuilder(),
            $this->paymentService,
            $this->documentService,
            $this->recordingMailService(),
            new \Core\File\UploadHandler($this->fileRepository, $this->storagePath),
            $this->stayService,
            null,
            null,
            // The paperwork register: the compliance page 404s without it.
            new \Modules\Rental\Service\RentalComplianceService(
                new \Modules\Rental\Repository\RentalComplianceRepository($this->pdo),
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                $journal,
                $this->fileRepository
            ),
            null,
            // Only « Régénérer le lien de suivi » reaches it.
            new RentalBookingService($this->bookingRepository, $journal)
        );

        $this->assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');
        $this->otherAssetId = $this->createAsset('Local des autres', 'local-des-autres');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $this->renterEmails = [];
        $this->trackingLinkEmails = [];
    }

    /**
     * A mail service that records the decisions it was asked to send.
     *
     * Recording rather than an expectation: what matters is not that
     * sendDecision() was called some number of times, but which decision
     * reached it, with which manager's word, and — for the ones a renter
     * can still act on — with a token at all.
     */
    private function recordingMailService(): \Modules\Rental\Service\RentalBookingMailService
    {
        $mock = $this->createMock(\Modules\Rental\Service\RentalBookingMailService::class);
        $mock->method('sendDecision')->willReturnCallback(
            function (
                \Modules\Rental\Booking\RentalBooking $booking,
                \Modules\Rental\Repository\RentalAsset $asset,
                \Modules\Rental\Booking\RenterDecision $decision,
                ?string $trackingToken,
                ?string $managerWord = null
            ): bool {
                $this->renterEmails[] = [
                    'decision' => $decision->value,
                    'token' => $trackingToken,
                    'word' => $managerWord,
                    'booking_id' => $booking->id,
                ];

                return true;
            }
        );
        $mock->method('sendTrackingLink')->willReturnCallback(
            function (
                \Modules\Rental\Booking\RentalBooking $booking,
                \Modules\Rental\Repository\RentalAsset $asset,
                string $trackingToken
            ): bool {
                $this->trackingLinkEmails[] = ['booking_id' => $booking->id, 'token' => $trackingToken];

                return true;
            }
        );

        return $mock;
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
        $id = $this->assetRepository->create('Local', $name, $slug, 60, 1, null, null, null, true);
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

    /**
     * The same POST as the booking page's own fetch makes
     * (public/assets/js/rental-booking.js): the X-Requested-With header is
     * the whole difference, and what makes bookingAction() answer JSON
     * instead of redirecting.
     *
     * @param array<string, string> $body
     */
    private function postAsync(string $path, string $action, array $body): Response
    {
        $body['_csrf_token'] ??= CsrfGuard::generateToken();
        $_POST = $body;

        $router = new Router();
        $router->addRoute('POST', $path, RentalManagementController::class, $action, 'identified');

        return $this->dispatch($router, new Request(
            'POST',
            $path,
            [],
            $body,
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']
        ));
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

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
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

    /**
     * A confirmed booking with a receivable raised against it and nothing
     * received — the ordinary state of a rental a fortnight before the
     * stay.
     */
    private function bookingOwedFor(int $totalCents = 46750): RentalBooking
    {
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: true,
            financeAccountId: $this->financeAccountId
        ));
        $booking = $this->createBooking();
        $this->bookingRepository->setAgreedPrice($booking->id, new \Modules\Rental\Pricing\PriceQuote(
            lines: [new \Modules\Rental\Pricing\PriceLine('Séjour', 1, null, $totalCents, \Modules\Rental\Pricing\PriceLine::RULE_BASE)],
            totalCents: $totalCents,
            nights: 3,
            persons: 20,
            quantity: 1,
            billingUnit: \Modules\Rental\Pricing\BillingUnit::FLAT_STAY
        ));

        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);
        $this->paymentService->ensureReceivables(
            $fresh,
            $this->paymentService->settingsFor($this->assetId),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );

        return $fresh;
    }

    public function testCancellingABookingStillOwedMoneySaysSo(): void
    {
        // §6.17 is explicit that nothing computes a refund — but cancelling
        // leaves the receivable exactly where it was, and "Réservation
        // « Annulée »." on its own reads as "everything is settled".
        $this->loginAsManager();
        $booking = $this->bookingOwedFor();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'cancelled',
        ]);

        $flash = (string) (\Core\Http\FlashMessage::get()['message'] ?? '');
        $this->assertStringContainsString('Attention', $flash);
        $this->assertStringContainsString('créance', $flash);
    }

    public function testAnOrdinaryTransitionSaysNothingAboutMoney(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'reviewing',
        ]);

        $this->assertStringNotContainsString(
            'Attention',
            (string) (\Core\Http\FlashMessage::get()['message'] ?? '')
        );
    }

    public function testClosingABookingWithNothingOutstandingSaysNothing(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'cancelled',
        ]);

        $this->assertStringNotContainsString(
            'Attention',
            (string) (\Core\Http\FlashMessage::get()['message'] ?? '')
        );
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

    // ── The renter is told (§6.15, §6.16) ───────────────────────────────

    public function testAConfirmationTellsTheRenterAndCarriesTheirLink(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertCount(1, $this->renterEmails);
        $this->assertSame('confirmed', $this->renterEmails[0]['decision']);
        $this->assertSame($booking->id, $this->renterEmails[0]['booking_id']);
        // The link the renter has no other way of getting to.
        $this->assertNotNull($this->renterEmails[0]['token']);
        $this->assertStringContainsString(
            'prévenu par email',
            (string) (\Core\Http\FlashMessage::get()['message'] ?? '')
        );
    }

    public function testARefusalTellsTheRenterAndCarriesNoLink(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'refused',
        ]);

        $this->assertCount(1, $this->renterEmails);
        $this->assertSame('refused', $this->renterEmails[0]['decision']);
        // The booking is over; re-issuing a capability inside a message
        // nobody can act on is how a link ends up forwarded.
        $this->assertNull($this->renterEmails[0]['token']);
    }

    public function testTheManagersOwnWordTravelsWithTheDecision(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'refused',
            'message' => 'Le local est déjà pris ce week-end-là.',
        ]);

        $this->assertSame('Le local est déjà pris ce week-end-là.', $this->renterEmails[0]['word']);
    }

    public function testBookkeepingWritesToNobody(): void
    {
        // A manager opening a request moves it to « en cours d'examen ».
        // That is not news, and an email saying so would train the renter
        // to ignore the ones that are.
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'reviewing',
        ]);

        $this->assertSame([], $this->renterEmails);
    }

    public function testARefusedTransitionSendsNothingAtAll(): void
    {
        // The email must follow the decision, never precede it: a
        // transition the state machine rejects has decided nothing.
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'cancelled',
        ]);
        $this->renterEmails = [];

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertSame([], $this->renterEmails);
    }

    public function testAProposalIsActuallySentRatherThanMerelyClaimed(): void
    {
        // The flash used to read « Proposition envoyée » while nothing had
        // left the building.
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/proposition', 'propose', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'kind' => 'dates',
            'arrival' => '2027-08-20',
            'departure' => '2027-08-23',
            'message' => 'Ces dates-là nous arrangeraient mieux.',
        ]);

        $this->assertCount(1, $this->renterEmails);
        $this->assertSame('proposed', $this->renterEmails[0]['decision']);
        $this->assertSame('Ces dates-là nous arrangeraient mieux.', $this->renterEmails[0]['word']);
        $this->assertNotNull($this->renterEmails[0]['token']);
    }

    public function testDecidingARentersChangeRequestTellsThemWhichWay(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $requestId = $this->operationsService->requestChange(
            $booking,
            \Modules\Rental\Booking\ChangeRequestOrigin::RENTER,
            \Modules\Rental\Booking\ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            30,
            null,
            'Nous serons plus nombreux.'
        );

        $this->post('/mes-locations/demande', 'decideChange', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'request_id' => (string) $requestId,
            'decision' => 'refuse',
            'message' => 'Le local ne peut pas accueillir trente personnes.',
        ]);

        $this->assertCount(1, $this->renterEmails);
        $this->assertSame('change_refused', $this->renterEmails[0]['decision']);
        $this->assertSame('Le local ne peut pas accueillir trente personnes.', $this->renterEmails[0]['word']);
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

        // The shared Core\Audit timeline (§8.66), not a hand-rolled list:
        // the status change is rendered under its French label, with the
        // move it made, by the same partial Camps uses.
        $this->assertStringContainsString('Statut', $body);
        $this->assertStringContainsString('En cours d', $body);
        $this->assertStringContainsString('audit-rental_booking-' . $booking->id, $body);
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

    private function bookingsList(array $query = []): string
    {
        return (string) $this->get(
            '/mes-locations/{slug}/reservations',
            '/mes-locations/local-saint-georges/reservations',
            'bookings',
            $query
        )->getBody();
    }

    public function testTheBookingsListSearchesByReferenceAndByName(): void
    {
        $this->loginAsManager();
        $this->createBooking(null, 'LOC-2027-0001');
        $this->createBooking(null, 'LOC-2028-0009');

        $byReference = $this->bookingsList(['q' => '2028']);
        $this->assertStringContainsString('LOC-2028-0009', $byReference);
        $this->assertStringNotContainsString('LOC-2027-0001', $byReference);

        // The renter's name is encrypted, so this can only ever be
        // answered after hydration — which is exactly why the filtering
        // happens in PHP.
        $this->assertStringContainsString('LOC-2027-0001', $this->bookingsList(['q' => 'Jeanne']));
        $this->assertStringNotContainsString('LOC-2027-0001', $this->bookingsList(['q' => 'Gudule']));
    }

    public function testTheBookingsListFiltersByYear(): void
    {
        $this->loginAsManager();
        $this->createBooking(null, 'LOC-2027-0001');
        $other = $this->bookingRepository->create(
            $this->assetId, 'LOC-2029-0001', '2029-07-01', '2029-07-04', 1, 20, null,
            ['name' => 'Marc', 'email' => 'marc@example.be', 'phone' => null,
             'organisation' => null, 'purpose' => null, 'comment' => null],
            null, null, null, 'v1', str_repeat('0', 64), 'v1', str_repeat('0', 64),
            new \DateTimeImmutable('2029-01-01 10:00:00')
        );
        $this->assertGreaterThan(0, $other['id']);

        $body = $this->bookingsList(['annee' => '2029']);

        $this->assertStringContainsString('LOC-2029-0001', $body);
        $this->assertStringNotContainsString('LOC-2027-0001', $body);
    }

    public function testTheBookingsListIsPaged(): void
    {
        // A hall let for ten years has hundreds of bookings, and the page
        // used to render every single one of them.
        $this->loginAsManager();
        for ($i = 1; $i <= 27; $i++) {
            $this->createBooking(null, sprintf('LOC-2027-%04d', $i));
        }

        $first = $this->bookingsList();
        $second = $this->bookingsList(['page' => '2']);

        $this->assertStringContainsString('Pagination des réservations', $first);
        // 27 bookings, 25 per page, ordered by arrival date then id — the
        // last two land on page two and nowhere else.
        $this->assertSame(25, substr_count($first, '/reservations/'));
        $this->assertSame(2, substr_count($second, '/reservations/'));
        $this->assertStringContainsString('27 réservations', $first);
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
        $this->assertStringContainsString("Périodes réservées par l'unité", $body);
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

    /**
     * The bug this covers, end to end: the checklist took its extra
     * milestones from an `$extras` map nobody ever built, so « Contrat
     * envoyé » stayed greyed — "sans objet" — however many contracts went
     * out. It is now derived from the documents themselves.
     */
    public function testSendingTheContractTicksTheChecklistLineOnTheBookingPage(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();

        $before = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        // Rendered as an unticked box, never as the greyed "sans objet"
        // dash it used to be.
        $this->assertMatchesRegularExpression('/bi-square[^<]*<\/i>\s*<span class="visually-hidden">À faire :<\/span>\s*Contrat envoyé/', $before);

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $document = $this->documentService->forBooking($booking->id)[0];
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $document->id,
        ]);

        $after = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertMatchesRegularExpression('/bi-check-square[^<]*<\/i>\s*<span class="visually-hidden">Fait :<\/span>\s*Contrat envoyé/', $after);
    }

    /**
     * A milestone belonging to something this installation cannot do at
     * all still renders greyed — that is what the applicability flag is
     * for, and ticking every box unconditionally would be the opposite
     * bug.
     */
    public function testAMilestoneWithNothingBehindItStaysGreyed(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        // No security deposit is configured on this asset.
        $this->assertMatchesRegularExpression('/bi-dash-square[^<]*<\/i>\s*<span class="visually-hidden">Sans objet :<\/span>\s*Caution reçue/', $body);
    }

    /**
     * Every panel the page's own fetch swaps carries its wrapper, and the
     * wrapper is present even when what it holds is not — a card that
     * appears or disappears has to swap like any other.
     */
    public function testTheBookingPageMarksItsRefreshablePanels(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('data-rental-booking', $body);
        foreach (['milestones', 'lifecycle', 'documents', 'price', 'history'] as $panel) {
            $this->assertStringContainsString('data-booking-panel="' . $panel . '"', $body);
        }
    }

    // ── The page acts without reloading ─────────────────────────────────

    public function testAnAsyncActionAnswersTheFlashAsJsonInsteadOfRedirecting(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->postAsync('/mes-locations/commentaire', 'addComment', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'body' => 'Le locataire a téléphoné.',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertStringContainsString('Commentaire enregistré', (string) $decoded['message']);
    }

    /**
     * A refused action reports itself as one — the manager stays on the
     * page, so the message has to arrive with the answer rather than in a
     * flash nobody is going to render.
     */
    public function testAnAsyncActionThatFailsAnswersSuccessFalseWithTheReason(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->postAsync('/mes-locations/demande', 'decideChange', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'request_id' => '999999',
            'decision' => 'accept',
        ]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('error', $decoded['type']);
        $this->assertNotNull($decoded['message']);
    }

    /**
     * The flash is consumed by the JSON answer: left in the session it
     * would surface, out of context, on whatever page the manager opened
     * next.
     */
    public function testAnAsyncActionLeavesNoFlashBehindForTheNextPage(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->postAsync('/mes-locations/commentaire', 'addComment', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'body' => 'Note interne.',
        ]);

        $this->assertNull(\Core\Http\FlashMessage::get());
    }

    /**
     * The classic path is untouched: a browser posting the form still gets
     * its redirect, so the page works with no JavaScript at all.
     */
    public function testAPlainFormPostStillRedirects(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/commentaire', 'addComment', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'body' => 'Note interne.',
        ]);

        $this->assertSame(302, $response->getStatusCode());
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

    public function testAnEmptyTemplateGeneratesTheStandardContract(): void
    {
        // The shipped standard bodies are a real default: a manager who
        // never opened the template editor can still press « Générer » and
        // hand the renter a complete Belgian contract, instead of meeting
        // "le gabarit est vide" as the first answer.
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        $this->assertCount(1, $this->documentService->forBooking($booking->id));
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
    }

    public function testTheTemplatePageOpensPrefilledWithTheStandardBody(): void
    {
        $this->loginAsManager();

        $body = (string) $this->get(
            '/mes-locations/{slug}/gabarits',
            '/mes-locations/local-saint-georges/gabarits',
            'templates'
        )->getBody();

        // The editor shows the text generation would actually use…
        $this->assertStringContainsString('Convention de location', $body);
        // …says which regime is in force…
        $this->assertStringContainsString('modèle standard', $body);
        // …and does NOT offer a reset that would be a no-op.
        $this->assertStringNotContainsString('Réinitialiser au modèle standard', $body);
    }

    public function testACustomisedTemplateOffersTheResetToStandard(): void
    {
        $this->loginAsManager();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);
        $this->documentService->saveTemplate(
            $asset,
            \Modules\Rental\Document\DocumentType::CONTRACT,
            '<p>Nos propres conditions de location.</p>',
            1
        );

        $body = (string) $this->get(
            '/mes-locations/{slug}/gabarits',
            '/mes-locations/local-saint-georges/gabarits',
            'templates'
        )->getBody();

        $this->assertStringContainsString('Nos propres conditions de location', $body);
        $this->assertStringContainsString('Réinitialiser au modèle standard', $body);
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
        $this->assertSame('error', $flash['type'] ?? null);
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

    // ── Regenerating the tracking link (§8.52) ──────────────────────────

    public function testAManagerCanReplaceALostTrackingLink(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $before = $this->bookingRepository->trackingTokenOf($booking->id);

        $response = $this->post('/mes-locations/lien-suivi', 'regenerateTrackingLink', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $after = $this->bookingRepository->trackingTokenOf($booking->id);
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after, 'The old link must stop working.');
        // The manager never sees the token, so the only way it reaches
        // anybody is the email addressed to the renter.
        $this->assertCount(1, $this->trackingLinkEmails);
        $this->assertSame($after, $this->trackingLinkEmails[0]['token']);
        $this->assertSame($booking->id, $this->trackingLinkEmails[0]['booking_id']);
    }

    public function testTheNewTokenIsNeverRenderedOnTheBookingPage(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/lien-suivi', 'regenerateTrackingLink', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
        ]);
        $token = (string) $this->bookingRepository->trackingTokenOf($booking->id);

        $this->assertStringNotContainsString(
            $token,
            $this->bookingPage('local-saint-georges', $booking->id)->getBody()
        );
    }

    public function testABookingOfAnotherAssetKeepsItsTrackingLink(): void
    {
        $this->loginAsManager();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-2027-0099');
        $before = $this->bookingRepository->trackingTokenOf($foreign->id);

        $response = $this->post('/mes-locations/lien-suivi', 'regenerateTrackingLink', [
            'asset_id' => (string) $this->otherAssetId,
            'booking_id' => (string) $foreign->id,
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($before, $this->bookingRepository->trackingTokenOf($foreign->id));
        $this->assertSame([], $this->trackingLinkEmails);
    }

    public function testAnAnonymousVisitorCannotReplaceATrackingLink(): void
    {
        // role_min identified, and one level below it is nobody at all.
        $this->addManager($this->assetId, 'manager@test.be');
        $booking = $this->createBooking();
        $before = $this->bookingRepository->trackingTokenOf($booking->id);

        $response = $this->post('/mes-locations/lien-suivi', 'regenerateTrackingLink', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
        ]);

        // The guard answers with the login redirect rather than the
        // action's own redirect — what matters is that nothing moved.
        $this->assertStringContainsString('/login', (string) $response->getHeaders()['Location']);
        $this->assertSame($before, $this->bookingRepository->trackingTokenOf($booking->id));
        $this->assertSame([], $this->trackingLinkEmails);
    }

    public function testAnIdentifiedVisitorWhoManagesNothingCannotReplaceATrackingLink(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        $booking = $this->createBooking();
        $before = $this->bookingRepository->trackingTokenOf($booking->id);
        AuthSession::login(2, 'passerby@test.be', 'identified');

        $response = $this->post('/mes-locations/lien-suivi', 'regenerateTrackingLink', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($before, $this->bookingRepository->trackingTokenOf($booking->id));
    }

    public function testTheBookingPageOffersTheRegenerationWithItsConsequence(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $html = (string) preg_replace(
            '/\\s+/',
            ' ',
            $this->bookingPage('local-saint-georges', $booking->id)->getBody()
        );

        $this->assertMatchesRegularExpression(
            '#<form[^>]*action="/mes-locations/lien-suivi"[^>]*data-confirm="R[^"]*g[^"]*rer le lien#',
            $html
        );
    }
    // ── Fields the site renders the same way everywhere ─────────────────

    /**
     * The calendar's blocking form and the compliance register were the
     * last two hand-written control stacks in the managed space: labels
     * whose classes did not match the rest of the site, and help texts no
     * screen reader ever announced because nothing pointed at them. The
     * `form_field` partial renders label, control, help text and required
     * marker as one unit with `aria-describedby` wired (design.md §7.9).
     */
    public function testTheBlockingFormIsRenderedThroughTheSharedField(): void
    {
        $this->loginAsManager();

        $html = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar'
        )->getBody();

        // The required marker comes from the partial, not from a « * »
        // somebody typed into the label.
        $this->assertMatchesRegularExpression(
            '#<label class="form-label small" for="block-start">\s*Du\s*<span class="text-danger" aria-hidden="true">\*</span>#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<input type="date" class="form-control form-control-sm" id="block-end"\s+name="end"\s+value=""\s+required#',
            $html
        );
    }

    public function testTheComplianceFormWiresItsHelpTextToItsField(): void
    {
        $this->loginAsManager();

        $html = (string) $this->get(
            '/mes-locations/{slug}/conformite',
            '/mes-locations/local-saint-georges/conformite',
            'compliance'
        )->getBody();

        $this->assertStringContainsString('aria-describedby="new-document-help"', $html);
        $this->assertStringContainsString('<div class="form-text" id="new-document-help">', $html);
        // The datalist the intitulé field reads still reaches it.
        $this->assertStringContainsString('list="compliance-suggestions"', $html);
    }
}
