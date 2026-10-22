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
use Modules\Rental\Booking\BookingBox;
use Modules\Rental\Booking\BookingPage;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Controller\RentalManagementController;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetReminderRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
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
use Core\Member\Repository\MemberProfileRepository;

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
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class RentalManagementControllerTest extends TestCase
{

    private \PDO $pdo;
    private Environment $twig;
    private RentalManagementController $controller;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalModificationRepository $modificationRepository;
    private \Modules\Rental\Service\RentalModificationService $modificationService;
    private RentalBookingCommentRepository $commentRepository;
    private RentalBlockRepository $blockRepository;

    private ?\Modules\Rental\Service\RentalComplianceService $complianceService = null;
    private RentalOperationsService $operationsService;
    private RentalPricingService $pricingService;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $assetId;
    private int $otherAssetId;

    /** @var list<array{sent_id: int, booking_id: int, token: ?string}> what « Renvoyer » asked for */
    private array $resends = [];
    private string $storagePath;
    private \Core\File\FileRepository $fileRepository;
    private \Modules\Rental\Service\RentalDocumentService $documentService;
    private \Modules\Rental\Service\RentalStayService $stayService;
    private RentalPaymentService $paymentService;
    private \Modules\Rental\Service\RentalClosureService $closureService;
    private int $financeAccountId;
    private int $financeCategoryId;
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
        // The controller asks ScoutYearResolver for the year the caller is
        // SERVED. With no public year configured, that resolver falls back
        // to the date-computed year — the same one above.
        $scoutYearResolver = new \Core\ScoutYear\ScoutYearResolver(
            $scoutYearService,
            $settingService,
            new \Core\Import\MemberYearRepository($this->pdo)
        );

        $connection = Connection::withPdo($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->modificationRepository = new RentalModificationRepository($this->pdo);
        $this->commentRepository = new RentalBookingCommentRepository($this->pdo, $this->encryption);
        $this->blockRepository = new RentalBlockRepository($this->pdo);
        $bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $this->encryption);

        $memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $this->encryption)
);

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );
        $availabilityService = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [
                new RentalBookingService($this->bookingRepository, $journal),
                $this->blockRepository,
                $this->modificationRepository,
            ]
        );

        // Finance, wired exactly as public/index.php does: the payment
        // panel and the "still owed" warning are only real when the
        // receivables behind them are.
        FinanceTestHelper::createTables($this->pdo);
        $this->financeAccountId = (new AccountRepository($this->pdo, $this->encryption))->create(
            'Compte unité', 'bank', null, 'BE68539007547034', 'Unité Test', 'intendant'
        );
        $this->pdo->exec("UPDATE finance_accounts SET status = 'active'");
        $this->financeCategoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->paymentService = new RentalPaymentService(
            new \Modules\Rental\Repository\RentalPaymentRepository($this->pdo, $this->encryption),
            $bookingAudit,
            $journal,
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository),
            // The QR and the account's IBAN on « Finances » (#927, §2.4).
            new \Modules\Finance\Service\SepaQrCodeService(),
            new \Modules\Finance\Service\FinanceAccountService(new AccountRepository($this->pdo, $this->encryption))
        );

        $this->storagePath = sys_get_temp_dir() . '/rental_mgmt_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0755, true);
        $this->fileRepository = new \Core\File\FileRepository($this->pdo);

        $this->stayService = new \Modules\Rental\Service\RentalStayService(
            new \Modules\Rental\Repository\RentalStayRepository($this->pdo, $this->encryption),
            $bookingAudit,
            $this->pricingService,
            new \Modules\Rental\Stay\SettlementCalculator(),
            $journal,
            // As public/index.php wires it: the settlement reads what was
            // paid on the booking's receivable...
            $this->paymentService,
            // ...and a photo taken off a reading or an incident leaves no
            // file behind (#989).
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath)
        );

        // The guarded closure and the unrecovered one (#980), as
        // public/index.php wires them.
        $this->closureService = new \Modules\Rental\Service\RentalClosureService(
            $this->bookingRepository,
            new \Modules\Rental\Repository\RentalStayRepository($this->pdo, $this->encryption),
            $this->stayService,
            $this->modificationRepository,
            $bookingAudit,
            $journal,
            $this->paymentService
        );

        $this->operationsService = new RentalOperationsService(
            $this->bookingRepository,
            $bookingAudit,
            $this->commentRepository,
            $this->modificationRepository,
            $availabilityService,
            $this->pricingService,
            $journal,
            $this->paymentService,
            // Wired exactly as public/index.php does, so the inventory
            // snapshot at confirmation is genuinely covered here rather
            // than only in production.
            $this->stayService,
            $this->closureService
        );

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views', 'inbound_mail' => dirname(__DIR__, 4) . '/modules/inbound_mail/views']
        );
        $this->twig->addGlobal('site_name', 'Unité Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'identified');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/mes-locations');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

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

        // As public/index.php wires it (#927, chantier §2.3).
        $occupancyGuard = new \Modules\Rental\Service\RentalOccupancyGuard(
            $this->bookingRepository,
            $availabilityService,
            $this->pricingService
        );
        $this->modificationService = new \Modules\Rental\Service\RentalModificationService(
            $this->bookingRepository,
            $this->modificationRepository,
            new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
            $occupancyGuard,
            $availabilityService,
            $this->pricingService,
            $bookingAudit,
            $journal,
            $this->documentService,
            $this->paymentService,
            $this->recordingMailService()
        );

        $this->controller = new RentalManagementController(
            $this->twig,
            new RentalAuthorizationService($memberService, $this->assetRepository, $this->managerRepository),
            $scoutYearResolver,
            $this->assetRepository,
            $this->bookingRepository,
            new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($this->pdo, $this->encryption)),
            $this->commentRepository,
            $this->modificationService,
            $this->operationsService,
            new RentalBlockService($this->blockRepository, $journal),
            $availabilityService,
            $this->pricingService,
            $memberService,
            new DayStateGridBuilder(),
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            $this->paymentService,
            $this->documentService,
            $this->recordingMailService(),
            new \Core\File\UploadHandler($this->fileRepository, $this->storagePath),
            $this->stayService,
            null,
            null,
            // The paperwork register: the compliance page 404s without it.
            $this->complianceService = new \Modules\Rental\Service\RentalComplianceService(
                new \Modules\Rental\Repository\RentalComplianceRepository($this->pdo),
                new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                $journal,
                new \Core\File\EncryptedFileStorageService(
                    $this->fileRepository,
                    new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                    $this->storagePath
                )
            ),
            // The three figures: « À traiter » has to be countable here, or
            // the tile and the list under it are only ever checked apart
            // (§22.5).
            new \Modules\Rental\Service\RentalStatisticsService(
                $this->bookingRepository,
                new \Modules\Rental\Repository\RentalAggregateRepository($this->pdo)
            ),
            // Only « Régénérer le lien de suivi » reaches it.
            new RentalBookingService($this->bookingRepository, $journal),
            // The « Rappels » section of the settings page (§6.29).
            new RentalAssetReminderRepository($this->pdo),
            $settingService,
            new \Modules\Rental\Service\RentalMilestoneMarkService(
                new \Modules\Rental\Repository\RentalMilestoneMarkRepository($this->pdo),
                $bookingAudit
            ),
            // The overview warns when nobody on the asset can be told (#708, IT-05).
            new \Modules\Rental\Service\ManagerRecipientResolver(
                $this->managerRepository,
                new \Core\Import\MemberYearRepository($this->pdo),
                new \Core\Security\UserAccountRepository($this->pdo, $this->encryption),
                $journal
            ),
            // Dates the conditions on the Gabarits list (#708, IT-10).
            new \Modules\Rental\Service\RentalConditionsService(
                new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo),
                new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo))
            ),
            // The contract's two signatures (#708, IT-16).
            $this->signedContractService = new \Modules\Rental\Service\RentalSignedContractService(
                $this->documentService,
                new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
                $this->personalSignatures = new \Core\Signature\PersonalSignatureService(
                    new \Core\Signature\PersonalSignatureRepository($this->pdo, $this->encryption),
                    $journal,
                    new \Core\Security\UserAccountRepository($this->pdo, $this->encryption)
                ),
                $bookingAudit,
                $this->recordingMailService(),
                new \Core\Pdf\PdfCompressor($this->storagePath . '/temp'),
                $journal,
                // Records what the signer is told (issue #926, decision Q1).
                $this->recordingNotifications(),
                null,
                null,
                $occupancyGuard,
                $this->modificationService
            ),
            $this->personalSignatures,
            // A contract the booking has outgrown is voided (#708, IT-20).
            new \Modules\Rental\Service\RentalContractValidityService(
                $this->documentService,
                new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
                $this->bookingRepository,
                $bookingAudit,
                $this->paymentService,
                new \Modules\Rental\Repository\RentalMilestoneMarkRepository($this->pdo),
                new \Modules\Rental\Repository\RentalReminderRepository($this->pdo)
            ),
            // « Valider, signer et envoyer » (#708, IT-17; #989, lot E), as
            // public/index.php wires it.
            new \Modules\Rental\Service\RentalInventoryValidationService(
                $this->stayService,
                $this->documentService,
                new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
                $this->recordingMailService(),
                new \Core\Pdf\DocumentPdfService(),
                $settingService,
                $bookingAudit,
                new \Modules\Rental\Service\InventoryPhotoRenderer($this->fileRepository, $this->storagePath),
                $this->personalSignatures,
                $journal,
                $this->recordingNotifications()
            ),
            // What the site sent the renter, on « Courrier » (#720, step 2).
            null,
            // Who signs a document is named by their account (#825).
            new \Modules\Rental\Document\SignerName(
                new \Core\Security\UserAccountRepository($this->pdo, $this->encryption)
            ),
            $this->closureService,
            // As public/index.php wires them (#989): every photo is
            // normalised before it is stored, and gets its thumbnail.
            \Core\Image\PhotoNormalizer::forThisServer(),
            new \Core\Photo\ImageVariantService(
                $this->fileRepository,
                new \Core\Photo\ImageVariantProcessor(),
                $this->storagePath
            )
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
        $this->documentEmails = [];
        $this->cancellationEmails = [];
    }

    /**
     * A mail service that records the decisions it was asked to send.
     *
     * Recording rather than an expectation: what matters is not that
     * sendDecision() was called some number of times, but which decision
     * reached it, with which manager's word, and — for the ones a renter
     * can still act on — with a token at all.
     */
    /** @var list<array{booking_id: int, label: string}> */
    private array $documentEmails = [];

    /** @var list<int> the bookings whose renter was told a modification was cancelled */
    private array $cancellationEmails = [];

    private \Modules\Rental\Service\RentalSignedContractService $signedContractService;
    private \Core\Signature\PersonalSignatureService $personalSignatures;

    /**
     * What the signed-contract service dispatched (issue #926, decision Q1).
     *
     * @var list<array{type: string, recipients: array<int, array{userAccountId: int, memberId: ?int}>, payload: array<string, mixed>}>
     */
    private array $notified = [];

    private function recordingNotifications(): \Core\Notification\NotificationService
    {
        $stub = $this->createStub(\Core\Notification\NotificationService::class);
        $stub->method('dispatch')->willReturnCallback(
            function (string $type, array $recipients, array $payload): void {
                $this->notified[] = ['type' => $type, 'recipients' => $recipients, 'payload' => $payload];
            }
        );

        return $stub;
    }

    private function recordingMailService(): \Modules\Rental\Service\RentalBookingMailService
    {
        $mock = $this->createStub(\Modules\Rental\Service\RentalBookingMailService::class);
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
        $mock->method('sendDocument')->willReturnCallback(
            function (
                \Modules\Rental\Booking\RentalBooking $booking,
                \Modules\Rental\Repository\RentalAsset $asset,
                string $documentLabel
            ): string {
                $this->documentEmails[] = ['booking_id' => $booking->id, 'label' => $documentLabel];

                return '<test@scoutmagic>';
            }
        );
        $mock->method('resend')->willReturnCallback(
            function (\Modules\Rental\Mail\SentEmail $sent, RentalBooking $booking, ?string $trackingToken, \Closure $attachmentOf): void {
                // As the real service does: every document read again first,
                // and the e-mail refused when one is gone.
                foreach ($sent->documentIds as $documentId) {
                    if ($attachmentOf($documentId) === null) {
                        throw new \Modules\Rental\Service\RentalException("Une pièce jointe de cet e-mail n'existe plus.");
                    }
                }
                $this->resends[] = ['sent_id' => $sent->id, 'booking_id' => $booking->id, 'token' => $trackingToken];
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
        $mock->method('sendModificationCancelled')->willReturnCallback(
            function (RentalBooking $booking): bool {
                $this->cancellationEmails[] = $booking->id;

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
        $_FILES = [];

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

    /**
     * The asset the scenarios work on, as the object
     * `RentalOperationsService::requestChange()` now takes — it validates a
     * renter's new dates against the asset's own rules rather than only
     * parsing them (IT-03).
     */
    private function asset(): RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    private function createAsset(string $name, string $slug): int
    {
        $id = $this->assetRepository->create('Local', $name, $slug, 60, 1, null, null, null, true);
        $this->pricingService->saveAssetPricing($id, 'per_night', 12000, null, null);
        // A contract is refused without the asset's address (issue #977).
        $this->assetRepository->saveContractDetails($id, 'Chemin du Camp 4, 1300 Wavre', 90);

        return $id;
    }

    private function addManager(int $assetId, string $email): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email . $assetId), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($assetId, $memberId, false);

        return $memberId;
    }

    private function createBooking(
        ?int $assetId = null,
        string $reference = 'LOC-A2B3C4',
        ?string $organisation = null
    ): RentalBooking {
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
                'organisation' => $organisation,
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
        // ...nor without the renter's (issue #977).
        $this->bookingRepository->saveBillingIdentity($created['id'], ['address' => 'Rue Haute 12, 1000 Bruxelles']);

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    /**
     * What a browser really posts for an `<input type="file">` the visitor
     * never touched: the part is still sent, as a $_FILES entry with an
     * empty name and UPLOAD_ERR_NO_FILE. That is "no file", not a failed
     * upload — verified against `php -S`.
     *
     * @var array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private const EMPTY_FILE_INPUT = [
        'name' => '',
        'type' => '',
        'tmp_name' => '',
        'error' => UPLOAD_ERR_NO_FILE,
        'size' => 0,
    ];

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
     * @param array<string, string> $params placeholders of $path, filled into the request path
     */
    private function post(string $path, string $action, array $body, array $params = []): Response
    {
        $body['_csrf_token'] ??= CsrfGuard::generateToken();
        $_POST = $body;

        $router = new Router();
        $router->addRoute('POST', $path, RentalManagementController::class, $action, 'identified');

        $requestPath = $path;
        foreach ($params as $name => $value) {
            $requestPath = str_replace('{' . $name . '}', $value, $requestPath);
        }

        return $this->dispatch($router, new Request('POST', $requestPath, [], $body, [], []));
    }

    private function complianceService(): \Modules\Rental\Service\RentalComplianceService
    {
        $this->assertNotNull($this->complianceService);

        return $this->complianceService;
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

    /**
     * One page of a booking's file, dispatched through the route
     * `module.json` really declares for it — breadcrumb included, which is
     * what makes the fil d'Ariane render at all (Core\Http\FrontController
     * reads it off the route).
     */
    /**
     * @param array<string, string> $query
     */
    private function filePage(BookingPage $page, string $slug, int $bookingId, array $query = []): Response
    {
        $routePath = '/mes-locations/{slug}/reservations/{id}' . $page->pathSuffix();
        $route = self::declaredRoute($routePath);

        $router = new Router();
        $router->addRoute('GET', $routePath, RentalManagementController::class, (string) $route['action'], 'identified', $route['breadcrumb'] ?? null);

        return $this->dispatch($router, new Request(
            'GET',
            $page->url('/mes-locations/' . $slug . '/reservations/' . $bookingId),
            $query,
            [],
            [],
            []
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function declaredRoute(string $path): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );
        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === $path && $route['method'] === 'GET') {
                return $route;
            }
        }
        self::fail("module.json declares no GET {$path}");
    }

    /**
     * A mailbox gathers mail for the rentals. Set on the
     * controller the setUp built rather than on a second one, so every
     * other collaborator stays the one the other tests use.
     */
    private function withCollectingMailbox(): \Modules\InboundMail\Api\InboundMailInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $inbound = $this->createMock(\Modules\InboundMail\Api\InboundMailInterface::class);
        $inbound->method('listMailboxSummariesFor')->willReturn([
            3 => ['name' => 'Locations', 'state' => 'OK', 'is_enabled' => true],
        ]);
        $this->withMailbox($inbound);

        return $inbound;
    }

    /**
     * Wires a mailbox into the controller's communication service.
     */
    private function withMailbox(\Modules\InboundMail\Api\InboundMailInterface $inbound): void
    {

        $service = new \Modules\Rental\Service\RentalCommunicationService(
            $this->bookingRepository,
            new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
            new RentalAuthorizationService(
                new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
                $this->assetRepository,
                $this->managerRepository
            ),
            new JournalService(new JournalRepository($this->pdo)),
            $inbound,
            null,
            new \Modules\Rental\Repository\RentalMailReadRepository($this->pdo)
        );
        (new \ReflectionProperty(RentalManagementController::class, 'communicationService'))
            ->setValue($this->controller, $service);
    }

    // ── « Courrier », one booking's own mail (#720) ─────────────────────

    /**
     * Messages 7 (filed nowhere), 8 (filed under the other asset's
     * booking) and 9 (filed under this manager's), and the two bookings.
     *
     * @return array{\Tests\Modules\InboundMail\InMemoryTriageMail, RentalBooking, RentalBooking}
     */
    private function mailAcrossTwoAssets(): array
    {
        $mail = new \Tests\Modules\InboundMail\InMemoryTriageMail(
            \Tests\Modules\InboundMail\InMemoryTriageMail::aMessage(7, 'Une question'),
            \Tests\Modules\InboundMail\InMemoryTriageMail::aMessage(8, 'Pour le chalet'),
            \Tests\Modules\InboundMail\InMemoryTriageMail::aMessage(9, 'Pour le local')
        );
        $this->loginAsManager();
        $this->withMailbox($mail);
        $mine = $this->createBooking();
        $theirs = $this->createBooking($this->otherAssetId, 'LOC-K7Q2MX');
        $mail->link(8, 'rental', $theirs->reference);
        $mail->link(9, 'rental', $mine->reference);

        return [$mail, $mine, $theirs];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function mailboxConfigurations(): array
    {
        return [
            'a box gathering mail for rentals, dedicated or shared' => [true],
            'no box gathering mail for rentals' => [false],
        ];
    }

    /**
     * The page is there whatever the mailbox configuration (#720):
     * dedicated, shared or none — and without a box it says how replies
     * would reach it rather than disappearing.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxConfigurations')]
    public function testTheCourrierPageIsThereWhateverTheMailboxes(bool $collects): void
    {
        [$mail, $mine] = $this->mailAcrossTwoAssets();
        $mail->collects = $collects;

        $dashboard = (string) $this->bookingPage('local-saint-georges', $mine->id)->getBody();
        $this->assertStringContainsString('/reservations/' . $mine->id . '/courrier"', $dashboard);

        $response = $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id);
        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        if ($collects) {
            $this->assertStringNotContainsString('data-mail-not-collected', $body);
        } else {
            $this->assertStringContainsString('Aucune boîte e-mail ne relève le courrier des locations', $body);
        }
        $this->assertStringNotContainsString('Cette page existe', $body);
    }

    public function testWithoutTheInboundMailModuleThePageIsStillThereAndSaysSo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("Le module « Courrier entrant » n'est pas actif", (string) $response->getBody());
        $this->assertStringContainsString('Aucun message pour cette réservation.', (string) $response->getBody());
    }

    /**
     * Only what the rules filed under THIS booking: not the other asset's
     * mail, not the mail filed nowhere — and nothing to sort, attach,
     * set aside or confirm.
     */
    public function testThePageShowsThisBookingsMailAndNothingElse(): void
    {
        [, $mine] = $this->mailAcrossTwoAssets();

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id)->getBody();

        $this->assertStringContainsString('data-mail-entry="9"', $body);
        $this->assertStringNotContainsString('data-mail-entry="8"', $body);
        $this->assertStringNotContainsString('data-mail-entry="7"', $body);
        $this->assertStringContainsString('Reçu', $body);
        $this->assertStringContainsString('Lire le message', $body);
        // Filed on the reference here: certain, so no warning.
        $this->assertStringNotContainsString('Rattachement incertain', $body);
        foreach (['Rattacher', 'Écarter', "Relancer l'analyse", 'Propositions', '/mes-locations/courrier/rattacher'] as $gone) {
            $this->assertStringNotContainsString($gone, $body);
        }
    }

    public function testAMessageFiledOnTheSenderAloneSaysItIsAGuess(): void
    {
        $mail = new \Tests\Modules\InboundMail\InMemoryTriageMail(
            \Tests\Modules\InboundMail\InMemoryTriageMail::aMessage(9, 'Pour le local')
        );
        $this->loginAsManager();
        $this->withMailbox($mail);
        $booking = $this->createBooking();
        $mail->linkAs(9, 'rental', $booking->reference, \Modules\InboundMail\Api\LinkOrigin::SENDER);

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('Rattachement incertain', $body);
    }

    public function testWhatTheUnitSentFromItsBoxIsShownAsSentToTheRenter(): void
    {
        $mail = new \Tests\Modules\InboundMail\InMemoryTriageMail(
            \Tests\Modules\InboundMail\InMemoryTriageMail::aMessage(9, 'Pour le local'),
            \Tests\Modules\InboundMail\InMemoryTriageMail::aSentMessage(10, 'Les clés')
        );
        $this->loginAsManager();
        $this->withMailbox($mail);
        $booking = $this->createBooking();
        $mail->linkAs(9, 'rental', $booking->reference, \Modules\InboundMail\Api\LinkOrigin::REFERENCE);
        $mail->linkAs(10, 'rental', $booking->reference, \Modules\InboundMail\Api\LinkOrigin::RECIPIENT);

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getBody();

        $this->assertMatchesRegularExpression('#data-mail-entry="10" data-mail-direction="sent"#', $body);
        $this->assertMatchesRegularExpression('#data-mail-entry="9" data-mail-direction="received"#', $body);
        $this->assertStringContainsString('À : j.leroy@example.be', $body);
        $this->assertStringContainsString('Rattachement incertain · Adresse du destinataire', $body);
    }

    public function testAMessageTheUnitSentIsNeverAnUnreadOne(): void
    {
        $mail = new \Tests\Modules\InboundMail\InMemoryTriageMail(
            \Tests\Modules\InboundMail\InMemoryTriageMail::aSentMessage(10, 'Les clés')
        );
        $this->loginAsManager();
        $this->withMailbox($mail);
        $booking = $this->createBooking();
        $mail->linkAs(10, 'rental', $booking->reference, \Modules\InboundMail\Api\LinkOrigin::RECIPIENT);

        $this->assertSame([], $mail->countLinksAfter('rental', [$booking->reference => 0]));
    }

    public function testDetachAsksFirst(): void
    {
        [, $mine] = $this->mailAcrossTwoAssets();

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id)->getBody();

        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/mes-locations/courrier/detacher"\s+data-confirm="Ce message ne concerne pas cette réservation \?#',
            $body
        );
    }

    /**
     * « Détacher » takes the message off this booking for good: the
     * exclusion is asked of inbound_mail, and the page no longer shows it.
     */
    public function testDetachingTakesTheMessageOffThisBookingForGood(): void
    {
        [$mail, $mine] = $this->mailAcrossTwoAssets();

        $response = $this->detachPost($mine, 9);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/courrier', (string) $response->getHeaders()['Location']);
        $this->assertSame([['rental', $mine->reference, 9]], $mail->exclusions);
        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id)->getBody();
        $this->assertStringNotContainsString('data-mail-entry="9"', $body);
    }

    public function testAMessageOfAnotherBookingCannotBeDetachedFromThisOne(): void
    {
        [$mail, $mine, $theirs] = $this->mailAcrossTwoAssets();

        $this->detachPost($mine, 8);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $mail->exclusions);
        $this->assertNotNull($mail->findOneForReference('rental', $theirs->reference, 8));
    }

    public function testABookingOfAnAssetTheyDoNotManageIsNotFound(): void
    {
        [$mail, , $theirs] = $this->mailAcrossTwoAssets();

        $response = $this->detachPost($theirs, 8);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $mail->exclusions);
    }

    /** The routes of the old triage screen are gone, the detach stays. */
    public function testTheTriageRoutesAreGone(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );
        $paths = array_column($manifest['routes'], 'path');

        $this->assertContains('/mes-locations/courrier/detacher', $paths);
        foreach (['rattacher', 'ecarter', 'reprendre', 'relancer', 'proposition/confirmation', 'proposition/rejet'] as $gone) {
            $this->assertNotContains('/mes-locations/courrier/' . $gone, $paths);
        }
    }

    // ── « Autres adresses du locataire » (#720, step 5) ─────────────────

    /** The service with its journal, as public/index.php wires it. */
    private function withAuditedBookingService(): void
    {
        (new \ReflectionProperty(RentalManagementController::class, 'bookingService'))->setValue(
            $this->controller,
            new RentalBookingService(
                $this->bookingRepository,
                new JournalService(new JournalRepository($this->pdo)),
                null,
                RentalTestHelper::bookingAudit($this->pdo, $this->encryption)
            )
        );
    }

    /** @param array<string, string> $fields */
    private function addressPost(string $action, RentalBooking $booking, array $fields): Response
    {
        $path = $action === 'addOtherRenterEmail' ? '/mes-locations/courrier/adresse-ajouter' : '/mes-locations/courrier/adresse-retirer';

        return $this->post($path, $action, [
            'asset_id' => (string) $booking->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
        ] + $fields);
    }

    public function testTheCourrierPageListsTheOtherAddressesAndWhichWereLearned(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'partenaire@maison.example');
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@groupe.example', 9);

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('Autres adresses du locataire', $body);
        $this->assertStringContainsString('partenaire@maison.example', $body);
        $this->assertStringContainsString('tresorier@groupe.example', $body);
        $this->assertSame(1, substr_count($body, 'ajoutée automatiquement'));
        $this->assertStringContainsString('action="/mes-locations/courrier/adresse-ajouter"', $body);
        $this->assertMatchesRegularExpression(
            '#action="/mes-locations/courrier/adresse-retirer" class="ms-auto"\s+data-confirm="Retirer partenaire@maison.example \?#',
            $body
        );
    }

    public function testWithNoOtherAddressThePageSaysSo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('Aucune autre adresse.', $body);
    }

    public function testAManagerAddsAnAddressAndTheHistorySaysSo(): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $booking = $this->createBooking();

        $response = $this->addressPost('addOtherRenterEmail', $booking, ['email' => ' Tresorier@Groupe.example ']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/courrier', (string) $response->getHeaders()['Location']);
        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertSame(['tresorier@groupe.example'], array_map(static fn($o) => $o->email, $others));
        $this->assertFalse($others[0]->wasLearned());

        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id);
        $last = end($history);
        $this->assertNotFalse($last);
        $this->assertSame(\Modules\Rental\Audit\BookingAudit::OTHER_EMAIL_CHANGED, $last->fieldKey);
        $this->assertNull($last->fromValue);
        $this->assertSame('tresorier@groupe.example', $last->toValue);
    }

    /** @return array<string, array{string}> */
    public static function refusedAddresses(): array
    {
        return [
            'not an address' => ['tresorier'],
            "the renter's own" => ['JEANNE@example.be'],
            'already listed' => ['partenaire@maison.example'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAddresses')]
    public function testAnAddressThatAddsNothingIsRefused(string $email): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'partenaire@maison.example');

        $this->addressPost('addOtherRenterEmail', $booking, ['email' => $email]);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertCount(1, $this->bookingRepository->otherRenterEmails($booking->id));
    }

    public function testAManagerRemovesALearnedAddress(): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@groupe.example', 9);
        $id = $this->bookingRepository->otherRenterEmails($booking->id)[0]->id;

        $this->addressPost('removeOtherRenterEmail', $booking, ['other_email_id' => (string) $id]);

        $this->assertSame([], $this->bookingRepository->otherRenterEmails($booking->id));
        $this->assertSame([], $this->bookingRepository->findByRenterEmail('tresorier@groupe.example'));
        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id);
        $last = end($history);
        $this->assertNotFalse($last);
        $this->assertSame('tresorier@groupe.example', $last->fromValue);
        $this->assertNull($last->toValue);
    }

    public function testAnAddressOfAnotherBookingCannotBeRemovedThroughThisOne(): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $mine = $this->createBooking();
        $other = $this->createBooking(null, 'LOC-A2B3C5');
        $this->bookingRepository->addRenterEmail($other->id, 'tresorier@groupe.example');
        $id = $this->bookingRepository->otherRenterEmails($other->id)[0]->id;

        $this->addressPost('removeOtherRenterEmail', $mine, ['other_email_id' => (string) $id]);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertCount(1, $this->bookingRepository->otherRenterEmails($other->id));
    }

    public function testNobodyAddsAnAddressToABookingOfAnAssetTheyDoNotManage(): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $theirs = $this->createBooking($this->otherAssetId, 'LOC-K7Q2MX');

        $response = $this->addressPost('addOtherRenterEmail', $theirs, ['email' => 'intrus@ailleurs.example']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->bookingRepository->otherRenterEmails($theirs->id));
    }

    // ── What the site sent the renter (#720, step 2) ────────────────────

    private function withSentLog(): \Modules\Rental\Repository\RentalSentEmailRepository
    {
        $log = new \Modules\Rental\Repository\RentalSentEmailRepository($this->pdo, $this->encryption);
        (new \ReflectionProperty(RentalManagementController::class, 'sentEmails'))->setValue($this->controller, $log);

        return $log;
    }

    /**
     * @param list<int> $documentIds
     */
    private function logEmail(
        \Modules\Rental\Repository\RentalSentEmailRepository $log,
        RentalBooking $booking,
        string $status,
        string $subject = '[LOC-A2B3C4] Votre demande de location',
        string $kind = 'rental.acknowledgement',
        array $documentIds = []
    ): int {
        return $log->record(
            $booking->id,
            $kind,
            'jeanne@example.be',
            $subject,
            "Bonjour,\nVotre lien : " . \Modules\Rental\Mail\SentEmail::MASKED_LINK,
            '<p>Bonjour</p>',
            $documentIds,
            '<m1@unite.test>',
            $status,
            new \DateTimeImmutable('2027-06-01 10:00:00')
        );
    }

    public function testWhatTheSiteSentIsOnTheCourrierPageEvenWithoutTheInboundMailModule(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_SENT);

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('data-sent-entry=', $body);
        $this->assertStringContainsString('Envoyé', $body);
        $this->assertStringContainsString('À jeanne@example.be', $body);
        $this->assertStringContainsString('[LOC-A2B3C4] Votre demande de location', $body);
        $this->assertStringContainsString(\Modules\Rental\Mail\SentEmail::MASKED_LINK_LABEL, $body);
        $this->assertStringNotContainsString('/mes-locations/courrier/renvoyer', $body, 'a sent e-mail has nothing to resend');
        // The page's own dialog: no template of the absent module.
        $this->assertStringContainsString('id="mail-message-modal"', $body);
    }

    public function testAnEmailThatFailedIsMarkedAndOffersRenvoyer(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_FAILED);

        $body = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('Non envoyé', $body);
        $this->assertStringContainsString('action="/mes-locations/courrier/renvoyer"', $body);
        $this->assertStringContainsString('name="sent_email_id" value="' . $id . '"', $body);
    }

    public function testRenvoyerSendsItAgainWithTheBookingsCurrentLink(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_FAILED);

        $response = $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $this->assertStringEndsWith('/courrier', (string) $response->getHeaders()['Location']);
        $this->assertCount(1, $this->resends);
        $this->assertSame($id, $this->resends[0]['sent_id']);
        $this->assertNotNull($this->resends[0]['token'], 'the current tracking link goes back in');
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    public function testAnotherBookingsEmailIsNotResentFromThisOne(): void
    {
        $this->loginAsManager();
        $mine = $this->createBooking();
        $other = $this->createBooking(null, 'LOC-A2B3C5');
        $id = $this->logEmail($this->withSentLog(), $other, \Modules\Rental\Mail\SentEmail::STATUS_FAILED);

        $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $mine->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $this->assertSame([], $this->resends);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    public function testAnEmailThatWentOutCannotBeResentByARequestNamingIt(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_SENT);

        $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $this->assertSame([], $this->resends);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('déjà parti', (string) ($flash['message'] ?? ''));
    }

    /**
     * « Renvoyer » at both sides of its role boundary: the route is
     * `identified`, and what keeps it to the asset's managers is the
     * asset check behind it (§22.9).
     */
    public function testAContractThatFinallyGoesOutIsRecordedAsSent(): void
    {
        // Its first send failed: nothing marked it sent, the booking never
        // reached « Contrat envoyé ». « Renvoyer » is that first send.
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->forBooking($booking->id)[0];
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_FAILED, 'Contrat', 'rental.contract', [$contract->id]);
        \Core\Http\FlashMessage::get();

        $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertCount(1, $this->resends);
        $this->assertTrue($this->documentService->find($contract->id)?->hasBeenSent());
        $this->assertSame(BookingStatus::CONTRACT_SENT, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testAVoidContractIsNotResentFromTheCourrierPageEither(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->forBooking($booking->id)[0];
        (new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo))->markSuperseded([$contract->id], new \DateTimeImmutable());
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_FAILED, 'Contrat', 'rental.contract', [$contract->id]);
        \Core\Http\FlashMessage::get();

        $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $refusal = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $refusal['type'] ?? null);
        $this->assertStringContainsString('remplacé', $refusal['message'] ?? '');
        $this->assertSame([], $this->resends);
        $this->assertNotSame(BookingStatus::CONTRACT_SENT, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testAnAnonymousVisitorCannotAddAnAddressToABooking(): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $booking = $this->createBooking();
        AuthSession::logout();

        $response = $this->addressPost('addOtherRenterEmail', $booking, ['email' => 'intrus@ailleurs.example']);

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertSame([], $this->bookingRepository->otherRenterEmails($booking->id));
    }

    public function testAnAnonymousVisitorCannotRemoveAnAddressFromABooking(): void
    {
        $this->loginAsManager();
        $this->withAuditedBookingService();
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@groupe.example', 9);
        $id = $this->bookingRepository->otherRenterEmails($booking->id)[0]->id;
        AuthSession::logout();

        $response = $this->addressPost('removeOtherRenterEmail', $booking, ['other_email_id' => (string) $id]);

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertCount(1, $this->bookingRepository->otherRenterEmails($booking->id));
    }

    public function testAnAnonymousVisitorCannotResendAnything(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_FAILED);
        AuthSession::logout();

        $response = $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertSame([], $this->resends);
    }

    public function testAnIdentifiedMemberWhoManagesNothingCannotResend(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->logEmail($this->withSentLog(), $booking, \Modules\Rental\Mail\SentEmail::STATUS_FAILED);
        AuthSession::logout();
        AuthSession::login(9, 'nobody@test.be', 'identified');

        $response = $this->post('/mes-locations/courrier/renvoyer', 'resendEmail', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'sent_email_id' => (string) $id,
        ]);

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame([], $this->resends);
        $this->assertNotSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    // ── « Non lus », per person (#720) ──────────────────────────────────

    public function testTheCourrierChipCountsWhatWasFiledSinceThePersonLastLooked(): void
    {
        [$mail, $mine] = $this->mailAcrossTwoAssets();

        $this->assertStringContainsString('<span>Courrier (1)</span>', $this->dashboardOf($mine));

        // Opening the page reads it — and its own chip does not announce
        // what is already on the screen.
        $page = (string) $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id)->getBody();
        $this->assertStringContainsString('<span>Courrier</span>', $page);
        $this->assertStringContainsString('<span>Courrier</span>', $this->dashboardOf($mine));

        $mail->link(7, 'rental', $mine->reference);
        $this->assertStringContainsString('<span>Courrier (1)</span>', $this->dashboardOf($mine));
    }

    public function testReadingIsEachPersonsOwn(): void
    {
        [, $mine] = $this->mailAcrossTwoAssets();
        $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id);

        // A second manager of the same hall still has the message to read.
        $this->addManager($this->assetId, 'second@test.be');
        AuthSession::login(2, 'second@test.be', 'identified');

        $this->assertStringContainsString('<span>Courrier (1)</span>', $this->dashboardOf($mine));
    }

    public function testTheOverviewListsTheBookingsWithNewMessages(): void
    {
        [, $mine] = $this->mailAcrossTwoAssets();

        $body = (string) $this->overview('local-saint-georges')->getBody();
        $this->assertStringContainsString('data-unread-mail', $body);
        $this->assertStringContainsString('href="/mes-locations/local-saint-georges/reservations/' . $mine->id . '/courrier"', $body);
        $this->assertStringContainsString('1 nouveau message', $body);

        $this->filePage(BookingPage::MAIL, 'local-saint-georges', $mine->id);
        $this->assertStringNotContainsString('data-unread-mail', (string) $this->overview('local-saint-georges')->getBody());
    }

    public function testABookingToDealWithCarriesItsUnreadBadgeToo(): void
    {
        [, $mine] = $this->mailAcrossTwoAssets();   // a new request: « À traiter »

        $body = (string) $this->overview('local-saint-georges')->getBody();

        $this->assertMatchesRegularExpression('#data-unread-badge>\s*<i class="bi bi-envelope" aria-hidden="true"></i>\s*1<span class="visually-hidden"> nouveau message</span>#', $body);
    }

    private function dashboardOf(RentalBooking $booking): string
    {
        return (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
    }

    private function detachPost(RentalBooking $booking, int $messageId): Response
    {
        return $this->post('/mes-locations/courrier/detacher', 'detachMessage', [
            'asset_id' => (string) $booking->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'mail',
            'message_id' => (string) $messageId,
        ]);
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

    // ── « À traiter » is not a filter on the status (§22.5) ─────────────

    /**
     * A manager recognises a booking by the person, not by its code: the
     * renter's name comes first, with their organisation, and the reference
     * moves to the second line (#708, IT-06).
     */
    public function testTheOverviewListNamesTheRenterBeforeTheReference(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $booking = $this->createBooking(organisation: 'Patro Saint-Jean');

        $body = (string) $this->overview('local-saint-georges')->getBody();

        $name = strpos($body, 'Jeanne Martin');
        $this->assertNotFalse($name);
        $this->assertStringContainsString('Patro Saint-Jean', $body);
        $this->assertGreaterThan($name, strpos($body, $booking->reference, $name));
    }

    /**
     * The tile and the list under it cannot disagree: both are counted from
     * Booking\BookingAttention. A « 0 » over a list of one is the failure
     * this pins.
     */
    public function testTheFigureAgreesWithTheListBelowIt(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $booking = $this->createBooking();

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->overview('local-saint-georges')->getBody());

        // The list holds exactly one row, and the tile says so.
        $this->assertStringContainsString($booking->reference, $body);
        $this->assertSame(1, substr_count($body, 'Une décision est attendue de vous'));
        $this->assertMatchesRegularExpression('/À traiter.*?>1</s', $body);
    }

    /**
     * A confirmed booking with a step of the unit's left — here the
     * contract to generate — stays on « À traiter », and the line names the step
     * (#708, IT-12). It used to vanish the moment it was confirmed.
     */
    public function testAConfirmedBookingWithAUnitStepLeftIsOnTheList(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $booking = $this->createBooking();
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CONFIRMED, new \DateTimeImmutable());

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->overview('local-saint-georges')->getBody());

        $this->assertStringContainsString('À faire : générer le contrat', $body);
        $this->assertStringNotContainsString('Aucune demande en attente.', $body);
    }

    /** A final booking is never on the list, whatever its steps say. */
    public function testAClosedBookingStaysOffTheList(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $booking = $this->createBooking();
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CLOSED, new \DateTimeImmutable());

        $this->assertStringContainsString('Aucune demande en attente.', (string) $this->overview('local-saint-georges')->getBody());
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
            ['/mes-locations/statut', 'changeStatus', ['status' => 'info_requested']],
            ['/mes-locations/option', 'placeOption', ['until' => '2027-06-01T18:00']],
            ['/mes-locations/commentaire', 'addComment', ['body' => 'Interne']],
            [
                '/mes-locations/modification',
                'saveModification',
                ['arrival' => '2027-07-02', 'departure' => '2027-07-05'],
            ],
            ['/mes-locations/modification-envoyer', 'sendModification', ['modification_id' => '1']],
            ['/mes-locations/modification-annuler', 'cancelModification', ['modification_id' => '1']],
            ['/mes-locations/document-texte', 'saveDocumentText', ['document_type' => 'contract', 'body' => 'x']],
            ['/mes-locations/document-generer', 'generateDocument', ['document_type' => 'contract']],
            ['/mes-locations/document-envoyer', 'sendDocument', ['document_id' => '1']],
            ['/mes-locations/document-supprimer', 'deleteDocument', ['document_id' => '1']],
            ['/mes-locations/facturation', 'saveBillingIdentity', ['billing_name' => 'x']],
            ['/mes-locations/releve', 'recordReading', ['meter_id' => '1', 'phase' => 'arrival', 'value' => '1000']],
            ['/mes-locations/etat-des-lieux/ligne', 'saveInventoryLine', ['inventory_id' => '1', 'phase' => 'arrival', 'value' => '1']],
            ['/mes-locations/etat-des-lieux/valider', 'validateInventory', ['phase' => 'arrival']],
            ['/mes-locations/incident', 'reportIncident', ['description' => 'x']],
            ['/mes-locations/incident/photo', 'addIncidentPhotos', ['incident_id' => '1']],
            ['/mes-locations/incident/photo/retirer', 'removeIncidentPhoto', ['photo_id' => '1']],
            ['/mes-locations/releve/photo/retirer', 'removeReadingPhoto', ['meter_id' => '1', 'phase' => 'arrival']],
            ['/mes-locations/incident-decision', 'decideIncident', ['incident_id' => '1', 'decision' => 'charge']],
            ['/mes-locations/decompte', 'recordSettlement', ['final_persons' => '10']],
            ['/mes-locations/decompte-valider', 'validateSettlement', ['settlement_id' => '1']],
            ['/mes-locations/decompte-actualiser', 'recordSettlementFromValidated', []],
            ['/mes-locations/facture-complementaire', 'generateSupplementaryInvoice', []],
            ['/mes-locations/virement-chercher', 'searchTransfer', ['amount' => '300']],
            ['/mes-locations/virement-rapprocher', 'reconcileTransfer', ['transaction_id' => '1', 'amount_cents' => '1']],
            ['/mes-locations/virement-detacher', 'detachTransfer', ['transaction_id' => '1']],
            [
                '/mes-locations/cloturer-non-recouvre',
                'closeUnrecovered',
                ['unrecovered_cents' => '100', 'reason' => 'Locataire injoignable'],
            ],
        ];

        foreach ($actions as [$path, $action, $body]) {
            $response = $this->post($path, $action, array_merge($body, [
                'asset_id' => (string) $this->assetId,
                'booking_id' => (string) $booking->id,
            ]));
            $this->assertSame(404, $response->getStatusCode(), $path . ' must be refused.');
        }

        $this->assertSame(404, $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [$this->futureDay(10)],
        ])->getStatusCode());
        $this->assertSame([], $this->blockRepository->findAllForAsset($this->assetId));
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($booking->id)?->status);

        // A period's reason too: an id in a POST is no authorisation.
        $blockId = $this->blockRepository->create($this->assetId, $this->futureDay(10), $this->futureDay(12), 'Camp', null);
        $this->assertSame(404, $this->post('/mes-locations/blocage-motif', 'blockReason', [
            'asset_id' => (string) $this->assetId,
            'block_id' => (string) $blockId,
            'reason' => 'Pris',
        ])->getStatusCode());
        $this->assertSame('Camp', $this->blockRepository->findById($blockId)?->reason);
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
            'status' => 'info_requested',
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
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $this->assertSame(404, $this->bookingPage('local-saint-georges', $foreign->id)->getStatusCode());
    }

    public function testAWriteAgainstAnotherAssetsBookingIsA404(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');
        AuthSession::login(1, 'manager@test.be', 'identified');

        $response = $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $foreign->id,
            'status' => 'info_requested',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($foreign->id)?->status);
    }

    public function testABlockCannotBeDeletedThroughAnAssetTheManagerDoesControl(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        $foreignBlockId = $this->blockRepository->create($this->otherAssetId, '2027-09-01', '2027-09-05', null, null);
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
            'status' => 'info_requested',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(BookingStatus::INFO_REQUESTED, $this->bookingRepository->findById($booking->id)?->status);
    }

    /**
     * A confirmed booking with a receivable raised against it and nothing
     * received — the ordinary state of a rental a fortnight before the
     * stay.
     */
    private function bookingOwedFor(int $totalCents = 46750, ?int $securityDepositCents = null): RentalBooking
    {
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: true,
            financeAccountId: $this->financeAccountId,
            securityDepositEnabled: $securityDepositCents !== null,
            securityDepositAmountCents: $securityDepositCents,
            financeCategoryId: $this->financeCategoryId
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
        $this->paymentService->ensureReceivable($fresh, $this->paymentService->settingsFor($this->assetId));

        return $fresh;
    }

    public function testCancellingABookingBringsItsReceivableToZeroAndSaysSo(): void
    {
        // §6.17 is explicit that nothing computes a refund — but the
        // receivable goes to 0 and stays (issue #927), and what was paid is
        // now an overpayment the manager must hear about.
        $this->loginAsManager();
        $booking = $this->bookingOwedFor();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'cancelled',
        ]);

        $flash = (string) (\Core\Http\FlashMessage::get()['message'] ?? '');
        $this->assertStringContainsString('ramenée à 0', $flash);
        $this->assertStringContainsString('trop-perçu', $flash);
        $this->assertSame(0, (int) $this->fetchScalar(
            'SELECT amount_due_cents FROM finance_expected_receivables WHERE source_module = ?',
            ['rental']
        ));
    }

    public function testAnOrdinaryTransitionSaysNothingAboutMoney(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'info_requested',
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
        $this->completeTheAgreement($booking);

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
        // Putting a request back on hold is the unit's own bookkeeping.
        // That is not news, and an email saying so would train the renter
        // to ignore the ones that are.
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'info_requested',
        ]);
        $this->renterEmails = [];

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'received',
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

    /**
     * The field says which hold runs (#708, IT-01): saving a date over the
     * automatic one makes it an option, which ends differently.
     */
    public function testTheOptionFieldSaysTheAutomaticHoldRunsAndSavingMakesAnOption(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $until = new \DateTimeImmutable('+10 days 14:00');
        $this->bookingRepository->setHold($booking->id, $until, HoldOrigin::AUTOMATIC);

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody());
        $this->assertStringContainsString('value="' . $until->format('Y-m-d\TH:i') . '"', $body);
        $this->assertStringContainsString('Cette date est celle du <strong>blocage automatique</strong>', $body);

        $this->post('/mes-locations/option', 'placeOption', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'until' => $until->format('Y-m-d\TH:i'),
        ]);
        $this->assertSame(HoldOrigin::MANAGER, $this->bookingRepository->findById($booking->id)?->holdOrigin);
    }

    /**
     * A lapsed automatic hold warns, and the field offers the deadline that
     * lapsed (#927): putting the option back starts from where it stood,
     * under the same check as any other writer of the dates.
     */
    public function testALapsedAutomaticHoldWarnsAndOffersItsDeadlineAgain(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $lapsed = new \DateTimeImmutable('-2 days 14:00');
        $this->bookingRepository->setHold($booking->id, $lapsed, HoldOrigin::AUTOMATIC);

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody());

        $this->assertStringContainsString('Les dates ne sont plus bloquées depuis le ' . $lapsed->format('d/m/Y'), $body);
        $this->assertStringContainsString('value="' . $lapsed->format('Y-m-d\TH:i') . '"', $body);
        $this->assertStringNotContainsString("L'option sur les dates est échue", $body);
    }

    /**
     * Nobody on the asset can be told about a request (#708, IT-05): the
     * overview says the Staff d'U gets them, and how to fix it.
     */
    public function testTheOverviewWarnsWhenNoManagerCanBeTold(): void
    {
        $this->loginAsManager();
        // loginAsManager()'s manager has no user account in this suite.
        $this->assertStringContainsString('data-managers-unreachable', (string) $this->overview('local-saint-georges')->getBody());

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')->execute([
            $this->encryption->encrypt('manager@test.be', 'user_accounts.email'),
            $this->encryption->blindIndex('manager@test.be', 'email'),
        ]);

        $this->assertStringNotContainsString('data-managers-unreachable', (string) $this->overview('local-saint-georges')->getBody());
    }

    /**
     * Before any contract has gone out, a change applies at once (#927,
     * chantier §2.3): the tariff's lines for the stay, and one discount —
     * typed with the French decimal comma an operator actually types.
     */
    public function testAManagerEditsThePriceAndTheRenterSeesTheNewTotal(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => '20',
            'discount_label' => 'Remise exceptionnelle',
            'discount_amount' => '-50,00',
        ]);
        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertSame(31000, $fresh?->effectiveTotalCents());
        $discount = \Modules\Rental\Pricing\QuoteEditor::discountLine($fresh?->effectivePrice());
        $this->assertSame('Remise exceptionnelle', $discount?->label);
        // Kept in the history, applied directly, and no e-mail.
        $history = $this->modificationRepository->findForBooking($booking->id);
        $this->assertCount(1, $history);
        $this->assertSame(\Modules\Rental\Booking\ModificationStatus::APPLIED_DIRECTLY, $history[0]->status);
        $this->assertSame([], $this->renterEmails);
    }

    /**
     * The discount is the amount deducted, typed without a sign: a phone's
     * decimal keypad has no minus key, so « 50,00 » and « -50,00 » are the
     * same fifty euros off, stored as a negative line either way.
     *
     * @return array<string, array{string}>
     */
    public static function typedDiscounts(): array
    {
        return ['without its sign' => ['50,00'], 'with a minus' => ['-50,00']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('typedDiscounts')]
    public function testADiscountTypedWithOrWithoutItsSignIsTheSameAmountDeducted(string $typed): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => '20',
            'discount_label' => 'Remise fidélité',
            'discount_amount' => $typed,
        ]);
        $fresh = $this->bookingRepository->findById($booking->id);
        $discount = \Modules\Rental\Pricing\QuoteEditor::discountLine($fresh?->effectivePrice());
        $this->assertSame(-5000, $discount?->amountCents);
        $this->assertSame(31000, $fresh?->effectiveTotalCents());
    }

    /** The form shows the discount in force as the amount deducted, without its sign. */
    public function testTheModificationFormShowsTheDiscountInForceWithoutItsSign(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => '20',
            'discount_label' => 'Remise fidélité',
            'discount_amount' => '-50,00',
        ]);

        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);
        $page = (string) $this->modificationPage($fresh, 'nouvelle')->getBody();
        $this->assertStringContainsString('Montant déduit (€)', $page);
        $this->assertMatchesRegularExpression(
            '/name="discount_amount"[^>]*value="50,00"|value="50,00"[^>]*name="discount_amount"/',
            $page
        );
        $this->assertStringNotContainsString('value="-50,00"', $page);
    }

    /** Garbage is still refused, with its reason; nothing changes. */
    public function testADiscountThatIsNotAnAmountIsRefused(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $before = $booking->effectiveTotalCents();
        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => '20',
            'discount_amount' => 'cinquante',
        ]);
        $this->assertSame($before, $this->bookingRepository->findById($booking->id)?->effectiveTotalCents());
        $this->assertSame([], $this->modificationRepository->findForBooking($booking->id));
    }

    // ── Blocking dates on the calendar (#708, IT-07) ────────────────────

    public function testDaysBlockedOnTheCalendarBecomeOnePeriodWithoutAReason(): void
    {
        $this->loginAsManager();

        $response = $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [$this->futureDay(10), $this->futureDay(11), $this->futureDay(12)],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(
            [$this->futureDay(10) => null, $this->futureDay(11) => null, $this->futureDay(12) => null],
            $payload['changed']
        );
        $this->assertStringContainsString('Motif', $payload['list']);

        $blocks = $this->blockRepository->findAllForAsset($this->assetId);
        $this->assertCount(1, $blocks);
        $this->assertSame($this->futureDay(10), $blocks[0]->startDate);
        $this->assertSame($this->futureDay(12), $blocks[0]->endDate);
        $this->assertNull($blocks[0]->reason);
    }

    /**
     * The list under the grid is re-rendered after a gesture with the
     * window the page drew it with — the month on screen, not today.
     */
    public function testTheListAfterAGestureKeepsTheWindowOfTheMonthOnScreen(): void
    {
        $this->loginAsManager();
        $this->blockRepository->create($this->assetId, $this->futureDay(10), $this->futureDay(12), 'Camp', null);
        $month = (new \DateTimeImmutable('first day of +2 months'));

        $payload = json_decode((string) $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [$month->modify('+3 days')->format('Y-m-d')],
            'month' => $month->format('Y-m'),
        ])->getBody(), true);
        $this->assertStringNotContainsString('Camp', $payload['list'], 'A block ended before that month stays out.');

        $payload = json_decode((string) $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [$this->futureDay(20)],
        ])->getBody(), true);
        $this->assertStringContainsString('Camp', $payload['list'], 'With no month, the window is this one.');
    }

    public function testReleasingTheMiddleOfAPeriodCutsItInTwoKeepingTheReason(): void
    {
        $this->loginAsManager();
        $this->blockRepository->create($this->assetId, $this->futureDay(10), $this->futureDay(14), 'Camp', null);

        $response = $this->postCalendarDays('local-saint-georges', [
            'mode' => 'release',
            'days' => [$this->futureDay(12)],
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame([$this->futureDay(12) => 'Camp'], $payload['changed']);

        $blocks = $this->blockRepository->findAllForAsset($this->assetId);
        $this->assertSame(
            [[$this->futureDay(10), $this->futureDay(11), 'Camp'], [$this->futureDay(13), $this->futureDay(14), 'Camp']],
            array_map(static fn($b) => [$b->startDate, $b->endDate, $b->reason], $blocks)
        );
    }

    public function testUndoingAReleaseWithItsReasonsRebuildsThePeriod(): void
    {
        $this->loginAsManager();
        $this->blockRepository->create($this->assetId, $this->futureDay(10), $this->futureDay(14), 'Camp', null);
        $this->postCalendarDays('local-saint-georges', ['mode' => 'release', 'days' => [$this->futureDay(12)]]);

        $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [$this->futureDay(12)],
            'reasons' => [$this->futureDay(12) => 'Camp'],
        ]);

        $blocks = $this->blockRepository->findAllForAsset($this->assetId);
        $this->assertCount(1, $blocks);
        $this->assertSame([$this->futureDay(10), $this->futureDay(14), 'Camp'], [$blocks[0]->startDate, $blocks[0]->endDate, $blocks[0]->reason]);
    }

    public function testAPastDayIsRefusedAndNothingIsWritten(): void
    {
        $this->loginAsManager();

        $response = $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [(new \DateTimeImmutable('yesterday'))->format('Y-m-d'), $this->futureDay(3)],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->blockRepository->findAllForAsset($this->assetId));
    }

    public function testAGestureWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->loginAsManager();

        $response = $this->postCalendarDays('local-saint-georges', [
            'mode' => 'block',
            'days' => [$this->futureDay(3)],
            '_csrf_token' => 'forged',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->blockRepository->findAllForAsset($this->assetId));
    }

    public function testABlockOverABookedPeriodIsAcceptedAndBothStayOnTheCalendar(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CONFIRMED, new \DateTimeImmutable());
        $this->blockRepository->create($this->assetId, '2027-07-02', '2027-07-02', 'Concierge absent', null);

        $body = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar',
            ['month' => '2027-07']
        )->getBody();

        // Neither failed nor overwrote the booking (§6.18): the day carries
        // the booking's state AND the unit's marker.
        $this->assertSame(BookingStatus::CONFIRMED, $this->bookingRepository->findById($booking->id)?->status);
        $this->assertMatchesRegularExpression(
            '/data-date="2027-07-02"\s+data-state="occupied"\s+data-unit-block="1"/',
            $body
        );
        $this->assertStringContainsString('Occupé — et réservé par l&#039;unité', $body);
    }

    public function testTheCalendarOffersNoDateFormNorQuantityAnyMore(): void
    {
        $this->loginAsManager();

        $body = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar'
        )->getBody();

        $this->assertStringContainsString('id="rental-block-calendar"', $body);
        $this->assertStringNotContainsString('action="/mes-locations/blocage"', $body);
        $this->assertStringNotContainsString('name="units"', $body);
        $this->assertStringContainsString('rental-block-days.js', $body);
    }

    public function testAPeriodsReasonIsGivenFromTheList(): void
    {
        $this->loginAsManager();
        $blockId = $this->blockRepository->create($this->assetId, $this->futureDay(10), $this->futureDay(12), null, null);

        $this->post('/mes-locations/blocage-motif', 'blockReason', [
            'asset_id' => (string) $this->assetId,
            'block_id' => (string) $blockId,
            'reason' => '  Chantier toiture ',
        ]);

        $this->assertSame('Chantier toiture', $this->blockRepository->findById($blockId)?->reason);
    }

    public function testAnotherAssetsPeriodsReasonCannotBeChanged(): void
    {
        $this->loginAsManager();
        $foreign = $this->blockRepository->create($this->otherAssetId, $this->futureDay(10), $this->futureDay(12), 'X', null);

        $this->post('/mes-locations/blocage-motif', 'blockReason', [
            'asset_id' => (string) $this->assetId,
            'block_id' => (string) $foreign,
            'reason' => 'Pris',
        ]);

        $this->assertSame('X', $this->blockRepository->findById($foreign)?->reason);
    }

    private function futureDay(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /**
     * The calendar's own fetch: a JSON body, the token inside it.
     *
     * @param array<string, mixed> $body
     */
    private function postCalendarDays(string $slug, array $body): Response
    {
        $body['_csrf_token'] ??= CsrfGuard::generateToken();
        $path = '/mes-locations/{slug}/calendrier/jours';

        $router = new Router();
        $router->addRoute('POST', $path, RentalManagementController::class, 'calendarDays', 'identified');

        return $this->dispatch(
            $router,
            new \Tests\RequestWithInput(
                'POST',
                '/mes-locations/' . $slug . '/calendrier/jours',
                [],
                [],
                [],
                [],
                (string) json_encode($body)
            )
        );
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
            'status' => 'info_requested',
        ]);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('Demande re', $body);
        $this->assertStringContainsString('Historique', $body);
        $this->assertStringContainsString('Location cl', $body);

        // The shared Core\Audit timeline (§8.66), not a hand-rolled list:
        // the status change is rendered under its French label, with the
        // move it made, by the same partial Camps uses.
        $this->assertStringContainsString('Statut', $body);
        $this->assertStringContainsString('Informations demand', $body);
        $this->assertStringContainsString('audit-rental_booking-' . $booking->id, $body);
    }

    public function testTheBookingsListFiltersOnWhatNeedsAttention(): void
    {
        $this->loginAsManager();
        $needsMe = $this->createBooking(null, 'LOC-A2B3C4');
        $done = $this->createBooking(null, 'LOC-A2B3C5');
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
        $this->createBooking(null, 'LOC-A2B3C4');
        $this->createBooking(null, 'LOC-Y2Y3Y4');

        $byReference = $this->bookingsList(['q' => 'Y2Y3']);
        $this->assertStringContainsString('LOC-Y2Y3Y4', $byReference);
        $this->assertStringNotContainsString('LOC-A2B3C4', $byReference);

        // The renter's name is encrypted, so this can only ever be
        // answered after hydration — which is exactly why the filtering
        // happens in PHP.
        $this->assertStringContainsString('LOC-A2B3C4', $this->bookingsList(['q' => 'Jeanne']));
        $this->assertStringNotContainsString('LOC-A2B3C4', $this->bookingsList(['q' => 'Gudule']));
    }

    public function testTheBookingsListFiltersByYear(): void
    {
        $this->loginAsManager();
        $this->createBooking(null, 'LOC-A2B3C4');
        $other = $this->bookingRepository->create(
            $this->assetId, 'LOC-Z2Z3Z4', '2029-07-01', '2029-07-04', 1, 20, null,
            ['name' => 'Marc', 'email' => 'marc@example.be', 'phone' => null,
             'organisation' => null, 'purpose' => null, 'comment' => null],
            null, null, null, 'v1', str_repeat('0', 64), 'v1', str_repeat('0', 64),
            new \DateTimeImmutable('2029-01-01 10:00:00')
        );
        $this->assertGreaterThan(0, $other['id']);

        $body = $this->bookingsList(['annee' => '2029']);

        $this->assertStringContainsString('LOC-Z2Z3Z4', $body);
        $this->assertStringNotContainsString('LOC-A2B3C4', $body);
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
        $this->blockRepository->create($this->assetId, '2027-07-10', '2027-07-12', 'Chantier toiture', null);

        $body = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar',
            ['month' => '2027-07']
        )->getBody();

        $this->assertStringContainsString('LOC-A2B3C4', $body);
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
        $this->createBooking(null, 'LOC-A2B3C4');
        $this->createBooking(null, 'LOC-A2B3C5');

        $body = (string) $this->get('/mes-locations', '/mes-locations', 'myRentals')->getBody();

        $this->assertStringContainsString('2 à traiter', $body);
        $this->assertStringContainsString('LOC-A2B3C4', $body);
    }

    public function testMyRentalsNeverListsAnotherManagersBookings(): void
    {
        $this->loginAsManager();
        $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');

        $body = (string) $this->get('/mes-locations', '/mes-locations', 'myRentals')->getBody();

        $this->assertStringNotContainsString('LOC-W9Y8X7', $body);
        $this->assertStringNotContainsString('Local des autres', $body);
    }

    // ── Documents (§6.24, §6.25) ────────────────────────────────────────

    private function setContractTemplate(string $body = '<p>Contrat pour {{ locataire_nom }}.</p>'): void
    {
        (new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)))
            ->set(\Modules\Rental\Document\DocumentType::CONTRACT->templateKey($this->assetId), $body, 'rich_text', 1);
    }

    /**
     * Generates the booking's contract and sends it, as the dashboard does:
     * from then on the booking changes only through a modification.
     */
    private function sendContractOf(RentalBooking $booking): \Modules\Rental\Document\RentalDocument
    {
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->latest($booking->id, \Modules\Rental\Document\DocumentType::CONTRACT);
        $this->assertNotNull($contract);
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $contract->id,
        ]);
        \Core\Http\FlashMessage::get();

        return $this->documentService->find($contract->id) ?? $contract;
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
        $this->assertSame('contrat-LOC-A2B3C4-v1.pdf', $documents[0]->originalName);
    }

    public function testTheFirstContractPrintsTheCommunicationOfTheReceivableItRaises(): void
    {
        // The receivable is raised before the first contract (issue #927),
        // so the contract prints the real communication, and a second
        // version prints the same one.
        $this->loginAsManager();
        $this->setContractTemplate('<p>Virement : {{ communication }}</p>');
        $booking = $this->bookingOwedFor();
        $this->runStatement('DELETE FROM finance_expected_receivables WHERE source_reference_id = ?', [$booking->id]);
        $this->runStatement(
            'UPDATE rental_bookings SET rental_receivable_id = NULL, rental_communication = NULL WHERE id = ?',
            [$booking->id]
        );

        foreach ([1, 2] as $ignored) {
            $this->post('/mes-locations/document-generer', 'generateDocument', [
                'asset_id' => (string) $this->assetId,
                'booking_id' => (string) $booking->id,
                'document_type' => 'contract',
            ]);
        }

        $communication = $this->fetchScalar(
            'SELECT communication FROM finance_expected_receivables WHERE source_reference_id = ?',
            [$booking->id]
        );
        $this->assertIsString($communication);
        $this->assertSame(1, (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM finance_expected_receivables WHERE source_reference_id = ?',
            [$booking->id]
        ));
        $snapshots = $this->runStatement(
            'SELECT generated_snapshot FROM rental_documents WHERE booking_id = ? AND document_type = ?',
            [$booking->id, 'contract']
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(2, $snapshots);
        foreach ($snapshots as $snapshot) {
            $this->assertSame($communication, json_decode((string) $snapshot, true)['communication'] ?? null);
        }
    }

    public function testNoContractIsGeneratedWhenPaymentsAreOnButTheReceivableCannotBeRaised(): void
    {
        // Payments on, but the category the receivable must name is gone:
        // generating would send a contract without a communication.
        $this->loginAsManager();
        $this->setContractTemplate('<p>Virement : {{ communication }}</p>');
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: true,
            financeAccountId: $this->financeAccountId,
            financeCategoryId: $this->financeCategoryId
        ));
        $this->runStatement('UPDATE rental_assets SET finance_category_id = NULL WHERE id = ?', [$this->assetId]);
        $booking = $this->createBooking();

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString("Le contrat n'a pas été généré", (string) ($flash['message'] ?? ''));
        $this->assertSame([], $this->documentService->forBooking($booking->id));
    }

    public function testAGoodwillGestureIsRecordedAsALineOfTheSettlement(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor();

        $this->post('/mes-locations/decompte', 'recordSettlement', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'final_persons' => '20',
            'gesture_label' => 'Geste commercial',
            'gesture_amount' => '50,00',
        ]);

        $settlement = $this->stayService->latestSettlement($booking->id);
        $this->assertNotNull($settlement);
        $this->assertSame(-5000, $settlement->gesture()?->amountCents);
        $this->assertSame('Geste commercial', $settlement->gesture()?->label);
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
        // Rendered as work to do, never as the greyed « sans objet » it
        // used to be.
        $this->assertStringContainsString('<span class="visually-hidden">À faire :</span>', self::step($before, 'contract_sent'));

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
        $this->assertStringContainsString('<span class="visually-hidden">Fait :</span>', self::step($after, 'contract_sent'));

        // The unit's answer (#708, IT-13): « Contrat envoyé », and the dates
        // held while the renter signs — no decision email on top of it.
        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertSame(BookingStatus::CONTRACT_SENT, $fresh?->status);
        $this->assertNotNull($fresh?->holdUntil);
        $this->assertSame([], $this->renterEmails);
    }

    /**
     * A contract never leaves for dates somebody else holds since this
     * booking's own hold lapsed (#927, chantier §2.1): refused before the
     * e-mail, nothing marked sent, the status untouched.
     */
    public function testAContractIsNotSentForDatesAnotherRequestHolds(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $competitor = $this->createBooking(reference: 'LOC-Q8R9S2');
        $this->bookingRepository->setHold($competitor->id, new \DateTimeImmutable('+10 days'), HoldOrigin::AUTOMATIC);

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        \Core\Http\FlashMessage::get();
        $document = $this->documentService->forBooking($booking->id)[0];
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $document->id,
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(\Modules\Rental\Service\RentalOperationsService::DATES_TAKEN_FOR_CONTRACT, $flash['message'] ?? null);
        $this->assertNull($this->documentService->find($document->id)?->sentAt);
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($booking->id)?->status);
    }

    /**
     * The contract is generated, read, then sent from its own steps on the
     * dashboard (#708, IT-16) — two gestures, so the PDF can be read before
     * it leaves — and the send says, before anything goes, to whom and how
     * long the dates stay held.
     */
    /**
     * The dashboard's contract step warns about an empty landlord address
     * as the Documents page does — until the address is filled in, and no
     * longer once the contract is sent and its text locked.
     */
    public function testTheDashboardContractStepWarnsWhileTheLandlordHasNoAddress(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $step = self::step($body, 'contract_generated');
        $this->assertStringContainsString('data-landlord-address-missing', $step);
        $this->assertStringContainsString('href="/mes-locations/local-saint-georges/reglages#bailleur"', $step);

        $this->assetRepository->saveLandlord(
            $this->assetId,
            'ASBL Les Amis du Local',
            'Place du Parc 3, 1300 Wavre',
            null
        );
        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringNotContainsString('data-landlord-address-missing', $body);
    }

    public function testTheDashboardWarningGoesOnceTheContractIsSent(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
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

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringNotContainsString(
            'data-landlord-address-missing',
            $body,
            'the sent text can no longer change'
        );
    }

    public function testTheContractIsGeneratedThenSentFromTheDashboard(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'dashboard',
            'document_type' => 'contract',
        ]);
        $this->assertStringEndsWith(
            '/mes-locations/local-saint-georges/reservations/' . $booking->id,
            (string) $response->getHeaders()['Location']
        );

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $nextStep = self::panel($body, 'next-step');
        $this->assertStringContainsString('data-contract-command="send"', $nextStep);
        $this->assertStringContainsString('à jeanne@example.be ?', html_entity_decode($nextStep));
        $this->assertStringContainsString("Les dates restent bloquées jusqu'au", html_entity_decode($nextStep));

        $generated = self::step($body, 'contract_generated');
        $this->assertStringContainsString('Relire « Contrat v1 »', html_entity_decode($generated));
        $this->assertStringContainsString('Générer à nouveau', $generated);
        $this->assertStringContainsString('Ajuster le', $generated);

        $document = $this->documentService->forBooking($booking->id)[0];
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'dashboard',
            'document_id' => (string) $document->id,
        ]);

        // Once it has gone its text is locked: nothing to regenerate or
        // adjust, only the version that left to read again.
        $sent = self::step($this->bookingPage('local-saint-georges', $booking->id)->getBody(), 'contract_generated');
        $this->assertStringContainsString('Relire « Contrat v1 »', html_entity_decode($sent));
        $this->assertStringNotContainsString('Générer à nouveau', $sent);
        $this->assertStringNotContainsString('Ajuster le', $sent);
    }

    // ── The countersignature (#708, IT-16) ──────────────────────────────

    /** A booking whose contract went out and whose renter sent a signed photo. */
    private function bookingWithASignedCopy(): array
    {
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $this->documentService->forBooking($booking->id)[0]->id,
        ]);

        $image = imagecreatetruecolor(600, 800);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        @mkdir($this->storagePath . '/rental/documents', 0755, true);
        $relative = 'rental/documents/' . bin2hex(random_bytes(8)) . '.jpg';
        file_put_contents($this->storagePath . '/' . $relative, $bytes);
        $fileId = $this->fileRepository->create($relative, 'copie.jpg', 'image/jpeg', strlen($bytes), 'identified', 'rental', null);

        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);
        $copy = $this->signedContractService->receiveCopy($fresh, $asset, $fileId);

        return [$fresh, $copy];
    }

    private static function signatureDataUrl(): string
    {
        $image = imagecreatetruecolor(300, 100);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** The signature this account keeps in Mon compte (Core\Signature, issue #926). */
    private function keepSignatureFor(int $account): void
    {
        $this->personalSignatures->save(
            $account,
            \Core\Signature\SignatureImage::fromDataUrl(self::signatureDataUrl()),
            new \DateTimeImmutable('2026-01-01 08:00:00')
        );
    }

    /**
     * The copy is opened and answered from its step; without a stored
     * signature, the dialog asks for one for this contract (issue #926).
     */
    public function testACopyWaitsOnTheDashboardAndTheDialogAsksForASignatureForThisContract(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('href="#contresignature"', self::panel($body, 'next-step'));
        $step = self::step($body, 'contract_countersigned');
        $this->assertStringContainsString('Ouvrir la copie reçue', $step);
        $this->assertStringContainsString('data-bs-target="#countersign-' . $copy->id . '">Valider et contresigner</button>', $step);
        $dialog = self::dialog($body, 'countersign-' . $copy->id);
        $this->assertStringContainsString('action="/mes-locations/contrat-contresigner"', $dialog);
        $this->assertStringContainsString('data-signature-mode="new" data-async="off"', $dialog);
        $this->assertStringContainsString('name="signature_source" value="new"', $dialog);
        $this->assertStringContainsString('data-signature-pad', $dialog);
        $this->assertStringContainsString('name="signature_file"', $dialog);
        $this->assertStringContainsString('Mémoriser ma signature dans mon compte pour une utilisation future', $dialog);
        $this->assertStringNotContainsString('checked', $dialog);
        $this->assertStringNotContainsString('data-confirm', $dialog);
        $this->assertStringContainsString('name="booking_page" value="dashboard"', $dialog);
        $this->assertStringContainsString('name="document_id" value="' . $copy->id . '"', $dialog);
        $this->assertStringContainsString('le locataire reçoit le contrat signé par les deux parties', $dialog);
        $this->assertStringContainsString('Signer le document</button>', $dialog);
        $this->assertStringNotContainsString('/mes-locations/ma-signature', $body);
        $this->assertStringContainsString('/assets/js/signature-dialog.js', $body);
        $this->assertStringContainsString('<span class="visually-hidden">Fait :</span>', self::step($body, 'signed_copy_received'));
    }

    /** With a stored signature, the dialog says it will be used — and never carries it. */
    public function testWithAStoredSignatureTheDialogSaysSoAndOffersToManageIt(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();
        $this->keepSignatureFor(1);

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $dialog = self::dialog($body, 'countersign-' . $copy->id);

        $this->assertStringContainsString(
            'Ce document sera signé avec la signature enregistrée dans votre compte. Vérifiez son contenu avant de confirmer.',
            $dialog
        );
        $this->assertStringContainsString('name="signature_source" value="stored"', $dialog);
        $this->assertStringContainsString(
            'href="/account/signature?retour=' . rawurlencode('/mes-locations/local-saint-georges/reservations/' . $booking->id) . '">Gérer ma signature</a>',
            $dialog
        );
        $this->assertStringNotContainsString('data-signature-pad', $dialog);
        $this->assertStringNotContainsString('remember_signature', $dialog);
        $this->assertStringNotContainsString('/account/signature/image', $body);
        $this->assertStringNotContainsString('data:image', $body);
    }

    /**
     * Signed for this contract alone, nothing is kept; ticked, it is kept
     * in Mon compte and the flash says so (issue #926).
     */
    public function testAOneOffCountersignatureKeepsNothingUnlessTheBoxIsTicked(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();

        $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'new',
            'signature_data' => self::signatureDataUrl(),
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $this->assertStringNotContainsString('Mon compte', (string) ($flash['message'] ?? ''));
        $this->assertNotNull($this->signedContractService->finalContract($booking->id));
        $this->assertFalse($this->personalSignatures->has(1));
    }

    public function testTickedTheSignatureIsKeptForTheSessionsAccountWhateverTheFormSays(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();

        $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'new',
            'signature_data' => self::signatureDataUrl(),
            'remember_signature' => '1',
            'user_account_id' => '2',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertStringEndsWith(' Votre signature est enregistrée dans Mon compte.', (string) ($flash['message'] ?? ''));
        $this->assertTrue($this->personalSignatures->has(1));
        $this->assertFalse($this->personalSignatures->has(2));
    }

    /** A posted account id never reaches somebody else's stored signature. */
    public function testAnotherAccountsStoredSignatureCannotBeNamedByTheForm(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();
        $this->keepSignatureFor(2);

        $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'stored',
            'user_account_id' => '2',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString("Votre signature enregistrée n'existe plus", (string) ($flash['message'] ?? ''));
        $this->assertNull($this->signedContractService->finalContract($booking->id));
        $this->assertSame($copy->id, $this->signedContractService->pendingCopy($booking->id)?->id);
    }

    /**
     * « Annuler » posts nothing, so the copy waits as it was; a dialog sent
     * with nothing to sign with is refused in French, not a 500 — the
     * core's refusal reaches bookingAction() as the rental one it catches.
     */
    public function testNothingToSignWithLeavesTheCopyWaiting(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();

        $response = $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'new',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame("Tracez votre signature ou importez-en l'image.", $flash['message'] ?? null);
        $this->assertSame($copy->id, $this->signedContractService->pendingCopy($booking->id)?->id);
        $this->assertNull($this->signedContractService->finalContract($booking->id));
    }

    /** Somebody who manages nothing countersigns nothing, and keeps nothing either. */
    public function testSomeoneWhoManagesNothingCannotCountersign(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();
        AuthSession::login(9, 'nobody@test.be', 'identified');

        $response = $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'new',
            'signature_data' => self::signatureDataUrl(),
            'remember_signature' => '1',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($this->signedContractService->finalContract($booking->id));
        $this->assertFalse($this->personalSignatures->has(9));
    }

    public function testAManagerCountersignsWithTheirOwnSignature(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();
        $this->keepSignatureFor(1);

        $before = self::step($this->bookingPage('local-saint-georges', $booking->id)->getBody(), 'contract_countersigned');
        $this->assertStringContainsString('/mes-locations/contrat-contresigner', $before);

        $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'stored',
        ]);

        $final = $this->signedContractService->finalContract($booking->id);
        $this->assertNotNull($final);
        $this->assertSame(\Modules\Rental\Document\DocumentType::SIGNED_CONTRACT, $final->type);
        $after = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('<span class="visually-hidden">Fait :</span>', self::step($after, 'contract_countersigned'));
    }

    /**
     * The session's account is told, and nobody else: not the asset's
     * other manager, and not whichever account a member's address would
     * resolve to (issue #926, decision Q1). The flash says the contract,
     * not the notification.
     */
    public function testTheSessionsAccountAloneIsNotifiedOfItsCountersignature(): void
    {
        $this->addManager($this->assetId, 'autre@test.be');
        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $this->encryption);
        // Account 1 is the session's; the manager's address resolves to
        // account 2, the other manager's to account 3.
        $this->assertSame(1, $accounts->create('session@test.be')->id);
        $this->assertSame(2, $accounts->create('manager@test.be')->id);
        $this->assertSame(3, $accounts->create('autre@test.be')->id);
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();
        $this->notified = [];

        $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'new',
            'signature_data' => self::signatureDataUrl(),
            'user_account_id' => '2',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $this->assertStringNotContainsString('notification', strtolower((string) ($flash['message'] ?? '')));
        $this->assertCount(1, $this->notified);
        $this->assertSame('rental.document_signed', $this->notified[0]['type']);
        $this->assertSame([['userAccountId' => 1, 'memberId' => null]], $this->notified[0]['recipients']);
        $this->assertSame(
            '/mes-locations/local-saint-georges/reservations/' . $booking->id . '/documents',
            $this->notified[0]['payload']['url']
        );
        $this->assertStringStartsWith('Contrat signé — ', $this->notified[0]['payload']['title']);
    }

    // ── Who signs a document (#825) ─────────────────────────────────────

    /**
     * A manager the unit's roster knows by a totem — « Loutre » — under a
     * child's first name, the way a family's shared address often reads,
     * and whose ACCOUNT carries the name the person logs in under. The two
     * say different things about one login, and a tenant must read only
     * the second.
     *
     * @return int the account id
     */
    private function loginAsManagerKnownByATotem(): int
    {
        $email = 'marie@test.be';
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-TOTEM001');
        RentalTestHelper::insertMemberYear(
            $this->pdo,
            $this->encryption,
            $memberId,
            $this->scoutYearId,
            $email,
            'Léa',
            null,
            'Petit',
            'Loutre'
        );
        $this->managerRepository->grant($this->assetId, $memberId, false);

        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $this->encryption);
        $account = $accounts->create($email);
        $accounts->updateProfile($account->id, 'Marie', 'Dupont');
        AuthSession::login($account->id, $email, 'identified');

        return $account->id;
    }

    /** What a reader of the PDF sees, on one line. */
    private function renderedTextOf(\Modules\Rental\Document\RentalDocument $document): string
    {
        $path = $this->documentService->absolutePath($document);
        $this->assertIsString($path);
        $text = (new \Core\File\PdfTextExtractor())->extractText((string) file_get_contents($path));
        $this->assertNotNull($text, 'the generated PDF carries no readable text layer');

        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * The line that says who countersigned names the person as their
     * account does — never the totem the roster knows them by, and never a
     * member who merely shares the address (#825).
     */
    public function testTheCountersignatureNamesTheAccountNotTheTotem(): void
    {
        $accountId = $this->loginAsManagerKnownByATotem();
        [$booking, $copy] = $this->bookingWithASignedCopy();
        $this->keepSignatureFor($accountId);

        $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'stored',
        ]);

        $final = $this->signedContractService->finalContract($booking->id);
        $this->assertNotNull($final);
        $text = $this->renderedTextOf($final);
        $this->assertStringContainsString(' par Marie Dupont, le ', $text);
        $this->assertStringNotContainsString('Loutre', $text);
        $this->assertStringNotContainsString('Léa', $text);
    }

    /** The inventory's signature frame says the same, for the same reader (#825). */
    public function testTheInventoryValidationNamesTheAccountNotTheTotem(): void
    {
        $this->loginAsManagerKnownByATotem();
        [$booking, $chairs, $kitchen] = $this->bookingWithAnInventory();
        $this->saveLine($booking, $chairs, 'arrival', '38');
        $this->saveLine($booking, $kitchen, 'arrival', 'yes');

        $this->validateInventoryPhase($booking, 'arrival');

        $validation = $this->stayService->inventoryValidations($booking->id)['arrival'] ?? null;
        $this->assertNotNull($validation);
        $document = $this->documentService->find((int) $validation['document_id']);
        $this->assertNotNull($document);
        $text = $this->renderedTextOf($document);
        $this->assertStringContainsString('Signé au doigt par Marie Dupont', $text);
        $this->assertStringNotContainsString('Loutre', $text);
        $this->assertStringNotContainsString('Léa', $text);
    }

    public function testAManagerRefusesACopyWithAReasonAndTheRenterMaySendAnother(): void
    {
        $this->loginAsManager();
        [$booking, $copy] = $this->bookingWithASignedCopy();

        $this->post('/mes-locations/copie-refuser', 'refuseSignedCopy', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'reason' => '',
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertNotNull($this->signedContractService->pendingCopy($booking->id));

        $this->post('/mes-locations/copie-refuser', 'refuseSignedCopy', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'reason' => 'La deuxième page n\'est pas signée.',
        ]);

        $this->assertNull($this->signedContractService->pendingCopy($booking->id));
        $this->assertTrue($this->signedContractService->acceptsCopy($booking));
        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        // Back to waiting on the renter, and the reason is on the step.
        $this->assertStringContainsString('<span class="visually-hidden">À faire :</span>', self::step($body, 'signed_copy_received'));
        $this->assertStringContainsString('pas signée', html_entity_decode(self::step($body, 'contract_countersigned')));
    }

    /**
     * A direct change, before any contract has gone out, leaves a contract
     * generated and not sent « à régénérer » (#927, chantier §2.3) — and the
     * manager is told in the same breath as their own gesture's answer.
     */
    public function testADirectChangeLeavesTheUnsentContractToRegenerate(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->forBooking($booking->id)[0];
        \Core\Http\FlashMessage::get();
        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => '30',
        ]);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString('à régénérer', $flash['message'] ?? '');
        $this->assertTrue($this->documentService->find($contract->id)?->isSuperseded());
        $this->assertSame(30, $this->bookingRepository->findById($booking->id)?->estimatedPersons);
        // Kept and marked on the Documents page; the steps start over.
        $documents = $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('À régénérer — la réservation a changé le', $documents);
        $dashboard = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('<span class="visually-hidden">À faire :</span>', self::step($dashboard, 'contract_generated'));
        $this->assertStringContainsString('Générer le contrat', self::panel($dashboard, 'next-step'));
        // A void contract is never sent.
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $contract->id,
        ]);
        $refusal = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $refusal['type'] ?? null);
        $this->assertStringContainsString('remplacé', $refusal['message'] ?? '');
    }

    /**
     * Once a contract has gone out, nothing voids it on its own any more
     * (#927, chantier §2.3): billing coordinates filled in after the send
     * leave it in force, and the booking stays « Contrat envoyé ».
     */
    public function testAfterTheFirstSendBillingCoordinatesVoidNothing(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $contract = $this->sendContractOf($booking);

        $this->post('/mes-locations/facturation', 'saveBillingIdentity', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'billing_name' => 'Patro Sainte-Anne',
            'billing_address' => "Rue de l'Église 12, 5000 Namur",
        ]);

        $this->assertFalse($this->documentService->find($contract->id)?->isSuperseded());
        $this->assertSame(BookingStatus::CONTRACT_SENT, $this->bookingRepository->findById($booking->id)?->status);
    }

    /**
     * A contract sent before contracts carried a fingerprint is never
     * voided, so its steps never reopen: the Documents page keeps offering
     * its new version — and only for that contract.
     */
    public function testAContractSentBeforeFingerprintsCanStillBeRegenerated(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->forBooking($booking->id)[0];
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $contract->id,
        ]);
        \Core\Http\FlashMessage::get();

        $documents = $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringNotContainsString('Générer une nouvelle version du', $documents, 'a fingerprinted contract reopens by itself');

        $this->pdo->prepare('UPDATE rental_documents SET fingerprint = NULL WHERE id = ?')->execute([$contract->id]);
        $documents = $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Générer une nouvelle version du', $documents);

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'documents',
            'document_type' => 'contract',
        ]);
        $this->assertCount(2, $this->documentService->forBooking($booking->id), 'v2 beside v1');
    }

    /**
     * A sent contract whose stored PDF is gone cannot be sent again — the
     * error says « Régénérez-le » — so the Documents page offers its new
     * version, as it does for a contract older than fingerprints.
     */
    public function testAContractWhoseFileIsGoneCanBeRegenerated(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->forBooking($booking->id)[0];
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $contract->id,
        ]);
        \Core\Http\FlashMessage::get();

        $documents = $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringNotContainsString('Générer une nouvelle version du', $documents);

        unlink((string) $this->documentService->absolutePath($contract));
        $documents = $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Générer une nouvelle version du', $documents);
    }

    /** What the contract does not state — an internal comment — voids nothing. */
    public function testAnInternalCommentVoidsNothing(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $contract = $this->documentService->forBooking($booking->id)[0];

        $this->post('/mes-locations/commentaire', 'addComment', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'body' => 'Le trésorier passe les clés.',
        ]);

        $this->assertFalse($this->documentService->find($contract->id)?->isSuperseded());
    }

    /**
     * Documents still lists the contract and the invoice and resends them,
     * but generates neither (#708, IT-16, IT-18), nor holds the billing
     * details any more.
     */
    public function testTheDocumentsPageNoLongerGeneratesTheContractNorTheInvoice(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringNotContainsString('Générer le contrat', $body);
        $this->assertStringNotContainsString('/document/contract"', $body);
        $this->assertStringNotContainsString('Générer la facture', $body);
        $this->assertStringNotContainsString('action="/mes-locations/facturation"', $body);
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
        $this->assertStringContainsString(
            '<span class="visually-hidden">Sans objet :</span>',
            self::step($body, 'security_deposit_received')
        );
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

        // Each page swaps its own panels, from a fresh render of its own
        // URL (rental-booking.js), so each must carry them — and opt in to
        // the script by carrying `data-rental-booking` at all. The figure
        // on a box is its own region: it is the only part of the box
        // outside the fold that an action changes, and a box still reading
        // « Aucun document » over a document somebody has just generated is
        // the lie the wrapper exists to stop.
        $expected = [
            'dashboard' => ['milestones', 'next-step', 'history', 'history-figure', 'comments'],
            'changes' => ['changes'],
            // The price moved to a modification's own page (#927, lot D).
            'finances' => ['payment'],
            'documents' => ['documents', 'documents-figure'],
        ];
        foreach ($expected as $page => $panels) {
            $body = (string) $this->filePage(BookingPage::from($page), 'local-saint-georges', $booking->id)->getBody();

            $this->assertStringContainsString('data-rental-booking', $body, "{$page} does not opt in to the refresh");
            foreach ($panels as $panel) {
                $this->assertStringContainsString('data-booking-panel="' . $panel . '"', $body, "{$page} lacks {$panel}");
            }
        }
    }

    /**
     * **The figure has to carry the number, not just the wrapper.**
     *
     * Each box embeds `_dossier_header.html.twig` with `only`, which
     * restricts the embed — the `{% block figure %}` overrides included —
     * to exactly what the `with {}` map passes. A variable the figure reads
     * and the map does not pass resolves to null rather than erroring,
     * because `strict_variables` is off, so every box renders its empty
     * branch over a file that is not empty: « Aucun document » above a
     * contract, « Suivi hors ScoutMagic » above money that is owed. Nothing
     * in the markup says so, which is why this asserts the computed text.
     */
    public function testEachFoldedBoxCarriesTheFigureItWasFoldedBehind(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $this->commentRepository->create($booking->id, null, 'Le locataire a téléphoné.');

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('1 document', self::panel($documents, 'documents-figure'));
        $this->assertStringContainsString('1 commentaire', self::panel($body, 'comments-figure'));
        // The history is never empty: creating the booking is itself an
        // entry, so a figure reading nothing at all is the bug.
        $this->assertMatchesRegularExpression(
            '/\d+ modification/',
            self::panel($body, 'history-figure')
        );
    }

    /**
     * « Modifications » (#927, chantier §2.4): its own page, right after
     * the dashboard; before any contract has gone out it offers to change
     * the booking directly, after it to create a modification — and the
     * rail counts the one in progress, on every page of the file.
     */
    public function testTheChangesHaveTheirOwnPageAndTheRailCountsWhatWaits(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $empty = (string) $this->filePage(BookingPage::CHANGES, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Aucune modification.', self::panel($empty, 'changes'));
        $this->assertStringContainsString('Modifier les dates, les participants ou le prix', $empty);
        $this->assertMatchesRegularExpression('#<span>Tableau de bord</span>.*?<span>Modifications</span>.*?<span>Finances</span>#s', $empty);

        $this->sendContractOf($booking);
        $sent = (string) $this->filePage(BookingPage::CHANGES, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Créer une modification', $sent);

        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => $booking->arrivalDate,
            'departure' => '2027-07-05',
            'persons' => '20',
        ]);
        $dashboard = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('<span>Modifications (1)</span>', $dashboard);
        $changes = (string) $this->filePage(BookingPage::CHANGES, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Modification en cours', $changes);
        $this->assertStringContainsString('En préparation', $changes);
        $this->assertStringContainsString('Générer puis envoyer le contrat de remplacement', $changes);
        // The booking itself has not moved.
        $this->assertSame('2027-07-04', $this->bookingRepository->findById($booking->id)?->departureDate);
    }

    /**
     * The three movements of the dashboard, in the mockup's order (issue
     * #462): where the booking stands — the journey, heading included —
     * what it is, then the file. Asserted by position rather than by
     * presence, because the order is the point: three headings in the
     * wrong sequence would pass a test that only asked whether they were
     * there.
     */
    public function testTheDashboardReadsInItsThreeMovements(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        // One component answers « où en est » — the second card that used
        // to answer it too is gone.
        $this->assertStringNotContainsString("L'action suivante", $body);

        $positions = [];
        foreach ([
            // Two cards since #708 (IT-19), from the one derivation.
            'Prochaine action',
            'Cycle de vie',
            'Les détails de la réservation',
            'Le dossier',
        ] as $heading) {
            $at = strpos($body, $heading);
            $this->assertNotFalse($at, "the page is missing « {$heading} »");
            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'the three movements are out of order');

        // The « État » card is gone: its badge is in the details above and
        // its buttons are decisions of a phase, never a state of their own.
        $this->assertStringNotContainsString('data-booking-panel="lifecycle"', $body);
    }

    /**
     * A request nobody has answered yet puts the decision at the top of the
     * page, with the buttons under it — not a link to a phase, and not a
     * request for a contract, which is where the checklist alone used to
     * point.
     */
    /**
     * The unit's answer is its contract (#708, IT-13): a received request
     * leads with sending it, the other answers stay behind « Autres
     * décisions », and confirming is not among them.
     */
    public function testAReceivedRequestLeadsWithTheContract(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $nextStep = self::panel($body, 'next-step');

        $this->assertStringContainsString('Le contrat reste à générer.', $nextStep);
        // Generated right there, from the dashboard (#708, IT-16).
        $this->assertStringContainsString('data-contract-command="generate"', $nextStep);
        $this->assertStringContainsString('Générer le contrat', $nextStep);
        $this->assertStringContainsString('value="refused"', $nextStep);
        $this->assertStringNotContainsString('value="confirmed"', $nextStep);
    }

    /** Confirming is refused while the agreement is not complete, and says what is missing. */
    public function testAConfirmationWaitsForTheAgreement(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($booking->id)?->status);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('« Contrat envoyé »', $flash['message'] ?? '');
    }

    /**
     * And the same buttons are NOT repeated inside the stretch they belong
     * to. One page offering « Confirmée » twice is one where pressing
     * either is a guess about which one counts.
     */
    public function testALiftedDecisionIsNotAlsoShownInsideItsPhase(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->completeTheAgreement($booking);

        $body = $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertSame(
            1,
            substr_count(self::withoutStepDiscs($body), 'name="status" value="confirmed"'),
            'the « Confirmée » button is rendered twice'
        );
    }

    /**
     * **No box is lost in the split** (issue #462, IT-01). Every case of
     * Booking\BookingBox is rendered on the page `BookingBox::page()` says
     * it lives on, carrying the anchor its journey links aim at — the
     * stay, which is a page of its own, on the dashboard as the line that
     * leads there. A box added to the enum and to no page fails here
     * rather than vanishing from the file.
     */
    public function testEveryBoxIsRenderedOnThePageItBelongsTo(): void
    {
        $this->loginAsManager();
        $this->withCollectingMailbox();
        // « État des lieux » is offered only where an inventory is kept.
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();

        $bodies = [];
        foreach (BookingPage::cases() as $page) {
            $response = $this->filePage($page, 'local-saint-georges', $booking->id);
            $this->assertSame(200, $response->getStatusCode(), $page->value);
            $bodies[$page->value] = (string) $response->getBody();
        }

        foreach (BookingBox::cases() as $box) {
            $home = $box->page() ?? BookingPage::DASHBOARD;
            $this->assertStringContainsString(
                'id="' . $box->anchor() . '"',
                $bodies[$home->value],
                $box->value . ' is not rendered on ' . $home->value
            );

            foreach ($bodies as $page => $body) {
                if ($page !== $home->value) {
                    $this->assertStringNotContainsString(
                        'id="' . $box->anchor() . '"',
                        $body,
                        $box->value . ' is rendered twice, also on ' . $page
                    );
                }
            }
        }
    }

    /**
     * One panel's markup, by the wrapper rental-booking.js swaps — the same
     * addressing the page's own script uses, rather than a guess at which
     * card a string landed in.
     */
    /**
     * One step of the journey, by its milestone key — the `li` the step
     * partial renders, with its nature and its visually-hidden state.
     */
    /** One signature dialog of the page, by its id (partials/signature_dialog.html.twig). */
    private static function dialog(string $body, string $id): string
    {
        $start = strpos($body, '<div class="modal fade" id="' . $id . '"');
        self::assertNotFalse($start, 'no dialog ' . $id . ' on the page');
        $end = strpos($body, 'Signer le document</button>', $start);
        self::assertNotFalse($end);

        return substr($body, $start, $end - $start + strlen('Signer le document</button>'));
    }

    private static function step(string $body, string $key): string
    {
        $found = preg_match('#<li class="step-item[^"]*"\s+data-milestone="' . preg_quote($key, '#') . '".*?</li>#s', $body, $m);
        self::assertSame(1, $found, "no step {$key}");

        return $m[0];
    }

    private static function panel(string $body, string $name): string
    {
        $open = strpos($body, 'data-booking-panel="' . $name . '"');
        self::assertNotFalse($open, "no panel named {$name}");

        // Up to the next wrapper rather than a fixed window: a CSRF token
        // is 64 characters and a panel holding six forms outgrows any
        // number picked here, which would make the assertion pass or fail
        // on how long a page happens to be.
        $next = strpos($body, 'data-booking-panel="', $open + 1);

        return $next === false ? substr($body, $open) : substr($body, $open, $next - $open);
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

        $response = $this->postAsync('/mes-locations/modification-annuler', 'cancelModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'modification_id' => '999999',
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
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');

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
        $mine = $this->createBooking(null, 'LOC-A2B3C4');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');
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
        // A landlord with an address, or the generation rightly warns that
        // the contract prints « — » in its place (issue #497) — which is
        // testGeneratingWithoutALandlordAddressSaysSo()'s subject, not this.
        $this->assetRepository->saveLandlord($this->assetId, null, 'Rue du Local 1, 1000 Bruxelles', null);

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

        $body = $this->templateDocumentPage('contrat');

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

        $body = $this->templateDocumentPage('contrat');

        $this->assertStringContainsString('Nos propres conditions de location', $body);
        $this->assertStringContainsString('Réinitialiser au modèle standard', $body);
    }

    // ── The Gabarits page as a list (#708, IT-10) ───────────────────────

    /**
     * Three documents, their state, a pencil each — and none of the three
     * editors on the list itself.
     */
    public function testTheTemplatePageListsTheThreeDocumentsWithTheirState(): void
    {
        $this->loginAsManager();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);
        $this->documentService->saveTemplate(
            $asset,
            \Modules\Rental\Document\DocumentType::INVOICE,
            '<p>Facture {{ prix_ttc }}</p>',
            1
        );

        $body = $this->templatesPage();

        foreach (['contrat', 'facture', 'conditions'] as $document) {
            $this->assertStringContainsString(
                'href="/mes-locations/local-saint-georges/gabarits/' . $document . '"',
                $body,
                $document
            );
        }
        $this->assertStringContainsString('Modèle standard', $body);
        $this->assertStringContainsString('Personnalisé', $body);
        $this->assertStringContainsString('Conditions standard', $body);
        $this->assertStringContainsString('version en vigueur depuis le', $body);
        // The invoice asks for a keyword that does not exist.
        $this->assertStringContainsString('Mots-clés non reconnus', $body);
        // No editor and no keyword panel on the list.
        $this->assertStringNotContainsString('Mots-clés disponibles', $body);
        $this->assertStringNotContainsString('Convention de location', $body);
        // The same list component, without drag or bin.
        $this->assertStringContainsString('id="template-list"', $body);
        $this->assertStringNotContainsString('list-editor-drag-handle', $this->between($body, 'id="template-list"', 'id="meter-list"'));
    }

    /** The keywords are open on a template's own page, not folded. */
    public function testATemplatesOwnPageShowsItsKeywordsOpen(): void
    {
        $this->loginAsManager();

        foreach (['contrat', 'facture'] as $document) {
            $body = $this->templateDocumentPage($document);

            $this->assertMatchesRegularExpression('/<details class="mb-3" open>\s*<summary[^>]*>Mots-clés disponibles/', $body);
            $this->assertStringContainsString('{{ locataire_nom }}', $body);
            $this->assertStringContainsString('sa propre copie', $body);
        }

        $this->assertStringContainsString('vat_exemption_note', $this->templateDocumentPage('facture'));
        $this->assertStringNotContainsString('vat_exemption_note', $this->templateDocumentPage('contrat'));
    }

    public function testAnUnknownDocumentHasNoPage(): void
    {
        $this->loginAsManager();

        $this->assertSame(404, $this->templateDocumentResponse('photo')->getStatusCode());
        $this->assertSame(404, $this->templateDocumentResponse('contract')->getStatusCode());
    }

    public function testATemplatesPageIsRefusedToANonManager(): void
    {
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $this->assertSame(404, $this->templateDocumentResponse('contrat')->getStatusCode());
        $this->assertSame(404, $this->templateDocumentResponse('conditions')->getStatusCode());
    }

    /** Saving goes back to the list, with a message. */
    public function testSavingATemplateGoesBackToTheList(): void
    {
        $this->loginAsManager();

        $response = $this->post('/mes-locations/gabarit', 'saveTemplate', [
            'asset_id' => (string) $this->assetId,
            'document_type' => 'contract',
            'body' => '<p>Notre contrat.</p>',
        ]);

        $this->assertSame('/mes-locations/local-saint-georges/gabarits', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    /**
     * The reset posts the standard body; on the invoice it must carry the
     * VAT sentence along, or resetting the text would erase it.
     */
    public function testResettingTheInvoiceKeepsTheVatSentence(): void
    {
        $this->loginAsManager();
        $this->post('/mes-locations/gabarit', 'saveTemplate', [
            'asset_id' => (string) $this->assetId,
            'document_type' => 'invoice',
            'body' => '<p>Notre facture.</p>',
            'vat_exemption_note' => 'Non assujetti',
        ]);

        $page = $this->templateDocumentPage('facture');
        $this->assertMatchesRegularExpression(
            '/name="body" value="[^"]*"[^>]*>\s*<input type="hidden" name="vat_exemption_note" value="Non assujetti">/',
            $page
        );
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

        $this->assertStringContainsString('Gabarits', $this->templatesPage());
    }

    public function testTheDocumentEditorIsRefusedForAnotherAssetsBooking(): void
    {
        $this->loginAsManager();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');

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

    /** The same form names the group's responsible person on site (issue #977). */
    public function testTheResponsiblePersonOnSiteIsSavedFromTheInvoicePage(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->post('/mes-locations/facturation', 'saveBillingIdentity', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'billing_name' => 'ASBL Les Scouts',
            'billing_address' => 'Place du Marché 1, 5000 Namur',
            'on_site_contact_name' => 'Paul Lemaire',
            'on_site_contact_phone' => str_repeat('1', 41),
        ]);
        // A crafted post: nothing of it is written, the billing half
        // included, and the refusal says why.
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null, 'a phone no column could hold is refused');
        $this->assertStringContainsString('Téléphone du responsable', (string) ($flash['message'] ?? ''));
        $identity = $this->bookingRepository->findBillingIdentity($booking->id);
        $this->assertNull($identity['name']);
        $this->assertSame('Rue Haute 12, 1000 Bruxelles', $identity['address']);
        $this->assertSame(['name' => null, 'phone' => null], $this->bookingRepository->findOnSiteContact($booking->id));

        $this->post('/mes-locations/facturation', 'saveBillingIdentity', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'billing_address' => 'Rue Haute 12, 1000 Bruxelles',
            'on_site_contact_name' => 'Paul Lemaire',
            'on_site_contact_phone' => '0470 98 76 54',
        ]);

        $this->assertSame(
            ['name' => 'Paul Lemaire', 'phone' => '0470 98 76 54'],
            $this->bookingRepository->findOnSiteContact($booking->id)
        );
    }

    /**
     * A contract the site refuses to generate (issue #977) raises no
     * receivable: the refusal comes first, and says what to complete.
     */
    public function testAContractRefusedForAMissingAddressSaysWhereToCompleteIt(): void
    {
        $this->loginAsManager();
        $settings = new \Modules\Rental\Payment\PaymentSettings(
            enabled: true,
            financeAccountId: $this->financeAccountId,
            financeCategoryId: $this->financeCategoryId
        );
        $this->paymentService->saveSettings($this->assetId, $settings);
        $booking = $this->createBooking();
        $this->bookingRepository->saveBillingIdentity($booking->id, ['address' => null]);

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString(
            '« Coordonnées de facturation » sur la page « Facture »',
            (string) ($flash['message'] ?? '')
        );
        $this->assertSame([], $this->documentService->forBooking($booking->id));
        $this->assertNull(
            $this->paymentService->statusFor($booking, $settings)['communication'],
            'no receivable raised'
        );
    }

    // ── The stay (§6.21–§6.23) ──────────────────────────────────────────

    // ── The journey (issue #462, IT-02) ─────────────────────────────────

    private function confirm(RentalBooking $booking): void
    {
        $this->completeTheAgreement($booking);
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);
    }

    /**
     * The agreement complete, as the site derives it: the contract sent and
     * its signed copy back (#708, IT-13) — what a confirmation now waits for.
     */
    // ── Completing a step by hand (#708, IT-14) ─────────────────────────

    /**
     * « Contrat envoyé » ticked by hand — the contract went by e-mail —
     * has the effects of a real send; reopened, the request is back to
     * « Demande reçue », the hold not shortened.
     */
    public function testTickingTheContractByHandSendsItAndReopeningPutsItBack(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->assertSame(302, $this->markStep($booking, 'contract_sent')->getStatusCode());
        $sent = $this->bookingRepository->findById($booking->id);
        $this->assertSame(BookingStatus::CONTRACT_SENT, $sent?->status);
        $this->assertNotNull($sent?->holdUntil);

        $this->markStep($booking, 'contract_sent', false);
        $reopened = $this->bookingRepository->findById($booking->id);
        $this->assertSame(BookingStatus::RECEIVED, $reopened?->status);
        $this->assertEquals($sent?->holdUntil, $reopened?->holdUntil);
    }

    /**
     * The mark and the status it carries are one write: a reopened
     * « Contrat envoyé » whose status cannot go back keeps its mark, so
     * the page never shows the step to do while the booking still says
     * the contract went out.
     */
    public function testAReopenWhoseStatusFailsKeepsTheMark(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->markStep($booking, 'contract_sent');
        $this->pdo->exec(
            "CREATE TRIGGER refuse_received BEFORE UPDATE ON rental_bookings WHEN NEW.status = 'received' "
                . "BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        try {
            $this->markStep($booking, 'contract_sent', false);
            $this->fail('the status write was expected to fail');
        } catch (\PDOException) {
        }

        $marks = new \Modules\Rental\Repository\RentalMilestoneMarkRepository($this->pdo);
        $this->assertArrayHasKey('contract_sent', $marks->findForBooking($booking->id));
        $this->assertSame(BookingStatus::CONTRACT_SENT, $this->bookingRepository->findById($booking->id)?->status);
    }

    /** A step ticked by hand moves the journey on, like the site's own answer. */
    public function testAHandTickMovesTheNextStepOn(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->markStep($booking, 'contract_sent');

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('<span class="visually-hidden">Fait :</span>', self::step($body, 'contract_sent'));
        $this->assertStringNotContainsString('envoyez le contrat', self::panel($body, 'next-step'));
    }

    /** A step the site completed itself never reopens. */
    public function testAStepTheSiteCompletedCannotBeReopened(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->completeTheAgreement($booking);

        $this->markStep($booking, 'contract_sent', false);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Seule une étape cochée à la main', $flash['message'] ?? '');
        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringNotContainsString('Rouvrir', self::step($body, 'contract_sent'));
    }

    /** « Réservation confirmée » is run from its disc once the agreement is complete, never ticked. */
    public function testTheConfirmationDiscRunsTheTransition(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->completeTheAgreement($booking);

        $step = self::step((string) $this->bookingPage('local-saint-georges', $booking->id)->getBody(), 'confirmed');

        $this->assertStringContainsString('action="/mes-locations/statut"', $step);
        $this->assertStringContainsString('name="status" value="confirmed"', $step);
        $this->assertStringContainsString('<span class="visually-hidden">Confirmer la réservation</span>', $step);
        $this->assertStringNotContainsString('action="/mes-locations/etape"', $step);
        $this->assertSame(302, $this->markStep($booking, 'confirmed')->getStatusCode());
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
    }

    public function testAStepOfAnotherAssetsBookingCannotBeTicked(): void
    {
        $this->loginAsManager();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');

        $response = $this->post('/mes-locations/etape', 'markMilestone', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $foreign->id,
            'milestone_key' => 'contract_sent',
            'done' => '1',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(BookingStatus::RECEIVED, $this->bookingRepository->findById($foreign->id)?->status);
    }

    /**
     * The page without its step discs: the disc of « Réservation
     * confirmée » or « Location clôturée » runs the transition too (#708,
     * IT-14), a second way into the same decision rather than a second
     * decision.
     */
    private static function withoutStepDiscs(string $body): string
    {
        return (string) preg_replace('#<form[^>]*data-step-disc[^>]*>.*?</form>#s', '', $body);
    }

    private function completeTheAgreement(RentalBooking $booking): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO rental_documents (booking_id, file_id, document_type, version, is_for_renter, sent_at)
             VALUES (?, 1, ?, 1, 1, ?)'
        );
        $insert->execute([$booking->id, 'contract', '2027-01-02 10:00:00']);
        $insert->execute([$booking->id, 'signed_contract', null]);
    }

    private function markStep(RentalBooking $booking, string $key, bool $done = true): Response
    {
        return $this->post('/mes-locations/etape', 'markMilestone', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'milestone_key' => $key,
            'done' => $done ? '1' : '0',
        ]);
    }

    /**
     * **Any step still to do is completed by hand from its disc** (#708,
     * IT-14), never « Demande reçue » nor « Dates bloquées », and every
     * disc that does something is a named button.
     */
    public function testEveryStepToDoCanBeTickedFromItsDisc(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->confirm($booking);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        preg_match_all('#<li class="step-item[^"]*"\s+data-milestone="([a-z_]+)" data-kind="([a-z]+)".*?</li>#s', $body, $steps, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(14, count($steps), 'the journey renders every step');

        $tickable = [];
        foreach ($steps as [$markup, $key]) {
            if (str_contains($markup, 'action="/mes-locations/etape"')) {
                $tickable[] = $key;
                $this->assertStringContainsString('<input type="hidden" name="done" value="1">', $markup, $key);
                $this->assertMatchesRegularExpression(
                    '#<span class="visually-hidden">Marquer « [^»]+ » comme fait</span>#',
                    $markup,
                    $key
                );
            }
        }
        $this->assertContains('arrival_inventory', $tickable);
        $this->assertNotContains('request_received', $tickable);
        $this->assertNotContains('hold', $tickable);
        $this->assertNotContains('closed', $tickable, 'a status is run, never ticked');
    }

    /**
     * Where « État des lieux » keeps the inventory (#708, IT-17), the
     * walk-through is done THERE, and nothing here ticks it.
     */
    public function testAnInventoryKeptOnItsOwnPageIsNotTickedHere(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->confirm($booking);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('data-kind="here"', self::step($body, 'arrival_inventory'));
        $this->assertStringNotContainsString('data-mark-step', $body);
    }

    /**
     * A stretch the booking has not reached is visible and inert: no
     * button, and a box that cannot be ticked (D5).
     */
    public function testAStretchNotYetReachedOffersNothingToPress(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertSame(1, preg_match('#<section class="border-bottom" data-phase="stay">.*?</section>#s', $body, $stay));

        // No action at all: neither a transition nor a link to a box.
        $this->assertStringNotContainsString('action="/mes-locations/statut"', $stay[0]);
        $this->assertStringNotContainsString('<a class="btn', $stay[0]);
        // The steps are there — a step that vanished would read as skipped
        // — but their discs are inert: nothing to tick before the stretch
        // is reached.
        $this->assertStringContainsString('data-milestone="arrival_inventory"', $stay[0]);
        $this->assertStringNotContainsString('action="/mes-locations/etape"', $stay[0]);
        $this->assertDoesNotMatchRegularExpression('#<button type="submit"(?![^>]*disabled)[^>]*>#', $stay[0]);
    }

    /**
     * Ticking writes a real fact — who and when — the line says it, and the
     * history carries it like any other action. Unticking undoes it, and
     * says so too.
     */
    public function testTickingAStepRecordsWhoAndWhenAndTheHistorySaysSo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->confirm($booking);

        $this->assertSame(302, $this->markStep($booking, 'arrival_inventory')->getStatusCode());

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $step = self::step($body, 'arrival_inventory');
        $this->assertStringContainsString('<span class="visually-hidden">Fait :</span>', $step);
        $this->assertStringContainsString('Coché à la main', $step);
        $this->assertStringContainsString(' le ' . (new \DateTimeImmutable())->format('d/m/Y'), $step);
        // Reopened from the same disc.
        $this->assertStringContainsString('<span class="visually-hidden">Rouvrir « État des lieux d&#039;entrée »</span>', $step);
        $this->assertStringContainsString('Étape cochée à la main', self::panel($body, 'history'));

        $this->markStep($booking, 'arrival_inventory', false);
        $again = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('<span class="visually-hidden">À faire :</span>', self::step($again, 'arrival_inventory'));
    }

    /**
     * **Every status decision is offered once, on the whole page** — in the
     * heading, never again in a step. The case that broke it: a confirmed
     * booking whose next step is a link (« Préparer le contrat »), so the
     * heading puts forward a link and offers closing and cancelling among
     * the others, while the « Location clôturée » step further down offered
     * them a second time.
     */
    public function testEveryStatusDecisionIsOfferedOnceOnThePage(): void
    {
        $this->loginAsManager();
        $undecided = $this->createBooking();
        $confirmed = $this->createBooking(null, 'LOC-A2B3C5');
        $this->confirm($confirmed);

        foreach ([$undecided, $confirmed] as $booking) {
            $booking = $this->bookingRepository->findById($booking->id);
            $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

            foreach (\Modules\Rental\Booking\BookingTransition::allowedFrom($booking->status) as $to) {
                // Confirming is offered by its own line once the agreement
                // is complete, never as one decision among others (#708, IT-13).
                if ($to === BookingStatus::CONFIRMED) {
                    continue;
                }
                $this->assertSame(
                    1,
                    substr_count(self::withoutStepDiscs($body), 'name="status" value="' . $to->value . '"'),
                    "{$booking->status->value}: « {$to->value} » is not offered exactly once"
                );
            }
        }
    }

    /**
     * The form is never the boundary: a hand-made POST for a derived step,
     * for a walk-through the stay page keeps, or for a stretch not reached
     * yet stores nothing.
     */
    public function testAHandMadePostCannotTickWhatIsNotTickedByHand(): void
    {
        $this->loginAsManager();
        $undecided = $this->createBooking();
        $this->markStep($undecided, 'arrival_inventory');

        $confirmed = $this->createBooking(null, 'LOC-A2B3C5');
        $this->confirm($confirmed);
        $this->markStep($confirmed, 'deposit_received');
        $this->markStep($confirmed, 'confirmed');

        $marks = new \Modules\Rental\Repository\RentalMilestoneMarkRepository($this->pdo);
        $this->assertSame([], $marks->findForBooking($undecided->id), 'a stretch not yet reached was ticked');
        $this->assertSame([], $marks->findForBooking($confirmed->id), 'a derived step was ticked');
    }

    public function testTickingAStepIsItsManagersAlone(): void
    {
        $booking = $this->createBooking();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');

        AuthSession::login(1, 'nobody@test.be', 'identified');
        $this->assertSame(404, $this->markStep($booking, 'arrival_inventory')->getStatusCode());

        $this->loginAsManager();
        $response = $this->post('/mes-locations/etape', 'markMilestone', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $foreign->id,
            'milestone_key' => 'arrival_inventory',
            'done' => '1',
        ]);
        $this->assertSame(404, $response->getStatusCode());
    }

    // ── The four pages of a booking's file (issue #462, IT-01) ──────────

    /**
     * @return array<string, array{BookingPage}>
     */
    public static function filePages(): array
    {
        $cases = [];
        foreach (BookingPage::cases() as $page) {
            $cases[$page->value] = [$page];
        }

        return $cases;
    }

    /**
     * The file's authorisation, page by page: a manager of the asset gets
     * each page, somebody who manages nothing gets a 404 — never a 403,
     * since « this exists but is not yours » is itself a disclosure (§6.6)
     * — and a manager asking for another asset's booking gets a 404 too.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filePages')]
    public function testEveryPageOfTheFileIsItsManagersAndNobodyElses(BookingPage $page): void
    {
        $this->withCollectingMailbox();
        // « État des lieux » is offered only where an inventory is kept.
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');

        AuthSession::login(1, 'nobody@test.be', 'identified');
        $this->assertSame(404, $this->filePage($page, 'local-saint-georges', $booking->id)->getStatusCode());

        $this->loginAsManager();
        $response = $this->filePage($page, 'local-saint-georges', $booking->id);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(404, $this->filePage($page, 'local-saint-georges', $foreign->id)->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('filePages')]
    public function testAnAnonymousVisitorIsRefusedEveryPageOfTheFile(BookingPage $page): void
    {
        $booking = $this->createBooking();

        $this->assertContains(
            $this->filePage($page, 'local-saint-georges', $booking->id)->getStatusCode(),
            [302, 401, 403]
        );
    }

    /**
     * The breadcrumb is the only way back to the asset once the booking's
     * rail has replaced the asset's, so every page must carry it with real
     * links: the managed space, the asset, its bookings list.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filePages')]
    public function testTheBreadcrumbLeadsBackToTheAssetFromEveryPage(BookingPage $page): void
    {
        $this->loginAsManager();
        $this->withCollectingMailbox();
        // « État des lieux » is offered only where an inventory is kept.
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();

        $body = (string) $this->filePage($page, 'local-saint-georges', $booking->id)->getBody();
        $this->assertSame(1, preg_match('#<nav class="breadcrumb-bar.*?</nav>#s', $body, $bar), 'no breadcrumb');

        foreach ([
            '/mes-locations' => 'Gérer mes locations',
            '/mes-locations/local-saint-georges' => 'Local Saint-Georges',
            '/mes-locations/local-saint-georges/reservations' => 'Réservations',
        ] as $url => $label) {
            $this->assertMatchesRegularExpression(
                '#<a href="' . preg_quote($url, '#') . '"[^>]*>' . preg_quote($label, '#') . '</a>#',
                $bar[0],
                "{$page->value}: no way back to {$url}"
            );
        }
        $this->assertStringContainsString('aria-current="page">' . $booking->reference . '</li>', $bar[0]);
    }

    /**
     * One rail, and it is the booking's: the asset's chips are gone from
     * these pages rather than stacked above them, and « Séjour » is not a
     * chip — it is one level deeper, reached from the dashboard.
     */
    public function testTheBookingsRailReplacesTheAssets(): void
    {
        $this->loginAsManager();
        $this->withCollectingMailbox();
        // « État des lieux » is offered only where an inventory is kept.
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $base = '/mes-locations/local-saint-georges/reservations/' . $booking->id;

        foreach (BookingPage::cases() as $page) {
            $body = (string) $this->filePage($page, 'local-saint-georges', $booking->id)->getBody();
            $this->assertSame(1, preg_match('#id="rental-booking-picker".*?</nav>#s', $body, $rail), "{$page->value}: no rail");

            $this->assertStringNotContainsString('rental-management-picker', $body, "{$page->value} still stacks the asset's rail");
            foreach (BookingPage::cases() as $chip) {
                $this->assertStringContainsString('href="' . $chip->url($base) . '"', $rail[0]);
                $this->assertStringContainsString('<span>' . $chip->label() . '</span>', $rail[0]);
            }
            $this->assertStringNotContainsString('/sejour', $rail[0], 'Séjour is not a chip');
            $this->assertMatchesRegularExpression(
                '#href="' . preg_quote($page->url($base), '#') . '"[^>]*\sactive"#',
                $rail[0],
                "{$page->value} is not the selected chip"
            );
        }
    }

    /**
     * Each page loads what it renders: Finances — refreshed after every
     * price line — reads neither the mailbox nor anything else of another
     * page's, and only Courrier asks the mailbox for the booking's mail.
     */
    public function testAPageReadsOnlyWhatItRenders(): void
    {
        $this->loginAsManager();
        $inbound = $this->withCollectingMailbox();
        $booking = $this->createBooking();

        $inbound->expects($this->never())->method('findForReference');
        foreach ([BookingPage::DASHBOARD, BookingPage::FINANCES, BookingPage::DOCUMENTS] as $page) {
            $this->assertSame(200, $this->filePage($page, 'local-saint-georges', $booking->id)->getStatusCode());
        }
    }

    public function testOnlyTheMailPageAsksForTheBookingsMail(): void
    {
        $this->loginAsManager();
        $inbound = $this->withCollectingMailbox();
        $booking = $this->createBooking();

        $inbound->expects($this->once())->method('findForReference')->willReturn([]);
        $this->assertSame(200, $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id)->getStatusCode());
    }

    /**
     * A form posted WITHOUT JavaScript comes back to the page it was on —
     * a payment recorded on Finances does not land the manager on the
     * dashboard — and the field that says so can only ever name one of the
     * booking's own pages: anything else falls back to the dashboard,
     * never to a URL.
     */
    public function testAFormPostedWithoutJavaScriptReturnsToItsOwnPage(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $base = '/mes-locations/local-saint-georges/reservations/' . $booking->id;

        foreach (['finances' => $base . '/finances', 'https://ailleurs.example/' => $base, '' => $base] as $sent => $expected) {
            $response = $this->post('/mes-locations/commentaire', 'addComment', [
                'asset_id' => (string) $this->assetId,
                'booking_id' => (string) $booking->id,
                'booking_page' => $sent,
                'body' => 'Un mot.',
            ]);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame($expected, $response->getHeaders()['Location'] ?? null, "sent « {$sent} »");
        }
    }

    /**
     * Every form of the boxes that left the dashboard says which page it is
     * on, or the fallback above would send it back to the dashboard.
     */
    public function testEveryFormOfAPageSaysWhichPageItIsOn(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        // Finances lost its forms with the price lines (#927): the price is
        // set on a modification's own page.
        foreach ([BookingPage::DOCUMENTS] as $page) {
            $body = (string) $this->filePage($page, 'local-saint-georges', $booking->id)->getBody();
            $main = substr($body, (int) strpos($body, 'data-rental-booking'));

            $forms = substr_count($main, 'name="booking_id"');
            $this->assertGreaterThan(0, $forms, "{$page->value} renders no form");
            $this->assertSame(
                $forms,
                substr_count($main, 'name="booking_page" value="' . $page->value . '"'),
                "a form on {$page->value} does not say where it was posted from"
            );
        }
    }

    /**
     * A journey line whose work is done on another page links to that
     * page, box anchor included — collapse-anchor.js opens the box there.
     * A fragment alone would scroll to a box this page no longer has.
     */
    public function testAJourneyLinkAimsAtThePageItsBoxIsOn(): void
    {
        $this->loginAsManager();
        // Confirmed, with an inventory kept on the site: the walk-through
        // is what comes next, and its way is « État des lieux ».
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->confirm($booking);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();
        $base = '/mes-locations/local-saint-georges/reservations/' . $booking->id;

        $this->assertStringContainsString(
            'href="' . $base . '/etat-des-lieux#dossier-inventory"',
            self::panel($body, 'next-step')
        );
        $this->assertStringNotContainsString('href="#dossier-', $body);
    }

    /**
     * While the renter is the one expected, nothing is put forward (#708,
     * IT-19): the heading says what is awaited.
     */
    public function testNothingIsPutForwardWhileTheRenterSigns(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $this->documentService->forBooking($booking->id)[0]->id,
        ]);

        $nextStep = self::panel((string) $this->bookingPage('local-saint-georges', $booking->id)->getBody(), 'next-step');

        $this->assertStringContainsString('Le contrat attend la signature du locataire', $nextStep);
        $this->assertStringNotContainsString('btn btn-primary', $nextStep);
    }

    public function testTheInventoryPageSaysItDoesNotWorkOffline(): void
    {
        // §6.23: these are write pages, never cached. The page tells a
        // manager the workaround rather than letting them discover it by
        // losing an inventory on the way home.
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();

        $body = (string) $this->filePage(BookingPage::INVENTORY, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('en ligne', $body);
        $this->assertStringContainsString('hotographiez sur place', $body);
    }

    public function testAManagerRecordsAReading(): void
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

    public function testAReadingIsSavedWhenThePhotoInputWasLeftEmpty(): void
    {
        // §6.22: the photo is optional. A browser nevertheless posts the
        // untouched file input as an UPLOAD_ERR_NO_FILE entry, and handing
        // that to UploadHandler refused the whole reading with « Erreur
        // lors de l'envoi du fichier (code 4). » — losing what the manager
        // had just typed in front of the meter.
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Électricité', \Modules\Rental\Stay\MeterKind::ELECTRICITY, 'kWh', null
        );
        $booking = $this->createBooking();
        $_FILES['photo'] = self::EMPTY_FILE_INPUT;

        $this->post('/mes-locations/releve', 'recordReading', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'meter_id' => (string) $meterId,
            'phase' => 'arrival',
            'value' => '1234,567',
        ]);

        $this->assertSame(
            1234567,
            (int) $this->pdo->query('SELECT value_milli FROM rental_meter_readings')->fetchColumn()
        );
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
    }

    public function testAReadingRedirectsBackToTheInventoryPageNotTheDashboard(): void
    {
        // A manager recording eight readings should land where they were
        // (#708, IT-17: the meters are read on « État des lieux »).
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Eau', \Modules\Rental\Stay\MeterKind::WATER, 'm³', null
        );
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/releve', 'recordReading', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'inventory',
            'meter_id' => (string) $meterId,
            'phase' => 'arrival',
            'value' => '12',
        ]);

        $this->assertStringEndsWith('/etat-des-lieux', (string) $response->getHeaders()['Location']);
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

        // Under assessment first, with what it waits for (#980).
        $this->post('/mes-locations/incident-decision', 'decideIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'incident_id' => (string) $incidents[0]->id,
            'decision' => 'pending',
            'amount' => '50,00',
            'awaiting' => 'Assurance du locataire',
            'reference' => 'SIN-2027-118',
            'waive_reason' => '',
        ]);
        $pending = $this->stayService->incidentsFor($booking->id)[0];
        $this->assertSame(\Modules\Rental\Stay\IncidentDecision::PENDING, $pending->decision);
        $this->assertSame('Assurance du locataire', $pending->awaiting);
        $this->assertSame('SIN-2027-118', $pending->reference);

        $this->post('/mes-locations/incident-decision', 'decideIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'incident_id' => (string) $incidents[0]->id,
            'decision' => 'charge',
            'amount' => '30,00',
            'awaiting' => 'Assurance du locataire',
            'reference' => 'SIN-2027-118',
        ]);

        $decided = $this->stayService->incidentsFor($booking->id)[0];
        $this->assertSame(\Modules\Rental\Stay\IncidentDecision::CHARGE, $decided->decision);
        $this->assertSame(3000, $decided->decidedAmountCents);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('Décision enregistrée : Facturer.', $flash['message'] ?? null);
    }

    public function testTheRetiredWithholdIsNoLongerADecision(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->stayService->reportIncident($booking, 'Vitre cassée', 5000, [], 1);

        $this->post('/mes-locations/incident-decision', 'decideIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'incident_id' => (string) $id,
            'decision' => 'withhold',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(
            \Modules\Rental\Stay\IncidentDecision::PENDING,
            $this->stayService->incidentsFor($booking->id)[0]->decision
        );
    }

    public function testAnOverlongDecisionTextIsRefusedAndSaysSo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->stayService->reportIncident($booking, 'Vitre cassée', 5000, [], 1);

        $this->post('/mes-locations/incident-decision', 'decideIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'incident_id' => (string) $id,
            'decision' => 'waive',
            'waive_reason' => str_repeat('x', 501),
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('500 caractères au plus', $flash['message'] ?? '');
        $this->assertSame(
            \Modules\Rental\Stay\IncidentDecision::PENDING,
            $this->stayService->incidentsFor($booking->id)[0]->decision
        );
    }

    /**
     * « Trancher » offers the three states as choices, says the current one
     * and what each does, and shows only the fields of the current state
     * (#980, chantier §2.1).
     */
    public function testTrancherOffersTheThreeStatesWithTheirConsequences(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $id = $this->stayService->reportIncident($booking, 'Vitre cassée', 5000, [], 1);
        $this->stayService->decideIncident(
            $booking,
            $id,
            \Modules\Rental\Stay\IncidentDecision::PENDING,
            null,
            1,
            'Expertise',
            'SIN-42'
        );

        $body = (string) $this->filePage(BookingPage::INVENTORY, 'local-saint-georges', $booking->id)->getBody();

        foreach (\Modules\Rental\Stay\IncidentDecision::cases() as $state) {
            $this->assertStringContainsString('value="' . $state->value . '"', $body);
            $this->assertStringContainsString(htmlspecialchars($state->consequence(), ENT_QUOTES), $body);
        }
        $this->assertStringNotContainsString('value="withhold"', $body);
        $this->assertMatchesRegularExpression('/value="pending"\s+aria-describedby="[^"]+"\s+checked/', $body);
        $this->assertStringContainsString('(état actuel)', $body);
        $this->assertStringContainsString('id="incident-' . $id . '"', $body);
        $this->assertStringContainsString('En attente de : Expertise', $body);
        $this->assertStringContainsString('value="SIN-42"', $body);
        // The reason for not billing is the one field of another state:
        // rendered, hidden, so switching back and forth loses nothing.
        $this->assertMatchesRegularExpression('/data-incident-field="waive"\s+hidden/', $body);
        $this->assertDoesNotMatchRegularExpression('/data-incident-field="pending"\s+hidden/', $body);
    }

    public function testAnIncidentIsSavedWhenThePhotoInputWasLeftEmpty(): void
    {
        // Same untouched file input as the reading above (§6.23): a damage
        // noticed without a camera to hand must still be recordable.
        $this->loginAsManager();
        $booking = $this->createBooking();
        $_FILES['photo'] = self::EMPTY_FILE_INPUT;

        $this->post('/mes-locations/incident', 'reportIncident', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'description' => 'Vitre cassée',
            'amount' => '50,00',
        ]);

        $this->assertCount(1, $this->stayService->incidentsFor($booking->id));
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
    }

    public function testAnIncidentOfAnotherBookingCannotBeDecidedHere(): void
    {
        $this->loginAsManager();
        $mine = $this->createBooking(null, 'LOC-A2B3C4');
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');
        $foreignId = $this->stayService->reportIncident($foreign, 'Vitre cassée', 5000, [], 1);

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
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->completeTheAgreement($booking);

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'confirmed',
        ]);

        $this->assertCount(1, $this->stayService->inventoryFor($booking->id));
    }

    // ── Meters and inventory, without reloading (#708, IT-10) ───────────

    public function testAManagerAddsAMeterAndGetsItsRowBack(): void
    {
        $this->loginAsManager();

        $response = $this->postJsonTo('/mes-locations/{slug}/gabarits/compteurs', 'addMeter', 'local-saint-georges', [
            'label' => 'Électricité',
            'kind' => 'electricity',
            'unit' => 'kWh',
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success'] ?? false, (string) $response->getBody());
        $meters = $this->stayService->metersFor($this->assetId);
        $this->assertCount(1, $meters);
        $this->assertStringContainsString('class="list-editor-item', (string) $data['html']);
        $this->assertStringContainsString('data-id="' . $meters[0]->id . '"', (string) $data['html']);
        $this->assertStringContainsString('Électricité', (string) $data['html']);
    }

    public function testAMeterWithoutANameIsRefusedInFrench(): void
    {
        $this->loginAsManager();

        $response = $this->postJsonTo('/mes-locations/{slug}/gabarits/compteurs', 'addMeter', 'local-saint-georges', [
            'label' => '  ',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('nom', (string) (json_decode((string) $response->getBody(), true)['error'] ?? ''));
    }

    public function testAMeterCannotBeConfiguredOnAnAssetTheManagerDoesNotManage(): void
    {
        $this->loginAsManager();

        $response = $this->postJsonTo('/mes-locations/{slug}/gabarits/compteurs', 'addMeter', 'local-des-autres', [
            'label' => 'Électricité',
            'kind' => 'electricity',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->stayService->metersFor($this->otherAssetId));
    }

    public function testAManagerRetiresAMeterWithoutReloading(): void
    {
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter($this->assetId, 'Eau', \Modules\Rental\Stay\MeterKind::WATER, 'm³', null);

        $response = $this->postJsonTo(
            '/mes-locations/{slug}/gabarits/compteurs/retirer',
            'retireMeter',
            'local-saint-georges',
            ['id' => $meterId]
        );

        $this->assertTrue(json_decode((string) $response->getBody(), true)['success'] ?? false);
        $this->assertSame([], $this->stayService->metersFor($this->assetId));
    }

    /**
     * With a meter fee, no help under the price at all; without one, a
     * single line under the row with the link to the pricing.
     */
    public function testThePriceHelpDependsOnWhetherAMeterFeeExists(): void
    {
        $this->loginAsManager();
        $line = 'Pour facturer la consommation, ajoutez un frais « relevé de compteur »';

        $this->assertStringContainsString($line, $this->templatesPage());

        $this->pricingService->addFee($this->assetId, 'Électricité', 'meter', 35, 'kWh');
        $body = $this->templatesPage();

        $this->assertStringNotContainsString($line, $body);
        $this->assertStringContainsString('Relevé seul, non facturé', $body);
    }

    public function testAnInventoryItemIsAddedWithItsSortAndCount(): void
    {
        $this->loginAsManager();

        $response = $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux', 'addInventoryItem', 'local-saint-georges', [
            'label' => 'Chaises',
            'kind' => 'quantity',
            'expected_count' => '40',
        ]);
        $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux', 'addInventoryItem', 'local-saint-georges', [
            'label' => 'Cuisine propre',
            'kind' => 'yes_no',
            'expected_count' => '1',
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertTrue($data['success'] ?? false, (string) $response->getBody());
        $this->assertStringContainsString('value="40"', (string) $data['html']);
        $items = $this->stayService->inventoryTemplateFor($this->assetId);
        $this->assertSame(['Chaises', 'Cuisine propre'], array_column($items, 'label'));
        $this->assertSame(40, $items[0]['expected_count']);
        $this->assertSame(\Modules\Rental\Stay\InventoryKind::YES_NO, $items[1]['kind']);
        $this->assertNull($items[1]['expected_count']);
    }

    public function testAnInventoryItemIsChangedInPlace(): void
    {
        $this->loginAsManager();
        $itemId = $this->stayService->addInventoryItem($this->assetId, 'Clés');

        $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux/modifier', 'updateInventoryItem', 'local-saint-georges', [
            'id' => $itemId,
            'kind' => 'quantity',
            'expected_count' => '3',
        ]);
        $this->assertSame(3, $this->stayService->inventoryItem($this->assetId, $itemId)['expected_count'] ?? null);

        $refused = $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux/modifier', 'updateInventoryItem', 'local-saint-georges', [
            'id' => $itemId,
            'kind' => 'quantity',
            'expected_count' => 'trois',
        ]);
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertSame(3, $this->stayService->inventoryItem($this->assetId, $itemId)['expected_count'] ?? null);
    }

    public function testTheInventoryIsReorderedAndRemovedWithoutReloading(): void
    {
        $this->loginAsManager();
        $keys = $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $chairs = $this->stayService->addInventoryItem($this->assetId, 'Chaises');

        $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux/ordre', 'reorderInventory', 'local-saint-georges', [
            'ids' => [(string) $chairs, (string) $keys],
        ]);
        $this->assertSame(['Chaises', 'Clés'], array_column($this->stayService->inventoryTemplateFor($this->assetId), 'label'));

        $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux/retirer', 'removeInventoryItem', 'local-saint-georges', [
            'id' => $chairs,
        ]);
        $this->assertSame(['Clés'], array_column($this->stayService->inventoryTemplateFor($this->assetId), 'label'));
    }

    /** An item id alone must not reach another asset's checklist. */
    public function testAnotherAssetsInventoryItemCannotBeRemovedFromHere(): void
    {
        $this->loginAsManager();
        $theirs = $this->stayService->addInventoryItem($this->otherAssetId, 'Chaises');

        $response = $this->postJsonTo('/mes-locations/{slug}/gabarits/etat-des-lieux/retirer', 'removeInventoryItem', 'local-saint-georges', [
            'id' => $theirs,
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->stayService->inventoryTemplateFor($this->otherAssetId));
    }

    /** The old « Ordre » field is gone; the inventory list is draggable. */
    public function testTheInventoryListIsSortableAndHasNoOrderField(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');

        $body = $this->templatesPage();
        $inventory = $this->between($body, 'id="inventory-list"', '</form>');

        $this->assertStringNotContainsString('name="sort_order"', $body);
        $this->assertStringContainsString('list-editor-drag-handle', $inventory);
        $this->assertStringContainsString('data-in-place="true"', $inventory);
        $this->assertStringNotContainsString('list-editor-drag-handle', $this->between($body, 'id="meter-list"', 'id="inventory-list"'));
    }

    // ── « État des lieux » (#708, IT-17) ────────────────────────────────

    /** @return array{0: RentalBooking, 1: int, 2: int} the booking, the chairs' line, the kitchen's */
    private function bookingWithAnInventory(): array
    {
        $this->stayService->addInventoryItem($this->assetId, 'Chaises', \Modules\Rental\Stay\InventoryKind::QUANTITY, 40);
        $this->stayService->addInventoryItem($this->assetId, 'Cuisine propre', \Modules\Rental\Stay\InventoryKind::YES_NO);
        $booking = $this->createBooking();
        $this->stayService->snapshotInventory($booking, $this->assetId);
        [$chairs, $kitchen] = $this->stayService->inventoryFor($booking->id);

        return [$booking, $chairs['id'], $kitchen['id']];
    }

    private function inventoryPage(RentalBooking $booking): string
    {
        $response = $this->filePage(BookingPage::INVENTORY, 'local-saint-georges', $booking->id);
        $this->assertSame(200, $response->getStatusCode());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    /** @return array{success: bool, type: string, message: ?string} */
    private function saveLine(RentalBooking $booking, int $inventoryId, string $phase, string $value): array
    {
        $response = $this->postAsync('/mes-locations/etat-des-lieux/ligne', 'saveInventoryLine', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'inventory',
            'inventory_id' => (string) $inventoryId,
            'phase' => $phase,
            'value' => $value,
            'note' => '',
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * « Valider, signer et envoyer » as the signing screen posts it (#989,
     * lot E): the renter absent unless `$renter` says more, the manager's
     * signature traced on the spot.
     *
     * @param array<string, string> $renter
     */
    private function validateInventoryPhase(RentalBooking $booking, string $phase, array $renter = []): Response
    {
        return $this->post('/mes-locations/etat-des-lieux/valider', 'validateInventory', $renter + [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'inventory',
            'phase' => $phase,
            'renter_signing' => 'absent',
            'signature_source' => 'new',
            'signature_data' => self::signatureDataUrl(),
        ]);
    }

    /** GET the signing screen of one phase (#989, lot E). */
    private function signingPage(RentalBooking $booking, string $phase): Response
    {
        return $this->get(
            '/mes-locations/{slug}/reservations/{id}/etat-des-lieux/signer',
            '/mes-locations/local-saint-georges/reservations/' . $booking->id . '/etat-des-lieux/signer',
            'signInventory',
            ['phase' => $phase]
        );
    }

    // ── « Valider, signer et envoyer » (#989, lot E) ────────────────────

    /**
     * The renter reads the signing screen: it shows the incidents with
     * their date and photos, and never what a manager decided about them —
     * no amount, no « En attente de », no reference, no reason. Answered
     * `no-store`, like every page carrying a signature's fields.
     */
    public function testTheSigningScreenShowsWhatWasSeenNeverAnAmountAReferenceOrAReason(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();
        $this->validateInventoryPhase($booking, 'arrival');
        $photo = $this->fileRepository->create('rental/photo.jpg', 'photo.jpg', 'image/jpeg', 10, 'identified', 'rental', null);
        $assessed = $this->stayService->reportIncident($booking, 'Porte du réfectoire forcée', 12345, [$photo], 1);
        $this->stayService->decideIncident(
            $booking, $assessed, \Modules\Rental\Stay\IncidentDecision::PENDING, 12345, 1,
            'Le passage de l\'expert', 'SIN-2027-0042'
        );
        $waived = $this->stayService->reportIncident($booking, 'Rayure sur la table', 6789, [], 1);
        $this->stayService->decideIncident(
            $booking, $waived, \Modules\Rental\Stay\IncidentDecision::WAIVE, null, 1, null, null, 'Geste pour le groupe'
        );

        $response = $this->signingPage($booking, 'departure');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control'] ?? null);
        $body = (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
        $main = substr($body, (int) strpos($body, 'data-inventory-signing'));
        $this->assertStringContainsString('Porte du réfectoire forcée', $main);
        $this->assertStringContainsString('Rayure sur la table', $main);
        $this->assertStringContainsString('src="/files/' . $photo . '/thumb"', $main);
        foreach (['123,45', '12345', '67,89', 'expert', 'SIN-2027-0042', 'Geste pour le groupe', 'En attente', 'Trancher', 'Facturer'] as $internal) {
            $this->assertStringNotContainsString($internal, $main, $internal . ' is for the manager, not the renter');
        }
        // The two signers, in one form of the page, the renter's prefixed.
        $this->assertStringContainsString('name="renter_signer_name"', $main);
        $this->assertStringContainsString('name="renter_signature_data"', $main);
        $this->assertStringContainsString('name="signature_data"', $main);
        $this->assertStringContainsString('Sans la signature du locataire, cet état des lieux prouve moins.', $main);
        $this->assertStringContainsString('Valider, signer et envoyer', $main);
        $this->assertStringNotContainsString('data-confirm', $main);
        $this->assertStringNotContainsString('name="signature_file"', $main, 'no import on the spot');
    }

    /** A manager of another asset, or nobody's, is refused the screen. */
    public function testANonManagerIsRefusedTheSigningScreen(): void
    {
        $booking = $this->createBooking();
        AuthSession::login(1, 'nobody@test.be', 'identified');

        $this->assertSame(404, $this->signingPage($booking, 'arrival')->getStatusCode());
    }

    /** A phase that is not the one open goes back to « État des lieux ». */
    public function testAPhaseNotOpenGoesBackToTheInventoryPage(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();

        $response = $this->signingPage($booking, 'departure');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/reservations/' . $booking->id . '/etat-des-lieux', (string) $response->getHeaders()['Location']);
    }

    /**
     * The renter signs on the spot: their name and drawing go into the PDF,
     * the row says « on_site » and names the session's account — never the
     * account a posted `user_account_id` names, and the notification goes
     * to the session's account alone.
     */
    public function testTheRenterSignsHereAndThePostedAccountIdIsIgnored(): void
    {
        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $this->encryption);
        $session = $accounts->create('session@test.be');
        $accounts->updateProfile($session->id, 'Anne', 'Dupont');
        $other = $accounts->create('autre@test.be');
        $accounts->updateProfile($other->id, 'Paul', 'Autre');
        $this->addManager($this->assetId, 'session@test.be');
        AuthSession::login($session->id, 'session@test.be', 'identified');
        [$booking] = $this->bookingWithAnInventory();
        $this->notified = [];

        $this->validateInventoryPhase($booking, 'arrival', [
            'renter_signing' => 'on_site',
            'renter_signer_name' => 'Jeanne Martin',
            'renter_signature_data' => self::signatureDataUrl(),
            'user_account_id' => (string) $other->id,
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $validation = $this->stayService->inventoryValidations($booking->id)['arrival'] ?? null;
        $this->assertNotNull($validation);
        $this->assertSame(\Modules\Rental\Stay\RenterSigning::ON_SITE, $validation['renter_signing']);
        $this->assertSame($session->id, $validation['validated_by_account_id']);
        $document = $this->documentService->find((int) $validation['document_id']);
        $this->assertNotNull($document);
        $text = $this->renderedTextOf($document);
        $this->assertStringContainsString('Accepté sur place par Jeanne Martin, signé au doigt', $text);
        $this->assertStringContainsString('Signé au doigt par Anne Dupont', $text);
        $this->assertStringNotContainsString('Paul', $text);
        $this->assertCount(1, $this->notified);
        $this->assertSame([['userAccountId' => $session->id, 'memberId' => null]], $this->notified[0]['recipients']);
    }

    /**
     * « Le locataire signe ici » with nothing traced in the renter's frame
     * goes back to the signing screen, and nothing is validated.
     */
    public function testARenterSigningHereWithoutADrawingGoesBackToTheScreen(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();

        $response = $this->validateInventoryPhase($booking, 'arrival', [
            'renter_signing' => 'on_site',
            'renter_signer_name' => 'Jeanne Martin',
        ]);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertStringEndsWith('/etat-des-lieux/signer?phase=arrival', (string) $response->getHeaders()['Location']);
        $this->assertSame([], $this->stayService->inventoryValidations($booking->id));
        $this->assertSame([], $this->documentEmails);
    }

    /** A renter's choice the screen does not offer is refused. */
    public function testARenterChoiceOutsideTheThreeIsRefused(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();

        $this->validateInventoryPhase($booking, 'arrival', ['renter_signing' => 'paper']);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->stayService->inventoryValidations($booking->id));
    }

    /**
     * A signed inventory is never deleted from Documents, sent or not: the
     * page does not offer it, and the server refuses it.
     */
    public function testASignedInventoryCannotBeDeletedEvenUnsent(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();
        $this->validateInventoryPhase($booking, 'arrival');
        $documentId = (int) ($this->stayService->inventoryValidations($booking->id)['arrival']['document_id'] ?? 0);
        $this->pdo->exec('UPDATE rental_documents SET sent_at = NULL WHERE id = ' . $documentId);

        $this->post('/mes-locations/document-supprimer', 'deleteDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $documentId,
        ]);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertNotNull($this->documentService->find($documentId));
        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringNotContainsString('name="document_id" value="' . $documentId . '"', $this->deleteFormsOf($documents));
    }

    /** The « Supprimer » forms of a page, and nothing else of it. */
    private function deleteFormsOf(string $body): string
    {
        preg_match_all('#<form method="post" action="/mes-locations/document-supprimer".*?</form>#s', $body, $forms);

        return implode("\n", $forms[0]);
    }

    /** An asset with nothing to walk has no such page, nor a chip for it. */
    /**
     * A photo stored for a reading or an incident whose own row is then
     * refused — a frozen phase, a value that does not parse — is taken
     * back: nothing would ever reference it.
     */
    public function testARefusedReadingOrIncidentLeavesNoPhotoBehind(): void
    {
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Électricité', \Modules\Rental\Stay\MeterKind::ELECTRICITY, 'kWh', null
        );
        $booking = $this->createBooking();
        $files = fn(): int => (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        $before = $files();

        // A value that does not parse, on an open phase.
        $this->postWithPhoto('/mes-locations/releve', 'recordReading', [
            'meter_id' => (string) $meterId, 'phase' => 'arrival', 'value' => 'beaucoup',
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame($before, $files());

        // A frozen phase: the arrival validated, then the departure.
        $this->stayService->recordInventoryValidation($booking, \Modules\Rental\Stay\ReadingPhase::ARRIVAL, new \DateTimeImmutable(), null);
        $this->postWithPhoto('/mes-locations/releve', 'recordReading', [
            'meter_id' => (string) $meterId, 'phase' => 'arrival', 'value' => '1234',
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame($before, $files());

        $this->stayService->recordInventoryValidation($booking, \Modules\Rental\Stay\ReadingPhase::DEPARTURE, new \DateTimeImmutable(), null);
        $this->postWithPhoto('/mes-locations/incident', 'reportIncident', [
            'description' => 'Vitre cassée', 'amount' => '',
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame($before, $files());

        // And an accepted one keeps its photo.
        $other = $this->createBooking(null, 'LOC-2027-0077');
        $this->postWithPhoto('/mes-locations/releve', 'recordReading', [
            'meter_id' => (string) $meterId, 'phase' => 'arrival', 'value' => '1234',
        ], $other);
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame($before + 1, $files());
    }

    /** @param array<string, string> $body */
    private function postWithPhoto(string $path, string $action, array $body, ?RentalBooking $booking = null): void
    {
        $booking ??= $this->bookingRepository->findByReference('LOC-A2B3C4');
        $this->assertNotNull($booking);
        $image = imagecreatetruecolor(32, 32);
        $temporary = (string) tempnam(sys_get_temp_dir(), 'photo-');
        imagepng($image, $temporary);
        $_FILES['photo'] = [
            'name' => 'compteur.png',
            'type' => 'image/png',
            'tmp_name' => $temporary,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($temporary),
        ];

        $this->post($path, $action, $body + [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
        ]);
        unset($_FILES['photo']);
    }

    // ── Photos (#989) ───────────────────────────────────────────────────

    /**
     * Posts photos under `photos[]` (several) — the field a form with
     * several photos sends — and removes the temporary files afterwards.
     *
     * @param array<string, string> $body
     * @param list<string> $images the bytes of each photo
     */
    private function postWithPhotos(string $path, string $action, array $body, array $images, RentalBooking $booking): Response
    {
        $temporaries = [];
        foreach ($images as $bytes) {
            $temporary = (string) tempnam(sys_get_temp_dir(), 'photo-');
            file_put_contents($temporary, $bytes);
            $temporaries[] = $temporary;
        }
        $_FILES['photos'] = [
            'name' => array_map(static fn(int $i): string => 'IMG_' . $i . '.jpg', array_keys($images)),
            'type' => array_fill(0, count($images), 'image/jpeg'),
            'tmp_name' => $temporaries,
            'error' => array_fill(0, count($images), UPLOAD_ERR_OK),
            'size' => array_map('strlen', $images),
        ];

        try {
            return $this->post($path, $action, $body + [
                'asset_id' => (string) $this->assetId,
                'booking_id' => (string) $booking->id,
            ]);
        } finally {
            unset($_FILES['photos']);
            foreach ($temporaries as $temporary) {
                @unlink($temporary);
            }
        }
    }

    /** A phone photo: JPEG pixels and an EXIF block with a camera make in it. */
    private static function phonePhoto(int $width = 64, int $height = 48): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        $make = "PhoneCam\x00";
        $tiff = 'II' . pack('v', 0x002A) . pack('V', 8) . pack('v', 1)
            . pack('vvVV', 0x010F, 2, strlen($make), 26) . pack('V', 0) . $make;
        $payload = "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    private function countFiles(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
    }

    /** @return list<\Modules\Rental\Stay\IncidentPhoto> */
    private function photosOfTheOnlyIncident(RentalBooking $booking): array
    {
        $incidents = $this->stayService->incidentsFor($booking->id);
        $this->assertCount(1, $incidents);

        return $incidents[0]->photos;
    }

    /**
     * Several photos come with one report, and each is stored as the site
     * keeps a photo: a JPEG with no EXIF, served through FileAccessGuard,
     * its thumbnail beside it. The incident page links to each.
     */
    public function testAnIncidentIsReportedWithSeveralNormalisedPhotos(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->postWithPhotos('/mes-locations/incident', 'reportIncident', [
            'description' => 'Porte du réfectoire forcée', 'amount' => '', 'booking_page' => 'inventory',
        ], [self::phonePhoto(), self::phonePhoto(3000, 1500)], $booking);

        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $photos = $this->photosOfTheOnlyIncident($booking);
        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            $record = $this->fileRepository->findById($photo->fileId);
            $this->assertNotNull($record);
            $this->assertSame('image/jpeg', $record->mimeType);
            $this->assertSame(\Modules\Rental\Service\RentalDocumentService::OWNER_TYPE, $record->ownerType);
            $this->assertSame($booking->id, $record->ownerId);
            $bytes = (string) file_get_contents($this->storagePath . '/' . $record->relativePath);
            $this->assertStringNotContainsString('PhoneCam', $bytes);
            [$width, $height] = (array) getimagesizefromstring($bytes);
            $this->assertLessThanOrEqual(\Core\Image\PhotoNormalizer::MAX_LONG_SIDE, max($width, $height));
            $this->assertFileExists(
                $this->storagePath . '/' . \Core\Photo\ImageVariantService::derivativeRelativePaths($record->relativePath)[0]
            );
        }

        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('/files/' . $photos[0]->fileId . '"', $body);
        $this->assertStringContainsString('Photo 2</a>', $body);
    }

    /** The single `photo` field the page posts today works the same way. */
    public function testTheSinglePhotoFieldIsNormalisedToo(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->postWithPhoto('/mes-locations/incident', 'reportIncident', ['description' => 'Vitre cassée', 'amount' => '']);

        $photos = $this->photosOfTheOnlyIncident($booking);
        $this->assertCount(1, $photos);
        $this->assertSame('image/jpeg', $this->fileRepository->findById($photos[0]->fileId)?->mimeType);
    }

    /**
     * A photo the server cannot read is refused with the normaliser's
     * sentence, and nothing of the report is kept — not the incident, not
     * the other photo already stored.
     */
    public function testAnUnreadablePhotoRefusesTheReportAndKeepsNothing(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $before = $this->countFiles();

        $this->postWithPhotos('/mes-locations/incident', 'reportIncident', [
            'description' => 'Vitre cassée', 'amount' => '',
        ], [self::phonePhoto(), "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64)], $booking);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(\Core\Image\PhotoNormalizer::UNREADABLE, $flash['message'] ?? null);
        $this->assertSame([], $this->stayService->incidentsFor($booking->id));
        $this->assertSame($before, $this->countFiles());
        $this->assertSame([], glob($this->storagePath . '/rental/documents/*') ?: []);
    }

    /**
     * Decision Q6: an HEIC photo on a server whose Imagick cannot read it
     * is refused with the sentence that tells an iPhone owner what to
     * change — never kept as it came, GPS position and all.
     */
    public function testAnHeicPhotoTheServerCannotReadIsRefusedInFrench(): void
    {
        (new \ReflectionProperty(RentalManagementController::class, 'photoNormalizer'))
            ->setValue($this->controller, new \Core\Image\PhotoNormalizer(null));
        $this->loginAsManager();
        $booking = $this->createBooking();
        $before = $this->countFiles();
        $heic = pack('N', 24) . 'ftypheic' . pack('N', 0) . 'mif1heic' . str_repeat("\x00", 64);

        $this->postWithPhotos('/mes-locations/incident', 'reportIncident', [
            'description' => 'Vitre cassée', 'amount' => '',
        ], [$heic], $booking);

        $this->assertSame(\Core\Image\PhotoNormalizer::HEIC_UNREADABLE, \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame([], $this->stayService->incidentsFor($booking->id));
        $this->assertSame($before, $this->countFiles());
    }

    public function testSixPhotosInOneReportAreRefusedBeforeAnyIsStored(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $before = $this->countFiles();

        $this->postWithPhotos('/mes-locations/incident', 'reportIncident', [
            'description' => 'Vitre cassée', 'amount' => '',
        ], array_fill(0, 6, self::phonePhoto()), $booking);

        $this->assertSame('Un incident garde 5 photos au plus.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame($before, $this->countFiles());
    }

    /**
     * Decision Q5: a photo is added to an incident already observed, and
     * one is removed — its file and its thumbnail with it.
     */
    public function testAPhotoIsAddedToAnIncidentAndRemovedFromIt(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $incidentId = $this->stayService->reportIncident($booking, 'Vitre cassée', null, [], null);

        $this->postWithPhotos('/mes-locations/incident/photo', 'addIncidentPhotos', [
            'incident_id' => (string) $incidentId,
        ], [self::phonePhoto(), self::phonePhoto()], $booking);
        $this->assertSame("Photo ajoutée à l'incident.", \Core\Http\FlashMessage::get()['message'] ?? null);
        $photos = $this->photosOfTheOnlyIncident($booking);
        $this->assertCount(2, $photos);
        $record = $this->fileRepository->findById($photos[0]->fileId);
        $this->assertNotNull($record);
        $thumb = $this->storagePath . '/' . \Core\Photo\ImageVariantService::derivativeRelativePaths($record->relativePath)[0];
        $this->assertFileExists($thumb);

        $this->post('/mes-locations/incident/photo/retirer', 'removeIncidentPhoto', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'photo_id' => (string) $photos[0]->id,
        ]);

        $this->assertSame("Photo retirée de l'incident.", \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame([$photos[1]->id], array_map(
            static fn($photo): int => $photo->id,
            $this->photosOfTheOnlyIncident($booking)
        ));
        $this->assertNull($this->fileRepository->findById($photos[0]->fileId));
        $this->assertFileDoesNotExist($this->storagePath . '/' . $record->relativePath);
        $this->assertFileDoesNotExist($thumb);
    }

    /** After the departure is validated, a photo is neither added (and its upload goes) nor removed. */
    public function testIncidentPhotosFreezeWithTheDepartureInventory(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $incidentId = $this->stayService->reportIncident($booking, 'Vitre cassée', null, [], null);
        $this->postWithPhotos('/mes-locations/incident/photo', 'addIncidentPhotos', [
            'incident_id' => (string) $incidentId,
        ], [self::phonePhoto()], $booking);
        $photo = $this->photosOfTheOnlyIncident($booking)[0];
        $this->stayService->recordInventoryValidation($booking, \Modules\Rental\Stay\ReadingPhase::ARRIVAL, new \DateTimeImmutable(), null);
        $this->stayService->recordInventoryValidation($booking, \Modules\Rental\Stay\ReadingPhase::DEPARTURE, new \DateTimeImmutable(), null);
        $before = $this->countFiles();

        $this->postWithPhotos('/mes-locations/incident/photo', 'addIncidentPhotos', [
            'incident_id' => (string) $incidentId,
        ], [self::phonePhoto()], $booking);
        $this->assertSame(
            \Modules\Rental\Service\RentalStayService::INCIDENT_PHOTOS_FROZEN,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame($before, $this->countFiles(), 'the refused upload is taken back');

        $this->post('/mes-locations/incident/photo/retirer', 'removeIncidentPhoto', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'photo_id' => (string) $photo->id,
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertNotNull($this->fileRepository->findById($photo->fileId));
    }

    /** A forged token stores nothing: the CSRF guard runs before any byte is decoded. */
    public function testAPhotoPostWithoutAValidCsrfTokenStoresNothing(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $incidentId = $this->stayService->reportIncident($booking, 'Vitre cassée', null, [], null);
        $before = $this->countFiles();

        $response = $this->postWithPhotos('/mes-locations/incident/photo', 'addIncidentPhotos', [
            '_csrf_token' => 'forged',
            'incident_id' => (string) $incidentId,
        ], [self::phonePhoto()], $booking);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame($before, $this->countFiles());
        $this->assertSame([], $this->photosOfTheOnlyIncident($booking));
    }

    /** A manager of another asset reaches none of this booking's photos. */
    public function testAnotherAssetsManagerCannotTouchThePhotos(): void
    {
        $booking = $this->createBooking();
        $incidentId = $this->stayService->reportIncident($booking, 'Vitre cassée', null, [], null);
        $this->addManager($this->otherAssetId, 'other@test.be');
        AuthSession::login(2, 'other@test.be', 'identified');
        $before = $this->countFiles();

        $response = $this->postWithPhotos('/mes-locations/incident/photo', 'addIncidentPhotos', [
            'incident_id' => (string) $incidentId,
        ], [self::phonePhoto()], $booking);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($before, $this->countFiles());
    }

    /**
     * A reading keeps one photo: a new one replaces the old, whose file
     * goes; and « retirer » takes it off until the phase is validated.
     */
    public function testAReadingsPhotoIsReplacedAndRemoved(): void
    {
        $this->loginAsManager();
        $meterId = $this->stayService->addMeter(
            $this->assetId, 'Électricité', \Modules\Rental\Stay\MeterKind::ELECTRICITY, 'kWh', null
        );
        $booking = $this->createBooking();
        $reading = fn() => $this->stayService->consumptionsFor($booking, $this->assetId)[0]->arrival;
        $body = ['meter_id' => (string) $meterId, 'phase' => 'arrival', 'value' => '1234'];

        $this->postWithPhoto('/mes-locations/releve', 'recordReading', $body, $booking);
        $first = $reading()?->fileId;
        $this->assertNotNull($first);
        $this->postWithPhoto('/mes-locations/releve', 'recordReading', $body, $booking);
        $second = $reading()?->fileId;
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertNull($this->fileRepository->findById($first), 'the replaced photo is gone');

        // Two photos for one reading are refused before either is stored.
        $before = $this->countFiles();
        $this->postWithPhotos('/mes-locations/releve', 'recordReading', $body, [self::phonePhoto(), self::phonePhoto()], $booking);
        $this->assertSame('Une seule photo à la fois ici.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame($before, $this->countFiles());

        $this->post('/mes-locations/releve/photo/retirer', 'removeReadingPhoto', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'meter_id' => (string) $meterId,
            'phase' => 'arrival',
        ]);
        $this->assertSame('Photo du relevé retirée.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertNull($reading()?->fileId);
        $this->assertSame(1_234_000, $reading()?->valueMilli);
        $this->assertNull($this->fileRepository->findById($second));
    }

    /** The photo inputs ask the phone for what the server reads without a converter (Q6). */
    public function testThePhotoInputsAskForJpegPngOrWebp(): void
    {
        $this->loginAsManager();
        $this->stayService->addMeter($this->assetId, 'Électricité', \Modules\Rental\Stay\MeterKind::ELECTRICITY, 'kWh', null);
        $this->stayService->addInventoryItem($this->assetId, 'Chaises');
        $booking = $this->createBooking();

        $body = $this->inventoryPage($booking);

        $this->assertStringContainsString('name="photo" accept="image/jpeg,image/png,image/webp"', $body);
        $this->assertStringNotContainsString('accept="image/*"', $body);
    }

    /**
     * An asset with nothing to walk keeps the page all the same, reduced to
     * the incidents: they live there, and such an asset can be damaged too.
     * No inventory to fill in, no validation to press.
     */
    public function testAnAssetWithNothingToWalkKeepsThePageForItsIncidents(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->assertStringContainsString(
            '/etat-des-lieux"',
            (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody()
        );
        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString("ni éléments d'état des lieux ni compteurs", $body);
        $this->assertStringContainsString('action="/mes-locations/incident"', $body);
        $this->assertStringNotContainsString('data-inventory-sign', $body);
        $this->assertStringNotContainsString('data-inventory-line', $body);
    }

    /**
     * The booking is walked against the checklist copied at its
     * confirmation: emptying the asset's template afterwards does not take
     * its inventory away, nor does filling one later give an empty booking
     * a validation it could never pass.
     */
    public function testTheBookingsOwnChecklistDecidesWhetherItHasAnInventory(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();
        foreach ($this->stayService->inventoryTemplateFor($this->assetId) as $item) {
            $this->stayService->removeInventoryItem($this->assetId, $item['id']);
        }

        $this->assertStringContainsString('data-inventory-sign', $this->inventoryPage($booking));

        $empty = $this->createBooking(null, 'LOC-K7Q2M4');
        $this->stayService->snapshotInventory($empty, $this->assetId);
        $this->stayService->addInventoryItem($this->assetId, 'Clés');

        $body = $this->inventoryPage($empty);
        $this->assertStringNotContainsString('data-inventory-sign', $body);
        $this->assertStringContainsString("ni éléments d'état des lieux ni compteurs", $body);
    }

    /**
     * Nothing is pre-filled: an empty field is « pas encore regardé ». Each
     * line says what it is checked against and offers « = » to take it; a
     * yes/no item is a list, never a box.
     */
    public function testTheArrivalStartsEmptyWithEachLinesReference(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();

        $body = $this->inventoryPage($booking);

        $this->assertStringContainsString('État des lieux d&#039;entrée', $body);
        $this->assertStringContainsString('Attendu : 40', $body);
        $this->assertStringContainsString('Attendu : Oui', $body);
        $this->assertMatchesRegularExpression('#<input type="number"[^>]*name="value" value="" data-inventory-value>#', $body);
        $this->assertStringContainsString('<option value="" selected>—</option>', $body);
        $this->assertStringNotContainsString('type="checkbox"', $body);
        $this->assertStringContainsString('data-inventory-copy="40"', $body);
        $this->assertStringContainsString('aria-label="Reprendre la référence pour Chaises"', $body);
        // Counted on the signing screen, from the lines as they are then.
        $this->assertStringContainsString('2 éléments sans valeur', $this->signingPage($booking, 'arrival')->getBody());
    }

    public function testALineIsSavedAsItIsTypedAndANonsenseValueIsRefused(): void
    {
        $this->loginAsManager();
        [$booking, $chairs] = $this->bookingWithAnInventory();

        $saved = $this->saveLine($booking, $chairs, 'arrival', '38');
        $this->assertTrue($saved['success']);
        $this->assertSame('38', $this->stayService->inventoryFor($booking->id)[0]['arrival_value']);

        $refused = $this->saveLine($booking, $chairs, 'arrival', 'beaucoup');
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('Chaises', (string) $refused['message']);
        $this->assertSame('38', $this->stayService->inventoryFor($booking->id)[0]['arrival_value']);
    }

    public function testTheDepartureCannotBeWrittenBeforeTheArrivalIsValidated(): void
    {
        $this->loginAsManager();
        [$booking, $chairs] = $this->bookingWithAnInventory();

        $refused = $this->saveLine($booking, $chairs, 'departure', '40');

        $this->assertFalse($refused['success']);
        $this->assertNull($this->stayService->inventoryFor($booking->id)[0]['departure_value']);
    }

    /** A meter with no reading blocks: a validated inventory is never completed. */
    public function testAMissingReadingBlocksTheValidation(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();
        $this->stayService->addMeter($this->assetId, 'Eau', \Modules\Rental\Stay\MeterKind::WATER, 'm³', null);

        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('Il manque le relevé de : Eau', $body);
        $this->assertStringContainsString(
            '<button type="button" class="btn btn-primary" disabled>Signer l\'état des lieux d&#039;entrée</button>',
            $body
        );
        $this->assertStringNotContainsString('data-inventory-sign', $body);

        $this->validateInventoryPhase($booking, 'arrival');

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->stayService->inventoryValidations($booking->id));
        $this->assertSame([], $this->documentEmails);
    }

    /**
     * Validating files the PDF with the documents, sends it to the renter,
     * freezes the arrival — and the page moves on to the departure, read
     * against what the arrival found.
     */
    public function testValidatingFilesThePdfSendsItAndFreezesThePhase(): void
    {
        $this->loginAsManager();
        [$booking, $chairs, $kitchen] = $this->bookingWithAnInventory();
        $this->saveLine($booking, $chairs, 'arrival', '38');
        $this->saveLine($booking, $kitchen, 'arrival', 'yes');

        $this->validateInventoryPhase($booking, 'arrival');

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $validation = $this->stayService->inventoryValidations($booking->id)['arrival'] ?? null;
        $this->assertNotNull($validation);
        $this->assertNotNull($validation['document_id']);
        $document = $this->documentService->find((int) $validation['document_id']);
        $this->assertSame(\Modules\Rental\Document\DocumentType::INVENTORY, $document?->type);
        $this->assertNotNull($document->sentAt);
        $this->assertSame([['booking_id' => $booking->id, 'label' => "État des lieux d'entrée"]], $this->documentEmails);

        // Frozen: the renter holds this PDF.
        $this->assertFalse($this->saveLine($booking, $chairs, 'arrival', '40')['success']);

        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('État des lieux de sortie', $body);
        $this->assertStringContainsString("À l'entrée : 38", $body);
        $this->assertStringContainsString('data-inventory-copy="38"', $body);
        $this->assertStringContainsString('ouvrir le PDF', $body);

        // Twice is refused, and nothing is sent twice.
        $this->validateInventoryPhase($booking, 'arrival');
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertCount(1, $this->documentEmails);
    }

    /** The departure's PDF lists the incidents: none is added after it. */
    public function testIncidentsCloseOnceTheDepartureIsValidated(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();
        $this->validateInventoryPhase($booking, 'arrival');
        $this->assertStringContainsString('action="/mes-locations/incident"', $this->inventoryPage($booking));

        $this->validateInventoryPhase($booking, 'departure');

        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('Les deux états des lieux sont validés', $body);
        $this->assertStringNotContainsString('action="/mes-locations/incident"', $body);
        $this->assertStringNotContainsString('data-inventory-line', $body);
        $this->assertCount(2, $this->documentEmails);
    }

    /** An arrival ticked by hand opens the departure without a reference to take. */
    public function testAnArrivalTickedByHandOpensTheDeparture(): void
    {
        $this->loginAsManager();
        [$booking, $chairs] = $this->bookingWithAnInventory();
        $this->confirm($booking);
        $this->markStep($booking, 'arrival_inventory');

        $body = $this->inventoryPage($booking);

        $this->assertStringContainsString('État des lieux de sortie', $body);
        $this->assertStringContainsString("coché à la main", $body);
        $this->assertTrue($this->saveLine($booking, $chairs, 'departure', '40')['success']);

        // Once the departure is validated, only ONE inventory was: the
        // arrival was ticked, never validated, and the page says so.
        $this->validateInventoryPhase($booking, 'departure');
        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('État des lieux validé', $body);
        $this->assertStringNotContainsString('Les deux états des lieux sont validés', $body);
    }

    /**
     * A validated departure freezes the arrival with it: unticking the
     * hand-ticked arrival afterwards does not reopen an arrival form whose
     * every save would be refused.
     */
    public function testUntickingTheArrivalAfterTheDepartureReopensNothing(): void
    {
        $this->loginAsManager();
        [$booking] = $this->bookingWithAnInventory();
        $this->confirm($booking);
        $this->markStep($booking, 'arrival_inventory');
        $this->validateInventoryPhase($booking, 'departure');
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);

        $this->markStep($booking, 'arrival_inventory', false);

        $body = $this->inventoryPage($booking);
        $this->assertStringNotContainsString('etat-des-lieux/signer?phase=arrival', $body);
        $this->assertStringNotContainsString('data-inventory-line', $body);
        $this->assertStringContainsString('État des lieux validé', $body);
    }

    /**
     * An arrival ticked by hand was never read through the page: its meter
     * readings are offered beside the departure's until the departure is
     * validated, or the consumption could never be billed. Only then.
     */
    public function testAnArrivalTickedByHandKeepsItsMeterReadingsReachable(): void
    {
        $this->loginAsManager();
        $this->stayService->addMeter(
            $this->assetId, 'Électricité', \Modules\Rental\Stay\MeterKind::ELECTRICITY, 'kWh', null
        );
        [$booking] = $this->bookingWithAnInventory();
        $this->confirm($booking);

        // Before the tick: the arrival's own page, its own readings only.
        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('aria-label="Relevé entrée — Électricité"', $body);
        $this->assertStringNotContainsString('aria-label="Relevé sortie — Électricité"', $body);

        $this->markStep($booking, 'arrival_inventory');

        $body = $this->inventoryPage($booking);
        $this->assertStringContainsString('aria-label="Relevé entrée — Électricité"', $body);
        $this->assertStringContainsString('aria-label="Relevé sortie — Électricité"', $body);
    }

    // ── « Facture » (#708, IT-18) ───────────────────────────────────────

    private function invoicePage(RentalBooking $booking): string
    {
        $response = $this->filePage(BookingPage::INVOICE, 'local-saint-georges', $booking->id);
        $this->assertSame(200, $response->getStatusCode());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    public function testAnOverpaidRentIsNeverLabelledAsTheSecurityDeposit(): void
    {
        // 500 € in on 467,50 € rent + 150 € deposit: the deposit was never
        // received, so the 32,50 € owed back are not « la caution ».
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 500.00);

        $body = $this->invoicePage($booking);

        $this->assertMatchesRegularExpression('/Solde à rembourser\s*<\/td>\s*<td[^>]*>\s*32,50/u', $body);
        $this->assertStringNotContainsString('dont la caution', $body);
        $this->assertStringNotContainsString('Caution à restituer', $body);
    }

    /**
     * @param list<int|string> $params
     */
    private function runStatement(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param list<int|string> $params
     */
    private function fetchScalar(string $sql, array $params): mixed
    {
        return $this->runStatement($sql, $params)->fetchColumn();
    }

    /** A transfer carrying the booking's communication, as a bank import brings it. */
    private function payOnReceivable(RentalBooking $booking, float $amount): void
    {
        $communication = (string) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['communication'];
        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->financeAccountId,
            FinanceTestHelper::createScoutYear($this->pdo, 'Exercice des locations', '2030-09-01', '2031-08-31'),
            null,
            '2030-10-01',
            'Virement ' . $communication,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    public function testTheSettlementShowsTheSecurityDepositReturnOnceWithinTheRefund(): void
    {
        // 467,50 € rental and a 150 € deposit, all paid on the booking's one
        // receivable: 150 € is owed back, and the screen must say 150 €
        // once — never a balance and a deposit return that add up to 300 €.
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 617.50);

        $body = $this->invoicePage($booking);

        $this->assertStringContainsString('Solde à rembourser', $body);
        $this->assertStringNotContainsString('Caution à restituer', $body);
        $this->assertMatchesRegularExpression('/Solde à rembourser\s*<\/td>\s*<td[^>]*>\s*150,00/u', $body);
        $this->assertMatchesRegularExpression('/dont la caution à rendre\s*:?\s*150,00/u', $body);
    }

    // ── « Finances » (chantier #927, §2.4 and §2.6) ─────────────────────

    private function financesPage(RentalBooking $booking): string
    {
        $response = $this->filePage(BookingPage::FINANCES, 'local-saint-georges', $booking->id);
        $this->assertSame(200, $response->getStatusCode());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    /** A debit of the unit's account carrying no communication — a refund made by hand at the bank. */
    private function debitWithoutCommunication(float $amount, string $date = '2030-10-05'): int
    {
        return (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->financeAccountId,
            FinanceTestHelper::createScoutYear($this->pdo, 'Exercice ' . bin2hex(random_bytes(3)), '2030-09-01', '2031-08-31'),
            null,
            $date,
            'Virement européen',
            -$amount,
            null,
            null,
            'import',
            null,
            'J. LAMBERT'
        );
    }

    private function cancel(RentalBooking $booking): void
    {
        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'cancelled',
        ]);
        \Core\Http\FlashMessage::get();
    }

    private function reconcileRefundPost(RentalBooking $booking, int $transactionId): Response
    {
        return $this->post('/mes-locations/remboursement-rapprocher', 'reconcileRefund', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'finances',
            'transaction_id' => (string) $transactionId,
        ]);
    }

    public function testTheFinancesPageReadsTheSummaryTheMovementsThenHowToPay(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $communication = (string) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['communication'];

        $body = $this->financesPage($booking);

        $summary = strpos($body, '>Résumé<');
        $movements = strpos($body, '>Mouvements du compte<');
        $instructions = strpos($body, '>Informations de paiement<');
        $this->assertNotFalse($summary);
        $this->assertNotFalse($movements);
        $this->assertNotFalse($instructions);
        $this->assertTrue($summary < $movements && $movements < $instructions);

        // The summary: 467,50 € + 150,00 €, 250,00 € in, 367,50 € to go.
        $this->assertStringContainsString('367,50 € restant dû', $body);
        $this->assertMatchesRegularExpression('/Montant attendu\s*<\/dt>\s*<dd[^>]*>\s*<strong>617,50/u', $body);
        // The movement, this booking's share, matched by its communication.
        $this->assertStringContainsString('+250,00 €', $body);
        $this->assertStringContainsString('Automatique', $body);
        // How to pay: the account, the one communication, the amount, the QR.
        $this->assertStringContainsString('BE68 5390 0754 7034', $body);
        $this->assertStringContainsString('Unité Test', $body);
        $this->assertStringContainsString($communication, $body);
        $this->assertStringContainsString('<strong>367,50 €</strong>', $body);
        $this->assertStringContainsString('src="data:image/png;base64,', $body);
        $this->assertStringContainsString('Agrandir le QR code', $body);
        $this->assertStringContainsString('id="qr-paiement"', $body);
        $this->assertStringNotContainsString('À rembourser au locataire', $body);
        // No price is edited outside the « Prix » box, and no hand-typed
        // return of the deposit while Finances follows the money.
        $this->assertStringNotContainsString('action="/mes-locations/caution"', $body);
    }

    public function testAnAccountWithoutAnIbanShowsNoQrAndSaysWhy(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750);
        $this->pdo->prepare('UPDATE finance_accounts SET iban = NULL, iban_blind_index = NULL WHERE id = ?')
            ->execute([$this->financeAccountId]);

        $body = $this->financesPage($booking);

        $this->assertStringContainsString('data-finance-no-iban', $body);
        $this->assertStringNotContainsString('data:image/png;base64,', $body);
        $this->assertStringNotContainsString('Agrandir le QR code', $body);
    }

    public function testBeforeTheFirstContractThereIsNoCommunicationToShowYet(): void
    {
        $this->loginAsManager();
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: true,
            financeAccountId: $this->financeAccountId,
            financeCategoryId: $this->financeCategoryId
        ));
        $booking = $this->createBooking();

        $body = $this->financesPage($booking);

        $this->assertStringContainsString('data-finance-no-receivable', $body);
        $this->assertStringContainsString('Aucun mouvement rapproché', $body);
    }

    public function testAnAbandonedBookingShowsWhatCameInAsToRefund(): void
    {
        // Lot B reads what a receivable at 0 received: the renter's 250 €
        // are owed back, and the page says so — never « 0,00 € reçu ».
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $this->cancel($booking);

        $body = $this->financesPage($booking);

        $this->assertStringContainsString('250,00 € à rembourser', $body);
        $this->assertStringContainsString('À rembourser au locataire : 250,00 €', $body);
        $this->assertMatchesRegularExpression('/Reçu\s*<\/dt>\s*<dd[^>]*>\s*250,00/u', $body);
        $this->assertStringContainsString('Annulée — plus rien n\'est attendu', $body);
        // The receivable at 0 absorbs none of it: the movements say where
        // the 250 € went, as a line of its own.
        $this->assertMatchesRegularExpression('/Reçu au-delà du montant attendu\s*<\/td>\s*<td[^>]*>\s*\+250,00/u', $body);
        $this->assertStringContainsString('Rapprocher le remboursement', $body);
        $this->assertStringNotContainsString('Agrandir le QR code', $body);
    }

    public function testARefundMadeWithoutTheCommunicationIsReconciledFromTheBooking(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $this->cancel($booking);
        $debitId = $this->debitWithoutCommunication(250.00);
        // Another debit of the account, too large to be this refund.
        $this->debitWithoutCommunication(900.00);

        $body = $this->financesPage($booking);
        $this->assertStringContainsString('value="' . $debitId . '"', $body);
        $this->assertStringContainsString('J. LAMBERT', $body);
        $this->assertSame(1, substr_count($body, 'name="transaction_id"'));
        $this->assertStringContainsString('Aucun e-mail n\'est envoyé', $body);

        $response = $this->reconcileRefundPost($booking, $debitId);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/finances', (string) $response->getHeaders()['Location']);
        $this->assertSame('Remboursement rapproché.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $after = $this->financesPage($booking);
        $this->assertStringNotContainsString('à rembourser', $after);
        $this->assertMatchesRegularExpression('/Remboursé\s*<\/dt>\s*<dd[^>]*>\s*250,00/u', $after);
        $this->assertStringContainsString('-250,00 €', $after);
        $this->assertStringContainsString('Manuelle', $after);
        // In the booking's history as a payment, never as « Prix ».
        $this->assertSame(1, (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM entity_changes WHERE entity_type = ? AND entity_id = ? AND field_key = ?',
            [\Modules\Rental\Audit\BookingAudit::ENTITY_TYPE, $booking->id, \Modules\Rental\Audit\BookingAudit::PAYMENT_RECORDED]
        ));
        $this->assertSame('Paiement', \Modules\Rental\Audit\BookingAudit::FIELD_LABELS['payment_recorded']);
        $this->assertSame(0, (int) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['overpaid_cents']);
    }

    public function testADebitThatIsNoLongerACandidateIsRefusedWithFinancesOwnReason(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $this->cancel($booking);
        $tooLarge = $this->debitWithoutCommunication(900.00);

        $this->reconcileRefundPost($booking, $tooLarge);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(25000, (int) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['overpaid_cents']);
    }

    public function testReconcilingARefundIsItsManagersAlone(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $this->cancel($booking);
        $debitId = $this->debitWithoutCommunication(250.00);
        $overpaid = fn(): int => (int) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['overpaid_cents'];

        // Anonymous: refused, nothing written.
        AuthSession::logout();
        $this->assertContains($this->reconcileRefundPost($booking, $debitId)->getStatusCode(), [302, 401, 403]);
        $this->assertSame(25000, $overpaid());

        // An identified member who manages nothing: a 404, nothing written.
        AuthSession::login(1, 'nobody@test.be', 'identified');
        $this->assertSame(404, $this->reconcileRefundPost($booking, $debitId)->getStatusCode());
        $this->assertSame(25000, $overpaid());

        // The asset's manager, at the route's role_min: allowed.
        AuthSession::login(1, 'manager@test.be', 'identified');
        $this->assertSame(302, $this->reconcileRefundPost($booking, $debitId)->getStatusCode());
        $this->assertSame(0, $overpaid());
    }

    // ── « Rapprocher un virement » (#980, chantier §2.6) ────────────────

    /** A credit of the unit's account carrying no communication — an insurer paying a damage. */
    private function creditWithoutCommunication(float $amount, string $counterparty = 'ETHIAS SA'): int
    {
        return (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->financeAccountId,
            FinanceTestHelper::createScoutYear($this->pdo, 'Exercice ' . bin2hex(random_bytes(3)), '2030-09-01', '2031-08-31'),
            null,
            '2030-10-05',
            'Sinistre 2030/118',
            $amount,
            null,
            null,
            'import',
            null,
            $counterparty
        );
    }

    /** A manager whose account carries a name, the way « rapproché à la main par … » reads it. */
    private function loginAsNamedManager(): void
    {
        $this->addManager($this->assetId, 'manager@test.be');
        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $this->encryption);
        $account = $accounts->create('manager@test.be');
        $accounts->updateProfile($account->id, 'Marie', 'Dubois');
        AuthSession::login($account->id, 'manager@test.be', 'identified');
    }

    /** @param array<string, string> $body */
    private function transferPost(string $path, string $action, RentalBooking $booking, array $body): Response
    {
        return $this->post($path, $action, $body + [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'finances',
        ]);
    }

    /** @return array<string, mixed> */
    private function searchTransfer(RentalBooking $booking, string $amount, string $payer = ''): array
    {
        $response = $this->postAsync('/mes-locations/virement-chercher', 'searchTransfer', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'finances',
            'amount' => $amount,
            'payer_name' => $payer,
        ]);
        $this->assertSame(200, $response->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true);

        return $data;
    }

    private function remainingCents(RentalBooking $booking): int
    {
        return (int) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['remaining_cents'];
    }

    public function testTheFinancesPageOffersToReconcileATransferWithoutListingTheAccount(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->creditWithoutCommunication(617.50);

        $body = $this->financesPage($booking);

        $this->assertStringContainsString('data-bs-target="#rapprocher-virement"', $body);
        $this->assertStringContainsString('id="rapprocher-virement"', $body);
        $this->assertStringContainsString('action="/mes-locations/virement-chercher"', $body);
        $this->assertStringContainsString('name="amount"', $body);
        $this->assertStringContainsString('name="payer_name"', $body);
        $this->assertStringContainsString('rental-transfer-reconcile.js', $body);
        // No statement status is wired here: the dialog says nothing is known.
        $this->assertStringContainsString('Aucun extrait importé depuis le', $body);
        // The account's unreconciled credits are never on the page itself.
        $this->assertStringNotContainsString('ETHIAS', $body);
        $this->assertStringNotContainsString('action="/mes-locations/virement-rapprocher"', $body);
    }

    public function testABookingWithoutAReceivableOffersNoTransferToReconcile(): void
    {
        $this->loginAsManager();
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: true,
            financeAccountId: $this->financeAccountId,
            financeCategoryId: $this->financeCategoryId
        ));

        $body = $this->financesPage($this->createBooking());

        $this->assertStringNotContainsString('Rapprocher un virement', $body);
        $this->assertStringNotContainsString('id="rapprocher-virement"', $body);
    }

    public function testTheSearchAnswersTheCandidatesOfThatExactAmountAsJson(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $insurer = $this->creditWithoutCommunication(617.50);
        $this->creditWithoutCommunication(617.51);
        $this->creditWithoutCommunication(617.50, 'J. LAMBERT');

        $data = $this->searchTransfer($booking, '617,50', 'ethias');

        $this->assertTrue($data['success']);
        $html = (string) preg_replace('/\s+/', ' ', (string) $data['html']);
        $this->assertSame(1, substr_count($html, 'data-transfer-candidate>'));
        $this->assertStringContainsString('value="' . $insurer . '"', $html);
        $this->assertStringContainsString('name="amount_cents" value="61750"', $html);
        $this->assertStringContainsString('Sinistre 2030/118', $html);
        $this->assertStringNotContainsString('J. LAMBERT', $html);
        $this->assertStringContainsString(
            'data-confirm="Rapprocher ce virement de 617,50 € (ETHIAS SA, 05/10/2030) de LOC-A2B3C4 ?',
            $html
        );
        $this->assertStringContainsString('Aucun e-mail n&#039;est envoyé.', $html);
        // The journal: ids and a count, never the name or the amount.
        $context = (string) $this->fetchScalar(
            'SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1',
            ['rental_transfer_searched']
        );
        $this->assertSame(['booking_id' => $booking->id, 'candidates' => 1], json_decode($context, true));
    }

    public function testASearchWithNothingFoundSaysSoAndAMissingAmountIsRefused(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);

        $empty = $this->searchTransfer($booking, '1000');
        $this->assertTrue($empty['success']);
        $this->assertStringContainsString('data-transfer-candidates-empty', (string) $empty['html']);
        $this->assertStringContainsString('Aucun extrait importé depuis le', (string) $empty['html']);

        $refused = $this->searchTransfer($booking, '');
        $this->assertFalse($refused['success']);
        $this->assertStringContainsString('Indiquez le montant reçu', (string) $refused['message']);
        $this->assertArrayNotHasKey('html', $refused);
    }

    public function testTheSearchIsNeverAnsweredToAPlainFormPost(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->creditWithoutCommunication(617.50);

        $response = $this->transferPost('/mes-locations/virement-chercher', 'searchTransfer', $booking, [
            'amount' => '617,50',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('ETHIAS', (string) $response->getBody());
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(0, (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM event_log WHERE event_type = ?',
            ['rental_transfer_searched']
        ));
    }

    public function testATransferReconciledFromTheBookingPaysItAndCanBeDetached(): void
    {
        $this->loginAsNamedManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $insurer = $this->creditWithoutCommunication(367.50);

        $response = $this->transferPost('/mes-locations/virement-rapprocher', 'reconcileTransfer', $booking, [
            'transaction_id' => (string) $insurer,
            'amount_cents' => '36750',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/finances', (string) $response->getHeaders()['Location']);
        $this->assertSame('Virement rapproché.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame(0, $this->remainingCents($booking));
        $page = $this->financesPage($booking);
        $this->assertStringContainsString('Rapproché à la main par Marie Dubois, le', $page);
        $this->assertStringContainsString('action="/mes-locations/virement-detacher"', $page);
        $this->assertStringContainsString(
            'data-confirm="Détacher ce virement de 367,50 € (ETHIAS SA, 05/10/2030) de LOC-A2B3C4 ?',
            $page
        );
        // The automatic payment carries no « Détacher ».
        $this->assertSame(1, substr_count($page, 'action="/mes-locations/virement-detacher"'));

        $this->transferPost('/mes-locations/virement-detacher', 'detachTransfer', $booking, [
            'transaction_id' => (string) $insurer,
        ]);

        $this->assertSame('Virement détaché.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame(36750, $this->remainingCents($booking));
        $this->assertStringNotContainsString('Rapproché à la main', $this->financesPage($booking));
    }

    public function testATransferIsAttachedWholeEvenBeyondWhatIsDue(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 617.50);
        $insurer = $this->creditWithoutCommunication(1000.00);

        $this->transferPost('/mes-locations/virement-rapprocher', 'reconcileTransfer', $booking, [
            'transaction_id' => (string) $insurer,
            'amount_cents' => '100000',
        ]);

        $this->assertSame('Virement rapproché.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame(100000, (int) $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($this->assetId)
        )['overpaid_cents']);
    }

    public function testATransferOfAnotherAmountIsRefusedWithFinancesOwnReason(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $insurer = $this->creditWithoutCommunication(367.50);

        $this->transferPost('/mes-locations/virement-rapprocher', 'reconcileTransfer', $booking, [
            'transaction_id' => (string) $insurer,
            'amount_cents' => '36700',
        ]);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(61750, $this->remainingCents($booking));
    }

    public function testATransferASettlementValidatedSinceCountedIsNotDetached(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $insurer = $this->creditWithoutCommunication(617.50);
        $this->transferPost('/mes-locations/virement-rapprocher', 'reconcileTransfer', $booking, [
            'transaction_id' => (string) $insurer,
            'amount_cents' => '61750',
        ]);
        \Core\Http\FlashMessage::get();
        $this->runStatement(
            'UPDATE finance_receivable_allocations SET created_at = ? WHERE transaction_id = ?',
            ['2026-01-05 09:00:00', $insurer]
        );
        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);
        $settlement = $this->stayService->recordSettlement($fresh, $this->assetId, null, [], null);
        $this->stayService->validateSettlement($fresh, $settlement->id, null);

        $this->assertStringNotContainsString('action="/mes-locations/virement-detacher"', $this->financesPage($booking));

        $this->transferPost('/mes-locations/virement-detacher', 'detachTransfer', $booking, [
            'transaction_id' => (string) $insurer,
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('tient compte de ce virement', (string) ($flash['message'] ?? ''));
        $this->assertSame(1, (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM finance_receivable_allocations WHERE transaction_id = ? AND amount_cents > 0',
            [$insurer]
        ));
    }

    public function testReconcilingADetachingAndSearchingATransferAreItsManagersAlone(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $insurer = $this->creditWithoutCommunication(617.50);
        $reconcile = fn(): Response => $this->transferPost(
            '/mes-locations/virement-rapprocher',
            'reconcileTransfer',
            $booking,
            ['transaction_id' => (string) $insurer, 'amount_cents' => '61750']
        );
        $detach = fn(): Response => $this->transferPost(
            '/mes-locations/virement-detacher',
            'detachTransfer',
            $booking,
            ['transaction_id' => (string) $insurer]
        );
        $search = fn(): Response => $this->postAsync('/mes-locations/virement-chercher', 'searchTransfer', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'amount' => '617,50',
        ]);

        // Anonymous: refused, nothing found, nothing written.
        AuthSession::logout();
        foreach ([$search, $reconcile, $detach] as $call) {
            $this->assertContains($call()->getStatusCode(), [302, 401, 403]);
        }
        $this->assertSame(61750, $this->remainingCents($booking));

        // An identified member who manages nothing: a 404 every time.
        AuthSession::login(1, 'nobody@test.be', 'identified');
        foreach ([$search, $reconcile, $detach] as $call) {
            $this->assertSame(404, $call()->getStatusCode());
        }
        $this->assertSame(61750, $this->remainingCents($booking));
        $this->assertSame(0, (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM event_log WHERE event_type = ?',
            ['rental_transfer_searched']
        ));

        // A manager of another asset, through the booking of this one: a 404.
        $this->addManager($this->otherAssetId, 'other@test.be');
        AuthSession::login(1, 'other@test.be', 'identified');
        $this->assertSame(404, $this->post('/mes-locations/virement-rapprocher', 'reconcileTransfer', [
            'asset_id' => (string) $this->otherAssetId,
            'booking_id' => (string) $booking->id,
            'transaction_id' => (string) $insurer,
            'amount_cents' => '61750',
        ])->getStatusCode());
        $this->assertSame(61750, $this->remainingCents($booking));

        // The asset's manager, at the routes' role_min: allowed.
        AuthSession::login(1, 'manager@test.be', 'identified');
        $this->assertSame(200, $search()->getStatusCode());
        $this->assertSame(302, $reconcile()->getStatusCode());
        $this->assertSame(0, $this->remainingCents($booking));
        $this->assertSame(302, $detach()->getStatusCode());
        $this->assertSame(61750, $this->remainingCents($booking));
    }

    public function testATransferPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $insurer = $this->creditWithoutCommunication(617.50);

        $this->transferPost('/mes-locations/virement-rapprocher', 'reconcileTransfer', $booking, [
            '_csrf_token' => 'forged',
            'transaction_id' => (string) $insurer,
            'amount_cents' => '61750',
        ]);

        $this->assertSame(61750, $this->remainingCents($booking));
    }

    public function testWithoutFinancesThePageSaysSoAndKeepsTheManualReturn(): void
    {
        $this->loginAsManager();
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: false,
            securityDepositEnabled: true,
            securityDepositAmountCents: 15000
        ));
        $booking = $this->createBooking();

        $body = $this->financesPage($booking);

        $this->assertStringContainsString('data-finance-off', $body);
        $this->assertStringContainsString('sans communication structurée', $body);
        $this->assertStringContainsString('action="/mes-locations/caution"', $body);
        $this->assertStringNotContainsString('>Mouvements du compte<', $body);

        $this->post('/mes-locations/caution', 'recordSecurityDeposit', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'returned' => '150,00',
            'returned_at' => '2027-07-10',
        ]);
        $this->assertSame('Restitution de la caution enregistrée.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertStringNotContainsString('action="/mes-locations/caution"', $this->financesPage($booking));
    }

    public function testATrackedBookingWithPaymentsSwitchedOffKeepsTheFinancesCards(): void
    {
        // Payments switched off on the asset after the receivable was
        // raised: Finance still reconciles its communication, so the page
        // stays Finance's — and asks the renter for nothing any more.
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->payOnReceivable($booking, 250.00);
        $this->paymentService->saveSettings($this->assetId, new \Modules\Rental\Payment\PaymentSettings(
            enabled: false,
            financeAccountId: $this->financeAccountId,
            securityDepositEnabled: true,
            securityDepositAmountCents: 15000,
            financeCategoryId: $this->financeCategoryId
        ));

        $body = $this->financesPage($booking);

        $this->assertStringContainsString('>Résumé<', $body);
        $this->assertStringContainsString('>Mouvements du compte<', $body);
        $this->assertStringContainsString('+250,00 €', $body);
        $this->assertStringContainsString('data-finance-payments-off', $body);
        $this->assertStringNotContainsString('data-finance-off', $body);
        $this->assertStringNotContainsString('action="/mes-locations/caution"', $body);
        $this->assertStringNotContainsString('data:image/png;base64,', $body);
    }

    public function testAnAssetWithoutADepositAsksForTheBalanceAlone(): void
    {
        $this->loginAsManager();
        $body = $this->financesPage($this->bookingOwedFor(46750));

        $this->assertStringContainsString('<span class="small text-body-secondary d-block">solde</span>', $body);
        $this->assertStringNotContainsString('caution comprise', $body);
    }

    public function testABalanceCarryingTheDepositSaysSo(): void
    {
        $this->loginAsManager();
        $body = $this->financesPage($this->bookingOwedFor(46750, 15000));

        $this->assertStringContainsString(
            '<span class="small text-body-secondary d-block">solde, caution comprise</span>',
            $body
        );
    }

    public function testTheManualReturnIsRefusedWhileFinancesFollowsTheBooking(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750, 15000);

        $this->post('/mes-locations/caution', 'recordSecurityDeposit', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'returned' => '150,00',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('virement', (string) ($flash['message'] ?? ''));
    }

    public function testTheBillingDetailsFoldOnceTheNameAndTheAddressAreKnown(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        // Nothing yet: open, as the task it is.
        $empty = $this->invoicePage($booking);
        $this->assertStringContainsString('class="collapse show" id="coordonnees-facturation-body"', $empty);
        $this->assertStringNotContainsString('data-billing-summary', $empty);

        // A name alone keeps it open.
        $this->operationsService->saveBillingIdentity($booking->id, ['name' => 'Patro Sainte-Anne']);
        $this->assertStringContainsString(
            'class="collapse show" id="coordonnees-facturation-body"',
            $this->invoicePage($booking)
        );

        // Name and address, no VAT number: folded, with both as its summary.
        $this->operationsService->saveBillingIdentity($booking->id, [
            'name' => 'Patro Sainte-Anne',
            'address' => "Rue de l'Église 12, 5000 Namur",
        ]);
        $folded = $this->invoicePage($booking);
        $this->assertStringContainsString('class="collapse" id="coordonnees-facturation-body"', $folded);
        $this->assertStringContainsString('aria-expanded="false" aria-controls="coordonnees-facturation-body"', $folded);
        $this->assertMatchesRegularExpression(
            '/data-billing-summary>\s*Patro Sainte-Anne · Rue de l(&#039;|\')Église 12, 5000 Namur/u',
            $folded
        );
        $this->assertMatchesRegularExpression('/data-collapse-label>\s*Modifier\s*</u', $folded);
        $this->assertStringContainsString('collapse-label.js', $folded);
    }

    public function testRemovingAnotherAddressIsAnIconWithItsName(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@patro.example');

        $response = $this->filePage(BookingPage::MAIL, 'local-saint-georges', $booking->id);
        $body = (string) preg_replace('/\s+/', ' ', (string) $response->getBody());

        $this->assertMatchesRegularExpression(
            '#aria-label="Retirer tresorier@patro.example" title="Retirer tresorier@patro.example"> '
            . '<i class="bi bi-x-lg" aria-hidden="true"></i> </button>#',
            $body
        );
    }

    private function generateInvoice(RentalBooking $booking): void
    {
        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'invoice',
            'document_type' => 'invoice',
        ]);
    }

    /** @return list<\Modules\Rental\Document\RentalDocument> */
    private function invoices(RentalBooking $booking): array
    {
        return array_values(array_filter(
            $this->documentService->forBooking($booking->id),
            static fn($document): bool => $document->type === \Modules\Rental\Document\DocumentType::INVOICE
        ));
    }

    /** The billing details, the settlement and the invoice, in that order. */
    public function testTheInvoicePageHoldsTheBillingTheSettlementAndTheInvoice(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = $this->invoicePage($booking);

        $billing = strpos($body, 'action="/mes-locations/facturation"');
        $settlement = strpos($body, 'action="/mes-locations/decompte"');
        $invoice = strpos($body, 'Générer la facture');
        $this->assertNotFalse($billing);
        $this->assertNotFalse($settlement);
        $this->assertNotFalse($invoice);
        // The settlement first, then the billing details, then the invoice
        // (chantier #927, §2.4).
        $this->assertTrue($settlement < $billing && $billing < $invoice);
        $this->assertStringContainsString('ne modifie jamais le prix convenu', $body);
    }

    /** No « Séjour » shortcut on the dashboard, and no link to it anywhere. */
    public function testNothingLeadsToTheStayPageAnyMore(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();

        foreach (BookingPage::cases() as $page) {
            $response = $this->filePage($page, 'local-saint-georges', $booking->id);
            if ($response->getStatusCode() === 200) {
                $this->assertStringNotContainsString('/sejour', (string) $response->getBody(), $page->value);
            }
        }
        $manifest = (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json');
        $this->assertStringNotContainsString('/sejour"', $manifest);
    }

    /**
     * The invoice waits for the departure inventory — on screen, and at
     * the server, whatever a crafted POST says.
     */
    public function testTheInvoiceWaitsForTheDepartureInventory(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->stayService->snapshotInventory($booking, $this->assetId);

        $body = $this->invoicePage($booking);
        $this->assertStringContainsString("La facture se génère une fois l'état des lieux de sortie complété.", $body);
        $this->assertStringContainsString("/etat-des-lieux\">Faire l'état des lieux</a>", $body);
        $this->assertStringNotContainsString('Générer la facture', $body);

        $this->generateInvoice($booking);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->invoices($booking));

        // Validated: the invoice can be made.
        $this->stayService->recordInventoryValidation($booking, \Modules\Rental\Stay\ReadingPhase::ARRIVAL, new \DateTimeImmutable(), null);
        $this->stayService->recordInventoryValidation($booking, \Modules\Rental\Stay\ReadingPhase::DEPARTURE, new \DateTimeImmutable(), null);
        $this->assertStringContainsString('Générer la facture', $this->invoicePage($booking));
        $this->generateInvoice($booking);
        $this->assertCount(1, $this->invoices($booking));
    }

    public function testADepartureTickedByHandLetsTheInvoiceBeMade(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->confirm($booking);
        $this->markStep($booking, 'departure_inventory');

        $this->assertStringContainsString('Générer la facture', $this->invoicePage($booking));
        $this->generateInvoice($booking);
        $this->assertCount(1, $this->invoices($booking));
    }

    /** An asset with no inventory page waits for nothing. */
    public function testAnAssetWithoutInventoryWaitsForNothing(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $this->assertStringNotContainsString('data-invoice-waits', $this->invoicePage($booking));
        $this->generateInvoice($booking);
        $this->assertCount(1, $this->invoices($booking));
    }

    /** « Envoyer la facture » names the address before it goes, and sends it. */
    public function testTheInvoiceIsSentFromItsPageAfterAQuestionNamingTheAddress(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->generateInvoice($booking);

        $body = $this->invoicePage($booking);
        $this->assertStringContainsString('Envoyer la facture à ' . $booking->renterEmail . ' ?', $body);
        $this->assertStringContainsString('Envoyer la facture </button>', $body);

        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'invoice',
            'document_id' => (string) $this->invoices($booking)[0]->id,
        ]);

        $this->assertNotNull($this->documentService->find($this->invoices($booking)[0]->id)?->sentAt);
        $this->assertStringContainsString('Renvoyer la facture </button>', $this->invoicePage($booking));
        // Documents still lists it.
        $this->assertStringContainsString(
            'Facture',
            (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody()
        );
    }

    /**
     * The incidents decided on « État des lieux » show on the settlement
     * with their amount, read only, and a link to change the decision.
     */
    public function testDecidedIncidentsAreCarriedReadOnlyWithALinkBack(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $incident = $this->stayService->reportIncident($booking, 'Vitre cassée', 5000, [], 1);
        $this->stayService->decideIncident($booking, $incident, \Modules\Rental\Stay\IncidentDecision::CHARGE, 4500, 1);
        $this->stayService->reportIncident($booking, 'Tache au mur', 1000, [], 1);

        $body = $this->invoicePage($booking);

        $this->assertStringContainsString('Vitre cassée', $body);
        $this->assertStringNotContainsString('Tache au mur', $body);
        $this->assertStringContainsString('45,00', $body);
        $this->assertStringContainsString('/etat-des-lieux">Changer une décision', $body);
        $this->assertStringNotContainsString('action="/mes-locations/incident-decision"', $body);
    }

    /**
     * A damage under assessment holds the settlement (#980): « Facture »
     * shows a sentence and a link to the incident instead of « Valider »,
     * and a POST that skips the page is refused with the same reason.
     */
    public function testADamageUnderAssessmentHoldsTheSettlementOnThePageAndOnThePost(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $incident = $this->stayService->reportIncident($booking, 'Porte fendue', 30000, [], 1);
        $settlement = $this->stayService->recordSettlement($booking, $this->assetId, 28, [], 1);

        $body = $this->invoicePage($booking);

        $this->assertStringContainsString('data-settlement-waits="assessment"', $body);
        $this->assertStringContainsString('/etat-des-lieux#incident-' . $incident . '"', $body);
        $this->assertStringNotContainsString('action="/mes-locations/decompte-valider"', $body);

        $this->post('/mes-locations/decompte-valider', 'validateSettlement', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'settlement_id' => (string) $settlement->id,
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(
            \Modules\Rental\Service\RentalStayService::SETTLEMENT_WAITS_FOR_ASSESSMENT,
            $flash['message'] ?? null
        );
        $this->assertFalse($this->stayService->latestSettlement($booking->id)?->isValidated);
    }

    public function testAVersionOutdatedByADecisionSaysToRecordANewOne(): void
    {
        $this->loginAsManager();
        $this->stayService->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $incident = $this->stayService->reportIncident($booking, 'Porte fendue', 30000, [], 1);
        $this->stayService->recordSettlement($booking, $this->assetId, 28, [], 1);
        $this->stayService->decideIncident(
            $booking,
            $incident,
            \Modules\Rental\Stay\IncidentDecision::CHARGE,
            null,
            1
        );

        $body = $this->invoicePage($booking);

        $this->assertStringContainsString('data-settlement-waits="outdated"', $body);
        $this->assertStringNotContainsString('data-settlement-waits="assessment"', $body);
        $this->assertStringNotContainsString('action="/mes-locations/decompte-valider"', $body);
    }

    public function testASettlementLandsBackOnTheInvoicePage(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->post('/mes-locations/decompte', 'recordSettlement', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'invoice',
            'final_persons' => '28',
        ]);

        $this->assertStringEndsWith('/facture', (string) $response->getHeaders()['Location']);
        $this->assertNotNull($this->stayService->latestSettlement($booking->id));
    }

    private function templatesPage(): string
    {
        return (string) $this->get(
            '/mes-locations/{slug}/gabarits',
            '/mes-locations/local-saint-georges/gabarits',
            'templates'
        )->getBody();
    }

    private function templateDocumentResponse(string $document): Response
    {
        return $this->get(
            '/mes-locations/{slug}/gabarits/{document}',
            '/mes-locations/local-saint-georges/gabarits/' . $document,
            'templateDocument'
        );
    }

    private function templateDocumentPage(string $document): string
    {
        $response = $this->templateDocumentResponse($document);
        $this->assertSame(200, $response->getStatusCode(), $document);

        return (string) $response->getBody();
    }

    private function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $this->assertNotFalse($start, $from);
        $end = strpos($haystack, $to, $start + strlen($from));

        return substr($haystack, $start, $end === false ? null : $end - $start);
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
        $foreign = $this->createBooking($this->otherAssetId, 'LOC-W9Y8X7');
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
     * The calendar's period list and the compliance register were the
     * last two hand-written control stacks in the managed space: labels
     * whose classes did not match the rest of the site, and help texts no
     * screen reader ever announced because nothing pointed at them. The
     * `form_field` partial renders label, control, help text and required
     * marker as one unit with `aria-describedby` wired (design.md §7.9).
     */
    public function testAPeriodsReasonFieldIsRenderedThroughTheSharedField(): void
    {
        $this->loginAsManager();
        $blockId = $this->blockRepository->create($this->assetId, $this->futureDay(10), $this->futureDay(12), null, null);

        $html = (string) $this->get(
            '/mes-locations/{slug}/calendrier',
            '/mes-locations/local-saint-georges/calendrier',
            'calendar'
        )->getBody();

        $this->assertMatchesRegularExpression(
            '#<label class="form-label small" for="block-reason-' . $blockId . '">\s*Motif#',
            $html
        );
    }

    public function testTheComplianceFormWiresItsHelpTextToItsField(): void
    {
        $this->loginAsManager();

        $html = (string) $this->complianceFormPage('local-saint-georges')->getBody();

        $this->assertStringContainsString('aria-describedby="compliance-document-help"', $html);
        $this->assertStringContainsString('<div class="form-text" id="compliance-document-help">', $html);
        // The datalist the intitulé field reads still reaches it.
        $this->assertStringContainsString('list="compliance-suggestions"', $html);
        // An entry without a due date triggers nothing, and the field says so.
        $this->assertStringContainsString('ne déclenche aucun rappel', $html);
    }

    // ── The compliance register on the shared list (#708, IT-09) ───────

    public function testTheRegisterIsAListSortedByDueDateWithoutDragHandles(): void
    {
        $this->loginAsManager();
        $service = $this->complianceService();
        $service->add($this->assetId, 'Sans date', null, null);
        $service->add($this->assetId, 'Chaudière', '2027-09-01', null);
        $service->add($this->assetId, 'Extincteurs', '2027-03-01', 'Société Feu Sûr');

        $html = (string) $this->get(
            '/mes-locations/{slug}/conformite',
            '/mes-locations/local-saint-georges/conformite',
            'compliance'
        )->getBody();

        $this->assertStringContainsString('id="compliance-list"', $html);
        $this->assertStringContainsString('data-sortable="false"', $html);
        $this->assertStringNotContainsString('list-editor-drag-handle', $html);
        $this->assertStringContainsString('href="/mes-locations/local-saint-georges/conformite/nouvelle"', $html);
        $this->assertStringNotContainsString('action="/mes-locations/conformite-ajouter"', $html);
        $first = strpos($html, 'Extincteurs');
        $this->assertNotFalse($first);
        $this->assertGreaterThan($first, strpos($html, 'Chaudière'));
        $this->assertGreaterThan(strpos($html, 'Chaudière'), strpos($html, 'Sans date'));
        $this->assertStringContainsString('Supprimer « Extincteurs » du registre ?', $html);
    }

    public function testAnEntryIsAddedFromItsOwnPageAndTheManagerIsSentBackToTheList(): void
    {
        $this->loginAsManager();

        $response = $this->post('/mes-locations/{slug}/conformite/nouvelle', 'complianceSave', [
            'label' => 'Extincteurs',
            'expires_on' => '2027-03-01',
            'remark' => 'Société Feu Sûr',
        ], ['slug' => 'local-saint-georges']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/mes-locations/local-saint-georges/conformite', $response->getHeaders()['Location'] ?? null);
        $items = $this->complianceService()->forAsset($this->assetId);
        $this->assertCount(1, $items);
        $this->assertSame('2027-03-01', $items[0]->expiresOn);
    }

    public function testARefusedEntryComesBackWithWhatWasTyped(): void
    {
        $this->loginAsManager();

        $response = $this->post('/mes-locations/{slug}/conformite/nouvelle', 'complianceSave', [
            'label' => '',
            'remark' => 'Ma remarque',
        ], ['slug' => 'local-saint-georges']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Ma remarque', (string) $response->getBody());
    }

    public function testAnEntryIsChangedFromItsOwnPageKeepingItsDocument(): void
    {
        $this->loginAsManager();
        $id = $this->complianceService()->add($this->assetId, 'Extincteurs', '2027-03-01', null);

        $page = (string) $this->complianceFormPage('local-saint-georges', $id)->getBody();
        $this->assertStringContainsString('value="Extincteurs"', $page);

        $this->post('/mes-locations/{slug}/conformite/{id}/modifier', 'complianceSave', [
            'label' => 'Extincteurs (rez)',
            'expires_on' => '',
            'remark' => '',
        ], ['slug' => 'local-saint-georges', 'id' => (string) $id]);

        $entry = $this->complianceService()->find($this->assetId, $id);
        $this->assertSame('Extincteurs (rez)', $entry?->label);
        $this->assertNull($entry?->expiresOn);
    }

    public function testAnEntryIsDeletedThroughTheListBin(): void
    {
        $this->loginAsManager();
        $id = $this->complianceService()->add($this->assetId, 'Extincteurs', '2027-03-01', null);

        $response = $this->postJsonTo('/mes-locations/{slug}/conformite/supprimer', 'complianceDelete', 'local-saint-georges', [
            'id' => $id,
        ]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], $this->complianceService()->forAsset($this->assetId));
    }

    public function testTheEntryPagesAreClosedToAnotherAssetsEntriesAndToNonManagers(): void
    {
        $foreign = $this->complianceService()->add($this->otherAssetId, 'Chaudière', null, null);

        AuthSession::login(1, 'nobody@test.be', 'identified');
        $this->assertSame(404, $this->complianceFormPage('local-saint-georges')->getStatusCode());

        $this->loginAsManager();
        $this->assertSame(404, $this->complianceFormPage('local-saint-georges', $foreign)->getStatusCode());
        $this->assertSame(404, $this->post('/mes-locations/{slug}/conformite/{id}/modifier', 'complianceSave', [
            'label' => 'Pris',
        ], ['slug' => 'local-saint-georges', 'id' => (string) $foreign])->getStatusCode());
        $this->postJsonTo('/mes-locations/{slug}/conformite/supprimer', 'complianceDelete', 'local-saint-georges', ['id' => $foreign]);
        $this->assertNotNull($this->complianceService()->find($this->otherAssetId, $foreign));
    }

    private function complianceFormPage(string $slug, ?int $id = null): Response
    {
        return $id === null
            ? $this->get('/mes-locations/{slug}/conformite/nouvelle', '/mes-locations/' . $slug . '/conformite/nouvelle', 'complianceForm')
            : $this->get(
                '/mes-locations/{slug}/conformite/{id}/modifier',
                '/mes-locations/' . $slug . '/conformite/' . $id . '/modifier',
                'complianceForm'
            );
    }

    /**
     * A list editor's own fetch: a JSON body, the token inside it.
     *
     * @param array<string, mixed> $body
     */
    private function postJsonTo(string $routePath, string $action, string $slug, array $body): Response
    {
        $body['_csrf_token'] ??= CsrfGuard::generateToken();
        $router = new Router();
        $router->addRoute('POST', $routePath, RentalManagementController::class, $action, 'identified');

        return $this->dispatch(
            $router,
            new \Tests\RequestWithInput(
                'POST',
                str_replace('{slug}', $slug, $routePath),
                [],
                [],
                [],
                [],
                (string) json_encode($body)
            )
        );
    }

    // ── The « Rappels » section of the settings page (IT-07, §6.29) ──────

    private function settingsPage(): string
    {
        return (string) $this->get(
            '/mes-locations/{slug}/reglages',
            '/mes-locations/local-saint-georges/reglages',
            'settings'
        )->getBody();
    }

    public function testTheSettingsPageListsEveryReminderWithItsOwnField(): void
    {
        $this->loginAsManager();

        $html = $this->settingsPage();

        $this->assertStringContainsString('id="rappels"', $html);
        foreach (\Modules\Rental\Reminder\ReminderKind::cases() as $kind) {
            $this->assertStringContainsString(
                'name="days_' . $kind->value . '"',
                $html,
                $kind->value . ' must have a field of its own.'
            );
            $this->assertStringContainsString('name="active_' . $kind->value . '"', $html, $kind->value);
        }
    }

    /**
     * The field is **empty** and the unit's number is written underneath,
     * never pre-filled into it: pre-filling is how a screen freezes a
     * default the day somebody presses « Enregistrer » without changing
     * anything, and the number then stops following the unit's setting.
     */
    public function testAnInheritedDelayLeavesTheFieldEmptyAndShowsTheDefaultUnderIt(): void
    {
        $this->loginAsManager();

        $html = $this->settingsPage();

        $this->assertMatchesRegularExpression(
            '#name="days_unanswered_request"\s+value=""#',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#id="reminder-default-unanswered_request">\s*défaut : 3 jours#u',
            $html
        );
    }

    public function testAnAssetsOwnDelayIsShownInTheFieldItself(): void
    {
        (new RentalAssetReminderRepository($this->pdo))->save(
            $this->assetId,
            \Modules\Rental\Reminder\ReminderKind::UNANSWERED_REQUEST,
            10,
            true
        );
        $this->loginAsManager();

        $this->assertMatchesRegularExpression(
            '#name="days_unanswered_request"\s+value="10"#',
            $this->settingsPage()
        );
    }

    /**
     * An unchecked box posts nothing at all, so the checkbox's state is the
     * only record of a reminder being off — it has to survive the round
     * trip through the page.
     */
    public function testAReminderSwitchedOffComesBackUnchecked(): void
    {
        (new RentalAssetReminderRepository($this->pdo))->save(
            $this->assetId,
            \Modules\Rental\Reminder\ReminderKind::ARRIVAL_INVENTORY,
            null,
            false
        );
        $this->loginAsManager();

        $html = $this->settingsPage();
        $checkbox = substr($html, (int) strpos($html, 'name="active_arrival_inventory"'), 120);

        $this->assertStringNotContainsString('checked', $checkbox);
        // …while one nobody touched is still on: a reminder that has to be
        // switched on is one a unit discovers it never had.
        $other = substr($html, (int) strpos($html, 'name="active_departure_inventory"'), 120);
        $this->assertStringContainsString('checked', $other);
    }

    // ── The landlord (issue #497) ────────────────────────────────────────

    /**
     * Said on the Documents page BEFORE anything is generated: a contract
     * sent with « — » where the landlord's address belongs is locked once
     * it has gone.
     */
    public function testTheDocumentsPageWarnsWhileTheLandlordHasNoAddress(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $body = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringContainsString('data-landlord-address-missing', $body);
        $this->assertStringContainsString('href="/mes-locations/local-saint-georges/reglages#bailleur"', $body);
        $this->assertStringContainsString("l'adresse postale de l'unité", $body, 'names the setting that is empty');
    }

    public function testTheWarningGoesOnceTheLandlordHasAnAddress(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $this->assetRepository->saveLandlord($this->assetId, 'ASBL Les Amis du Local', 'Place du Parc 3, 1300 Wavre', null);

        $body = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();

        $this->assertStringNotContainsString('data-landlord-address-missing', $body);
    }

    public function testGeneratingWithoutALandlordAddressSaysSo(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();

        $this->post('/mes-locations/document-generer', 'generateDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_type' => 'contract',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString("l'adresse du bailleur est vide", (string) ($flash['message'] ?? ''));
        $this->assertCount(1, $this->documentService->forBooking($booking->id), 'generated all the same');
    }

    /** The card says who the landlord is today, and where that comes from. */
    public function testTheSettingsPageShowsTheLandlordInForceAndItsOrigin(): void
    {
        $this->loginAsManager();
        $this->assetRepository->saveLandlord($this->assetId, 'ASBL Les Amis du Local', "Place du Parc 3\n1300 Wavre", null);

        $html = $this->settingsPage();

        $this->assertStringContainsString('id="bailleur"', $html);
        $this->assertStringContainsString('ASBL Les Amis du Local', $html);
        $this->assertStringContainsString('Place du Parc 3<br />', $html);
        $this->assertStringContainsString('Le bailleur propre à ce bien.', $html);
        $this->assertStringContainsString('action="/mes-locations/local-saint-georges/reglages/bailleur"', $html);
    }

    // ── Modifications through a replacement contract (#927, lot D) ──────

    /** An image a renter could send as their signed copy, filed like an upload. */
    private function signedCopyFile(): int
    {
        $image = imagecreatetruecolor(600, 800);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        @mkdir($this->storagePath . '/rental/documents', 0755, true);
        $relative = 'rental/documents/' . bin2hex(random_bytes(8)) . '.jpg';
        file_put_contents($this->storagePath . '/' . $relative, $bytes);

        return $this->fileRepository->create(
            $relative,
            'copie.jpg',
            'image/jpeg',
            strlen($bytes),
            'identified',
            'rental',
            null
        );
    }

    /**
     * A booking whose first contract went out, with a project moving it
     * from 1–4 July to 10–14 July.
     *
     * @return array{
     *     0: RentalBooking,
     *     1: \Modules\Rental\Document\RentalDocument,
     *     2: \Modules\Rental\Booking\BookingModification
     * }
     */
    private function bookingWithAProject(): array
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $contract = $this->sendContractOf($booking);

        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => '2027-07-10',
            'departure' => '2027-07-14',
            'persons' => '20',
        ]);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $project = $this->modificationRepository->findActiveFor($booking->id);
        $this->assertNotNull($project);

        return [$booking, $contract, $project];
    }

    private function sendProject(RentalBooking $booking, int $modificationId): void
    {
        $this->post('/mes-locations/modification-envoyer', 'sendModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'modification_id' => (string) $modificationId,
        ]);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
    }

    private function modificationPage(RentalBooking $booking, string $suffix): Response
    {
        $routePath = '/mes-locations/{slug}/reservations/{id}/modifications/'
            . ($suffix === 'nouvelle' ? 'nouvelle' : '{modificationId}');

        return $this->get(
            $routePath,
            '/mes-locations/local-saint-georges/reservations/' . $booking->id . '/modifications/' . $suffix,
            $suffix === 'nouvelle' ? 'newModification' : 'modification'
        );
    }

    /**
     * After the first send, « Enregistrer » writes a project and nothing
     * else: the booking keeps its dates until the countersignature, and
     * the project's new dates are held against everyone else.
     */
    public function testAfterTheFirstSendAChangeIsAProjectThatHoldsItsDates(): void
    {
        [$booking, , $project] = $this->bookingWithAProject();

        $this->assertSame(\Modules\Rental\Booking\ModificationStatus::DRAFT, $project->status);
        $current = $this->bookingRepository->findById($booking->id);
        $this->assertSame('2027-07-01', $current?->arrivalDate);
        $this->assertSame('2027-07-04', $current?->departureDate);

        $occupancies = $this->modificationRepository->findOccupancies(
            $this->assetId,
            new \DateTimeImmutable('2027-07-01'),
            new \DateTimeImmutable('2027-07-31'),
            new \DateTimeImmutable()
        );
        $this->assertCount(1, $occupancies);

        $page = (string) $this->modificationPage($booking, (string) $project->id)->getBody();
        $this->assertStringContainsString('Générer et envoyer le contrat de remplacement', $page);
        $this->assertStringContainsString('Annuler la modification', $page);
    }

    public function testASecondProjectIsRefusedWhileOneIsInProgress(): void
    {
        [$booking] = $this->bookingWithAProject();

        $response = $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => '2027-07-20',
            'departure' => '2027-07-22',
            'persons' => '20',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(
            \Modules\Rental\Service\RentalModificationService::ALREADY_IN_PROGRESS,
            $flash['message'] ?? null
        );
        $this->assertStringEndsWith('/modifications/nouvelle', (string) $response->getHeaders()['Location']);
        $this->assertCount(1, $this->modificationRepository->findForBooking($booking->id));
    }

    public function testAProjectOnDatesSomebodyElseHoldsIsRefused(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->sendContractOf($booking);
        $this->blockRepository->create($this->assetId, '2027-07-11', '2027-07-12', 'Travaux', null);

        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => '2027-07-10',
            'departure' => '2027-07-14',
            'persons' => '20',
        ]);

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertNull($this->modificationRepository->findActiveFor($booking->id));
    }

    /**
     * New dates overlapping the booking's own are no conflict: the
     * booking does not stand in its own way.
     */
    public function testAProjectMayOverlapTheBookingsOwnDates(): void
    {
        $this->loginAsManager();
        $this->setContractTemplate();
        $booking = $this->createBooking();
        $this->sendContractOf($booking);

        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => '2027-07-02',
            'departure' => '2027-07-05',
            'persons' => '20',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        $this->assertNotNull($this->modificationRepository->findActiveFor($booking->id));
    }

    /**
     * The whole path: the replacement contract goes out, the renter's copy
     * of it comes back, and the countersignature applies the project — once,
     * however many times it is pressed — and retires the contract before.
     */
    public function testTheCountersignatureOfTheReplacementCopyAppliesTheProjectOnce(): void
    {
        [$booking, $contract, $project] = $this->bookingWithAProject();
        $this->sendProject($booking, $project->id);
        $sent = $this->modificationRepository->findById($project->id);
        $this->assertSame(\Modules\Rental\Booking\ModificationStatus::SENT, $sent?->status);
        $this->assertNotNull($sent->contractDocumentId);
        // Mailed: the replacement contract carries its send.
        $this->assertNotNull($this->documentService->find((int) $sent->contractDocumentId)?->sentAt);

        // Documents shows the banner and the contract to come.
        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('data-modification-banner', $documents);
        $this->assertStringContainsString('data-replacement-contract', $documents);

        $copy = $this->signedContractService->receiveCopy(
            $this->bookingRepository->findById($booking->id) ?? $booking,
            $this->asset(),
            $this->signedCopyFile()
        );
        $this->assertSame($project->id, $copy->modificationId);
        $this->assertSame($sent->contractDocumentId, $copy->contractDocumentId);
        // The copy answers the project: the booking's own line still waits.
        $this->assertTrue($copy->inActiveModification);

        // « Contresigner » on Documents opens the core's dialog (issue
        // #926): a signature for this contract while none is kept…
        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('data-bs-target="#countersign-' . $copy->id . '">Contresigner</button>', $documents);
        $dialog = self::dialog($documents, 'countersign-' . $copy->id);
        $this->assertStringContainsString('data-signature-mode="new" data-async="off"', $dialog);
        $this->assertStringContainsString('name="booking_page" value="documents"', $dialog);
        $this->assertStringContainsString(
            "La modification s'applique aussitôt : la réservation passe au 10/07/2027 → 14/07/2027",
            html_entity_decode($dialog, ENT_QUOTES)
        );
        $this->assertStringContainsString('Le statut de confirmation ne change pas.', $dialog);
        $this->assertStringNotContainsString('checked', $dialog);
        $this->assertStringNotContainsString('data-confirm', $dialog);
        $this->assertStringContainsString('/assets/js/signature-dialog.js', $documents);

        // … the kept one, said and never carried, once there is one.
        $this->keepSignatureFor(1);
        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $dialog = self::dialog($documents, 'countersign-' . $copy->id);
        $this->assertStringContainsString('Ce document sera signé avec la signature enregistrée dans votre compte.', $dialog);
        $this->assertStringContainsString(
            'href="/account/signature?retour=' . rawurlencode('/mes-locations/local-saint-georges/reservations/' . $booking->id . '/documents') . '">Gérer ma signature</a>',
            $dialog
        );
        $this->assertStringNotContainsString('/account/signature/image', $documents);

        $countersign = fn() => $this->post('/mes-locations/contrat-contresigner', 'countersignContract', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'document_id' => (string) $copy->id,
            'signature_source' => 'stored',
        ]);
        $this->notified = [];
        $countersign();
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null, (string) ($flash['message'] ?? ''));
        // The signer is told which document it was (issue #926, Q1).
        $this->assertCount(1, $this->notified);
        $this->assertStringStartsWith('Contrat de remplacement signé — ', $this->notified[0]['payload']['title']);
        $this->assertStringContainsString('le contrat de remplacement de ' . $booking->reference, $this->notified[0]['payload']['body']);
        $this->assertStringEndsWith('/documents', $this->notified[0]['payload']['url']);

        $applied = $this->bookingRepository->findById($booking->id);
        $this->assertSame('2027-07-10', $applied?->arrivalDate);
        $this->assertSame('2027-07-14', $applied?->departureDate);
        $this->assertSame(
            \Modules\Rental\Booking\ModificationStatus::APPLIED,
            $this->modificationRepository->findById($project->id)?->status
        );
        $this->assertNull($this->modificationRepository->findActiveFor($booking->id));
        $this->assertTrue($this->documentService->find($contract->id)?->isSuperseded());
        $final = $this->signedContractService->finalContract($booking->id);
        $this->assertNotNull($final);
        $this->assertSame($project->id, $final->modificationId);

        // A second press changes nothing.
        $countersign();
        \Core\Http\FlashMessage::get();
        $this->assertCount(1, array_filter(
            $this->modificationRepository->findForBooking($booking->id),
            fn($m) => $m->status === \Modules\Rental\Booking\ModificationStatus::APPLIED
        ));
        $this->assertSame('2027-07-10', $this->bookingRepository->findById($booking->id)?->arrivalDate);

        // Read back from the history, closed.
        $page = $this->modificationPage($booking, (string) $project->id);
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringNotContainsString('Annuler la modification', (string) $page->getBody());
        $this->assertStringContainsString('Appliquée', (string) $this->filePage(
            BookingPage::CHANGES,
            'local-saint-georges',
            $booking->id
        )->getBody());
    }

    /** Before any send, the form applies at once and the page says so. */
    public function testBeforeTheFirstSendTheNewModificationPageChangesTheBookingDirectly(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $page = $this->modificationPage($booking, 'nouvelle');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('Nouvelles valeurs', (string) $page->getBody());
        $this->assertStringContainsString('action="/mes-locations/modification"', (string) $page->getBody());
    }

    /**
     * Before its replacement contract goes out, a project is cancelled
     * without a word to the renter; after, they are told.
     */
    public function testCancellingAProjectTellsTheRenterOnlyOnceItsContractWentOut(): void
    {
        [$booking, , $draft] = $this->bookingWithAProject();
        $cancel = fn(int $id) => $this->post('/mes-locations/modification-annuler', 'cancelModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'modification_id' => (string) $id,
        ]);

        $cancel($draft->id);
        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->cancellationEmails);
        $this->assertSame(
            \Modules\Rental\Booking\ModificationStatus::CANCELLED,
            $this->modificationRepository->findById($draft->id)?->status
        );

        $this->post('/mes-locations/modification', 'saveModification', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'arrival' => '2027-07-10',
            'departure' => '2027-07-14',
            'persons' => '20',
        ]);
        \Core\Http\FlashMessage::get();
        $second = $this->modificationRepository->findActiveFor($booking->id);
        $this->assertNotNull($second);
        $this->sendProject($booking, $second->id);

        $cancel($second->id);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertStringContainsString('prévenu par e-mail', $flash['message'] ?? '');
        $this->assertSame([$booking->id], $this->cancellationEmails);
        // The booking is untouched and its replacement contract retired.
        $this->assertSame('2027-07-01', $this->bookingRepository->findById($booking->id)?->arrivalDate);
        $sentContract = $this->documentService->find(
            (int) $this->modificationRepository->findById($second->id)?->contractDocumentId
        );
        $this->assertTrue($sentContract?->isSuperseded());
        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Modification annulée le', $documents);
    }

    /**
     * « À traiter » reads the project too (§8.54ter): one never sent waits
     * on the unit, and so does a copy of its replacement contract to
     * countersign; one waiting for the renter's copy does not.
     */
    public function testAProjectWaitingOnTheUnitPutsTheBookingOnTheList(): void
    {
        [$booking, , $project] = $this->bookingWithAProject();

        $overview = (string) $this->overview('local-saint-georges')->getBody();
        $this->assertStringContainsString('À faire : générer puis envoyer le contrat de remplacement', $overview);

        $this->sendProject($booking, $project->id);
        $this->assertStringNotContainsString(
            'contrat de remplacement',
            (string) $this->overview('local-saint-georges')->getBody()
        );

        $this->signedContractService->receiveCopy(
            $this->bookingRepository->findById($booking->id) ?? $booking,
            $this->asset(),
            $this->signedCopyFile()
        );
        $this->assertStringContainsString(
            'À faire : vérifier la copie, puis contresigner',
            (string) $this->overview('local-saint-georges')->getBody()
        );
    }

    /**
     * A replacement contract whose e-mail failed is generated and frozen,
     * but never reached the renter. No copy may land on the contract it
     * replaces — that would hold the booking's dates firmly, and a refusal
     * of it would lengthen the booking's hold. The renter is told to wait
     * for it; a copy the unit files answers the replacement.
     */
    public function testACopyNeverAnswersTheContractAReplacementIsReplacing(): void
    {
        [$booking, $contract, $project] = $this->bookingWithAProject();
        $this->sendProject($booking, $project->id);
        $replacementId = (int) $this->modificationRepository->findById($project->id)?->contractDocumentId;
        // As if the e-mail had failed: frozen, never received.
        $this->pdo->prepare('UPDATE rental_documents SET sent_at = NULL WHERE id = ?')->execute([$replacementId]);
        $fresh = $this->bookingRepository->findById($booking->id) ?? $booking;

        try {
            $this->signedContractService->receiveCopy($fresh, $this->asset(), $this->signedCopyFile());
            $this->fail('the renter filed a copy of a contract they never received');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertSame(
                \Modules\Rental\Service\RentalSignedContractService::REPLACEMENT_NOT_RECEIVED,
                $e->getMessage()
            );
        }

        $copyId = $this->signedContractService->fileCopyFromManager(
            $fresh,
            $this->asset(),
            $this->signedCopyFile(),
            null,
            false,
            null
        );
        $copy = $this->documentService->find($copyId);
        $this->assertSame($project->id, $copy?->modificationId);
        $this->assertSame($replacementId, $copy->contractDocumentId);
        $this->assertNotSame($contract->id, $copy->contractDocumentId);
        // The booking's own line is untouched: no firm hold from it.
        $this->assertNull($this->bookingRepository->findById($booking->id)?->signedCopyReceivedAt);
    }

    /** @return array<string, array{string, string}> */
    public static function modificationRoutes(): array
    {
        return [
            'the page' => ['GET', '/mes-locations/{slug}/reservations/{id}/modifications/{modificationId}'],
            'nouvelle' => ['GET', '/mes-locations/{slug}/reservations/{id}/modifications/nouvelle'],
            'save' => ['POST', '/mes-locations/modification'],
            'send' => ['POST', '/mes-locations/modification-envoyer'],
            'cancel' => ['POST', '/mes-locations/modification-annuler'],
        ];
    }

    /**
     * An anonymous visitor is refused every modification route, with the
     * floor module.json declares — not the `identified` the helpers above
     * register for themselves — so a route declared `public` by mistake
     * fails here. Nothing is written for them.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modificationRoutes')]
    public function testAnAnonymousVisitorIsRefusedEveryModificationRoute(string $method, string $path): void
    {
        [$booking, , $project] = $this->bookingWithAProject();
        AuthSession::logout();
        $_SESSION = [];

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );
        $declared = null;
        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === $path && $route['method'] === $method) {
                $declared = $route;
            }
        }
        $this->assertNotNull($declared, "module.json declares no {$method} {$path}");
        $this->assertNotSame('public', $declared['role_min']);

        $router = new Router();
        $router->addRoute(
            $method,
            $path,
            RentalManagementController::class,
            (string) $declared['action'],
            (string) $declared['role_min']
        );
        $requestPath = str_replace(
            ['{slug}', '{id}', '{modificationId}'],
            ['local-saint-georges', (string) $booking->id, (string) $project->id],
            $path
        );
        $body = [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'modification_id' => (string) $project->id,
            'arrival' => '2027-07-20',
            'departure' => '2027-07-22',
            '_csrf_token' => CsrfGuard::generateToken(),
        ];
        $_POST = $method === 'POST' ? $body : [];
        $response = $this->dispatch(
            $router,
            new Request($method, $requestPath, [], $method === 'POST' ? $body : [], [], [])
        );

        $this->assertContains($response->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString(
            '/mes-locations/local-saint-georges/reservations',
            (string) ($response->getHeaders()['Location'] ?? '')
        );
        $this->assertSame(
            \Modules\Rental\Booking\ModificationStatus::DRAFT,
            $this->modificationRepository->findById($project->id)?->status
        );
        $this->assertSame('2027-07-10', $this->modificationRepository->findById($project->id)?->arrivalDate);
    }

    public function testTheModificationPagesAreAManagersOnly(): void
    {
        [$booking, , $project] = $this->bookingWithAProject();
        $this->assertSame(200, $this->modificationPage($booking, (string) $project->id)->getStatusCode());
        // With a project in progress, « nouvelle » leads to it.
        $this->assertSame(302, $this->modificationPage($booking, 'nouvelle')->getStatusCode());
        $this->assertSame(404, $this->modificationPage($booking, '999')->getStatusCode());

        AuthSession::logout();
        AuthSession::login(2, 'nobody@test.be', 'identified');
        $this->assertSame(404, $this->modificationPage($booking, (string) $project->id)->getStatusCode());
        $this->assertSame(404, $this->modificationPage($booking, 'nouvelle')->getStatusCode());
    }

    // ── A decision after validation, the supplementary invoice (#980, lot F)

    /**
     * A booking whose settlement was validated — well before today — and
     * whose invoice, generated from it, went out.
     */
    private function invoicedAfterASettlement(): RentalBooking
    {
        $this->loginAsManager();
        $booking = $this->createBooking();
        $settlement = $this->stayService->recordSettlement($booking, $this->assetId, null, [], 1);
        $this->stayService->validateSettlement($booking, $settlement->id, 1);
        $this->pdo->prepare('UPDATE rental_settlements SET validated_at = ? WHERE id = ?')
            ->execute(['2026-01-05 10:00:00', $settlement->id]);
        $this->generateInvoice($booking);
        $this->post('/mes-locations/document-envoyer', 'sendDocument', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'invoice',
            'document_id' => (string) $this->invoices($booking)[0]->id,
        ]);

        return $booking;
    }

    /** @return list<\Modules\Rental\Document\RentalDocument> */
    private function supplements(RentalBooking $booking): array
    {
        return array_values(array_filter(
            $this->documentService->forBooking($booking->id),
            static fn($document): bool
                => $document->type === \Modules\Rental\Document\DocumentType::SUPPLEMENTARY_INVOICE
        ));
    }

    private function postOnBooking(RentalBooking $booking, string $path, string $action, array $body = []): Response
    {
        return $this->post($path, $action, $body + [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'booking_page' => 'invoice',
        ]);
    }

    /**
     * Chantier decision 2.8 end to end on « Facture »: the banner, a version
     * carrying the decision, its validation, the supplementary invoice of
     * only the difference — generated once, sent after a question naming
     * the address, listed on « Documents » — and the invoice untouched.
     */
    public function testADecisionAfterValidationLeadsToASupplementaryInvoiceOfTheDifference(): void
    {
        $booking = $this->invoicedAfterASettlement();
        $invoice = $this->invoices($booking)[0];
        $this->assertNotNull($invoice->settlementId);
        $this->assertStringNotContainsString('data-settlement-revision', $this->invoicePage($booking));

        $incident = $this->stayService->reportIncident($booking, 'Vitre cassée', 5000, [], 1);
        $this->stayService->decideIncident($booking, $incident, \Modules\Rental\Stay\IncidentDecision::CHARGE, null, 1);

        $body = $this->invoicePage($booking);
        $this->assertStringContainsString('data-settlement-revision', $body);
        $this->assertStringContainsString('Le décompte validé (v1) ne tient pas compte de la décision du', $body);
        $this->assertStringContainsString('action="/mes-locations/decompte-actualiser"', $body);
        $this->assertStringContainsString('Aucun e-mail n&#039;est envoyé.', $body);
        $this->assertStringNotContainsString('data-supplement-offer', $body);

        $this->postOnBooking($booking, '/mes-locations/decompte-actualiser', 'recordSettlementFromValidated');
        $revised = $this->stayService->latestSettlement($booking->id);
        $this->assertNotNull($revised);
        $this->assertSame(2, $revised->version);
        $body = $this->invoicePage($booking);
        $this->assertStringNotContainsString('data-settlement-revision', $body);
        $this->assertStringContainsString('name="settlement_id" value="' . $revised->id . '"', $body);

        $this->postOnBooking($booking, '/mes-locations/decompte-valider', 'validateSettlement', [
            'settlement_id' => (string) $revised->id,
        ]);
        $this->assertStringContainsString(
            'générez la facture complémentaire',
            (string) (\Core\Http\FlashMessage::get()['message'] ?? '')
        );

        $body = $this->invoicePage($booking);
        $this->assertStringContainsString('data-supplement-offer', $body);
        $this->assertStringContainsString('50,00 € de plus', $body);
        $this->assertStringContainsString('Générer la facture complémentaire</button>', $body);

        $this->postOnBooking($booking, '/mes-locations/facture-complementaire', 'generateSupplementaryInvoice');
        $supplements = $this->supplements($booking);
        $this->assertCount(1, $supplements);
        $this->assertSame($revised->id, $supplements[0]->settlementId);
        $this->assertSame($invoice->id, $supplements[0]->supplementsDocumentId);
        $this->assertSame(
            ($invoice->billedKeptCents ?? 0) + 5000,
            $supplements[0]->billedKeptCents
        );

        $body = $this->invoicePage($booking);
        $this->assertStringNotContainsString('data-supplement-offer', $body);
        $this->assertStringContainsString(
            'Envoyer la facture complémentaire à ' . $booking->renterEmail . ' ?',
            $body
        );

        // Twice is once.
        $this->postOnBooking($booking, '/mes-locations/facture-complementaire', 'generateSupplementaryInvoice');
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertCount(1, $this->supplements($booking));

        $this->documentEmails = [];
        $this->postOnBooking($booking, '/mes-locations/document-envoyer', 'sendDocument', [
            'document_id' => (string) $supplements[0]->id,
        ]);
        $this->assertSame(
            [['booking_id' => $booking->id, 'label' => 'Facture complémentaire v1']],
            $this->documentEmails
        );
        $this->assertNotNull($this->documentService->find($supplements[0]->id)?->sentAt);

        // The invoice the renter holds is as it was sent.
        $after = $this->documentService->find($invoice->id);
        $this->assertEquals($invoice->sentAt, $after?->sentAt);
        $this->assertSame($invoice->billedKeptCents, $after?->billedKeptCents);
        $this->assertSame($invoice->settlementId, $after?->settlementId);

        $documents = (string) $this->filePage(BookingPage::DOCUMENTS, 'local-saint-georges', $booking->id)->getBody();
        $this->assertStringContainsString('Facture complémentaire v1', $documents);
        $this->assertStringContainsString('au locataire, à ' . $booking->renterEmail . ' ?', $documents);
    }

    /** A supplement left unsent is not sent once a later settlement holds. */
    public function testAnUnsentSupplementOfAnOlderSettlementIsNotSent(): void
    {
        $booking = $this->invoicedAfterASettlement();
        $incident = $this->stayService->reportIncident($booking, 'Vitre cassée', 5000, [], 1);
        $this->stayService->decideIncident($booking, $incident, \Modules\Rental\Stay\IncidentDecision::CHARGE, null, 1);
        $v2 = $this->stayService->recordSettlementFromValidated($booking, $this->assetId, 1);
        $this->stayService->validateSettlement($booking, $v2->id, 1);
        $this->postOnBooking($booking, '/mes-locations/facture-complementaire', 'generateSupplementaryInvoice');
        $supplement = $this->supplements($booking)[0];

        $this->pdo->prepare('UPDATE rental_settlements SET validated_at = ? WHERE id = ?')
            ->execute(['2026-01-06 10:00:00', $v2->id]);
        $this->stayService->decideIncident($booking, $incident, \Modules\Rental\Stay\IncidentDecision::CHARGE, 8000, 1);
        $v3 = $this->stayService->recordSettlementFromValidated($booking, $this->assetId, 1);
        $this->stayService->validateSettlement($booking, $v3->id, 1);

        $body = $this->invoicePage($booking);
        $this->assertStringContainsString('data-supplement-outdated', $body);
        // Against the INVOICE still, since the first supplement never left.
        $this->assertStringContainsString('80,00 € de plus', $body);

        $this->documentEmails = [];
        $this->postOnBooking($booking, '/mes-locations/document-envoyer', 'sendDocument', [
            'document_id' => (string) $supplement->id,
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->documentEmails);
        $this->assertNull($this->documentService->find($supplement->id)?->sentAt);
    }

    public function testTheSupplementaryInvoiceHasNoTextToEdit(): void
    {
        $this->loginAsManager();
        $booking = $this->createBooking();

        $response = $this->filePage(BookingPage::INVOICE, 'local-saint-georges', $booking->id);
        $this->assertStringNotContainsString('/document/supplementary_invoice', (string) $response->getBody());

        $this->postOnBooking($booking, '/mes-locations/document-texte', 'saveDocumentText', [
            'document_type' => 'supplementary_invoice',
            'body' => '<p>x</p>',
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);

        $this->postOnBooking($booking, '/mes-locations/document-generer', 'generateDocument', [
            'document_type' => 'supplementary_invoice',
        ]);
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->supplements($booking));
    }

    // ── The guarded closure and the unrecovered one (#980) ───────

    /**
     * A confirmed booking whose damage outgrew the deposit: 467,50 € and a
     * 150 € deposit paid, 800 € of damage charged and the settlement
     * validated — 650 € remain due on the receivable.
     */
    private function bookingWithABalanceDue(): RentalBooking
    {
        $booking = $this->bookingOwedFor(46750, 15000);
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CONFIRMED, new \DateTimeImmutable());
        $this->payOnReceivable($booking, 617.50);
        $fresh = fn(): RentalBooking => $this->bookingRepository->findById($booking->id) ?? $booking;
        $incident = $this->stayService->reportIncident($fresh(), 'Châssis brisé', 80000, [], null);
        $this->stayService->decideIncident(
            $fresh(),
            $incident,
            \Modules\Rental\Stay\IncidentDecision::CHARGE,
            null,
            null
        );
        $settlement = $this->stayService->recordSettlement($fresh(), $this->assetId, null, [], null);
        $this->stayService->validateSettlement($fresh(), $settlement->id, null);

        return $fresh();
    }

    /**
     * @param array<string, string> $body
     */
    private function closeUnrecoveredPost(RentalBooking $booking, array $body = []): Response
    {
        return $this->post('/mes-locations/cloturer-non-recouvre', 'closeUnrecovered', $body + [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'unrecovered_cents' => '65000',
            'reason' => 'Assurance refusée',
        ]);
    }

    public function testClosingWhileMoneyIsDueIsRefusedAndPointsToTheUnrecoveredClosure(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingWithABalanceDue();

        $this->post('/mes-locations/statut', 'changeStatus', [
            'asset_id' => (string) $this->assetId,
            'booking_id' => (string) $booking->id,
            'status' => 'closed',
        ]);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('650,00 € restent dus', $flash['message'] ?? '');
        $this->assertStringContainsString('« Clôturer avec un solde non recouvré »', $flash['message'] ?? '');
        $this->assertSame(BookingStatus::CONFIRMED, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testTheDashboardOffersTheUnrecoveredClosureWithItsDialog(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingWithABalanceDue();

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        // The stay (July 2027) is still ahead: the refusal is not said yet.
        $this->assertStringNotContainsString('data-closure-refusal', $body);
        $this->assertStringContainsString('Clôturer avec un solde non recouvré', $body);
        $this->assertStringContainsString('action="/mes-locations/cloturer-non-recouvre"', $body);
        $this->assertStringContainsString('name="unrecovered_cents" value="65000"', $body);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="reason"[^>]*required/', $body);
        $this->assertStringContainsString("Aucun e-mail n'est envoyé au locataire.", $body);
        $this->assertStringContainsString('650,00', $body);
    }

    public function testOnceTheStayIsOverTheDashboardSaysWhyTheClosureWaits(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingWithABalanceDue();
        $this->pdo->prepare('UPDATE rental_bookings SET arrival_date = ?, departure_date = ? WHERE id = ?')
            ->execute(['2026-01-02', '2026-01-05', $booking->id]);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertMatchesRegularExpression('/data-closure-refusal>\s*650,00\s€ restent dus/u', $body);
    }

    public function testASettledBookingOffersNoUnrecoveredClosure(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingOwedFor(46750);
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CONFIRMED, new \DateTimeImmutable());
        $this->payOnReceivable($booking, 467.50);

        $body = (string) $this->bookingPage('local-saint-georges', $booking->id)->getBody();

        $this->assertStringNotContainsString('data-closure-refusal', $body);
        $this->assertStringNotContainsString('cloturer-non-recouvre', $body);
    }

    public function testAManagerClosesWithAnUnrecoveredBalanceAndNoEmailLeaves(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingWithABalanceDue();

        $response = $this->closeUnrecoveredPost($booking);

        $this->assertSame(302, $response->getStatusCode());
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringContainsString("Aucun e-mail n'a été envoyé au locataire", $flash['message'] ?? '');
        $this->assertSame(BookingStatus::CLOSED, $this->bookingRepository->findById($booking->id)?->status);
        $status = $this->paymentService->statusFor($booking, $this->paymentService->settingsFor($this->assetId));
        $this->assertSame(61750, $status['total_cents']);
        $this->assertSame(0, $status['remaining_cents']);
        $latest = $this->stayService->latestSettlement($booking->id);
        $this->assertTrue($latest?->isValidated);
        $this->assertSame(65000, $latest?->unrecoveredCents);
        // Nothing reached the renter, by any of the mail service's doors.
        $this->assertSame([], $this->renterEmails);
        $this->assertSame([], $this->documentEmails);
        $this->assertSame([], $this->trackingLinkEmails);
        $this->assertSame([], $this->cancellationEmails);
        $this->assertSame([], $this->resends);
    }

    public function testTheUnrecoveredClosureRequiresAReason(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingWithABalanceDue();

        $this->closeUnrecoveredPost($booking, ['reason' => '  ']);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Indiquez pourquoi', $flash['message'] ?? '');
        $this->assertSame(BookingStatus::CONFIRMED, $this->bookingRepository->findById($booking->id)?->status);
    }

    public function testTheUnrecoveredClosureIsItsManagersAlone(): void
    {
        $this->loginAsManager();
        $booking = $this->bookingWithABalanceDue();

        // Anonymous: refused, nothing written.
        AuthSession::logout();
        $this->assertContains($this->closeUnrecoveredPost($booking)->getStatusCode(), [302, 401, 403]);
        $this->assertSame(BookingStatus::CONFIRMED, $this->bookingRepository->findById($booking->id)?->status);

        // An identified member who manages nothing: a 404, nothing written.
        AuthSession::login(1, 'nobody@test.be', 'identified');
        $this->assertSame(404, $this->closeUnrecoveredPost($booking)->getStatusCode());
        $this->assertSame(BookingStatus::CONFIRMED, $this->bookingRepository->findById($booking->id)?->status);

        // The asset's manager, at the route's role_min: allowed.
        AuthSession::login(1, 'manager@test.be', 'identified');
        $this->assertSame(302, $this->closeUnrecoveredPost($booking)->getStatusCode());
        $this->assertSame(BookingStatus::CLOSED, $this->bookingRepository->findById($booking->id)?->status);
    }
}
