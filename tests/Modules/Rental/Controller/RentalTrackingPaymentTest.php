<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceAccountService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Controller\RentalRequestController;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalConditionsVersionRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalConditionsService;
use Modules\Rental\Service\RentalJourneyService;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * « Paiement » on the renter's tracking page (chantier #927, §2.4): from
 * the moment the booking has a receivable, the renter reads who to pay,
 * the IBAN, the one communication, the amount due now with its deadline,
 * and the SEPA QR of that transfer — against the real Finance services,
 * wired as public/index.php wires them.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalTrackingPaymentTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private RentalPaymentService $payments;
    private RentalAssetRepository $assetRepository;
    private int $assetId;
    private int $accountId;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));

        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accountRepository->create(
            'Compte unité',
            'bank',
            null,
            'BE68539007547034',
            'Unité 25 SV — Locations',
            'intendant'
        );
        $this->pdo->exec("UPDATE finance_accounts SET status = 'active'");
        $this->categoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);

        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $this->encryption);
        $this->payments = new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            $bookingAudit,
            $journal,
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository),
            new SepaQrCodeService(),
            new FinanceAccountService($accountRepository)
        );

        $this->assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            null,
            null,
            null,
            true
        );
        $this->payments->saveSettings($this->assetId, new PaymentSettings(
            enabled: true,
            financeAccountId: $this->accountId,
            depositMode: DepositMode::FIXED,
            depositAmountCents: 25000,
            depositDueDays: 15,
            balanceDueDays: 14,
            securityDepositEnabled: true,
            securityDepositAmountCents: 15000,
            financeCategoryId: $this->categoryId
        ));
    }

    private function controller(?RentalPaymentService $payments, ?RentalStayService $stay = null): RentalRequestController
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $connection = Connection::withPdo($this->pdo);
        $editable = new EditableContentService(new EditableContentRepository($this->pdo));
        $bookingService = new RentalBookingService($this->bookingRepository, $journal);
        $pricing = new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal);
        $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [$bookingService]
        );
        $modifications = new RentalModificationRepository($this->pdo);

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/locations');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return new RentalRequestController(
            $twig,
            $this->assetRepository,
            $bookingService,
            $availability,
            $pricing,
            new RentalBookingMailService(
                $this->createStub(\Core\Mail\MailService::class),
                EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'rental'),
                $settingService,
                $journal,
                journey: new RentalJourneyService($this->bookingRepository)
            ),
            new RentalManagerService(
                new RentalAssetManagerRepository($this->pdo),
                new MemberService(
                    new MemberYearRepository($this->pdo),
                    new MemberProfileRepository($connection, $this->encryption)
                ),
                $journal
            ),
            new ScoutYearService($this->pdo),
            $editable,
            new HumanCheckService(
                $this->encryption,
                new HumanCheckRateLimitRepository($this->pdo),
                $settingService,
                $journal
            ),
            $settingService,
            new RentalOperationsService(
                $this->bookingRepository,
                RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
                new RentalBookingCommentRepository($this->pdo, $this->encryption),
                $modifications,
                $availability,
                $pricing,
                $journal
            ),
            new RentalConditionsService(new RentalConditionsVersionRepository($this->pdo), $editable),
            paymentService: $payments,
            stayService: $stay
        );
    }

    /** @return array{id: int, token: string} */
    private function createBooking(): array
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-A2B3C4',
            '2027-03-12',
            '2027-03-15',
            1,
            32,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => 'Patro Sainte-Anne',
                'purpose' => null,
                'comment' => null,
            ],
            new PriceQuote(
                lines: [new PriceLine('Séjour', 1, null, 66800, PriceLine::RULE_BASE)],
                totalCents: 66800,
                nights: 3,
                persons: 32,
                quantity: 1,
                billingUnit: BillingUnit::FLAT_STAY
            ),
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-10 10:00:00')
        );

        return ['id' => $created['id'], 'token' => $created['tracking_token']];
    }

    /** The booking's receivable, raised as the first contract raises it. */
    private function raiseReceivable(int $bookingId): string
    {
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);
        $communication = $this->payments->communicationForContract($booking, $this->payments->settingsFor($this->assetId));
        $this->assertNotNull($communication);

        return $communication;
    }

    private function track(
        array $booking,
        ?RentalPaymentService $payments = null,
        ?RentalStayService $stay = null
    ): string {
        $response = $this->controller($payments ?? $this->payments, $stay)->tracking(
            new Request('GET', '/locations/suivi/' . $booking['id'] . '/' . $booking['token'], [], [], [], []),
            ['id' => (string) $booking['id'], 'token' => $booking['token']]
        );
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    public function testTheRenterSeesHowToPayWithTheQrOnceAReceivableExists(): void
    {
        $booking = $this->createBooking();
        $communication = $this->raiseReceivable($booking['id']);
        $this->pdo->prepare('UPDATE rental_bookings SET deposit_due_date = ? WHERE id = ?')
            ->execute(['2027-01-30', $booking['id']]);

        $body = $this->track($booking);

        $this->assertStringContainsString('id="paiement"', $body);
        $this->assertStringContainsString('Unité 25 SV — Locations', $body);
        $this->assertStringContainsString('BE68 5390 0754 7034', $body);
        $this->assertStringContainsString($communication, $body);
        $this->assertStringContainsString('<strong>250,00 €</strong> avant le 30/01/2027', $body);
        $this->assertStringContainsString('src="data:image/png;base64,', $body);
        $this->assertStringContainsString('aria-label="Agrandir le QR code"', $body);
        $this->assertStringContainsString('id="qr-paiement"', $body);
        $this->assertStringContainsString('payment-qr.js', $body);
        $this->assertStringContainsString('pour l\'acompte, le solde et la caution', $body);
        $this->assertStringContainsString('data-renter-payment-communication', $body);
    }

    /**
     * A damage under assessment on a booking whose deposit was asked but
     * never paid (#980, §2.7): « la caution reste retenue » would be false,
     * so the page says only that a damage is being assessed.
     */
    public function testADepositAskedButNotPaidIsNeverSaidToBeHeld(): void
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $stay = new RentalStayService(
            new RentalStayRepository($this->pdo, $this->encryption),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal),
            new SettlementCalculator(),
            $journal
        );
        $created = $this->createBooking();
        $this->raiseReceivable($created['id']);
        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);
        $incident = $stay->reportIncident($booking, 'Vitre brisée', 12000, [], 1);
        $stay->decideIncident($booking, $incident, IncidentDecision::PENDING, 12000, 1);

        $body = $this->track($created, null, $stay);

        $this->assertStringContainsString('Un dégât constaté pendant votre séjour est en cours d\'évaluation.', $body);
        $this->assertStringNotContainsString('la caution reste retenue', $body);
    }

    public function testThePaymentBlockWaitsForTheReceivable(): void
    {
        $body = $this->track($this->createBooking());

        $this->assertStringNotContainsString('id="paiement"', $body);
        $this->assertStringNotContainsString('data:image/png', $body);
    }

    public function testWithoutFinanceThereIsNoPaymentBlock(): void
    {
        $booking = $this->createBooking();
        $this->raiseReceivable($booking['id']);

        $withoutFinance = new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->assertStringNotContainsString('id="paiement"', $this->track($booking, $withoutFinance));
    }

    public function testAnAbandonedBookingThatWasPaidSaysTheMoneyComesBack(): void
    {
        $booking = $this->createBooking();
        $communication = $this->raiseReceivable($booking['id']);
        (new TransactionRepository($this->pdo, $this->encryption))->create(
            $this->accountId,
            FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31'),
            null,
            '2027-01-20',
            'Virement ' . $communication,
            250.00,
            null,
            null,
            'import',
            null
        );
        $fresh = $this->bookingRepository->findById($booking['id']);
        $this->assertNotNull($fresh);
        $this->payments->abandonAmount($fresh);

        $body = $this->track($booking);

        $this->assertStringContainsString('Vous avez versé 250,00 € de plus que le montant attendu', $body);
        $this->assertStringNotContainsString('data:image/png', $body);
        // Owed money, never told how to pay.
        $this->assertStringNotContainsString('data-renter-payment-communication', $body);
        $this->assertStringNotContainsString('Utilisez toujours la communication', $body);
    }
}
