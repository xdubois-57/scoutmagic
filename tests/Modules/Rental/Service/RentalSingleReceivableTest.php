<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceAccountService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\SettlementCalculator;
use Modules\Rental\Stay\SettlementLine;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The booking's single receivable across its life (issue #927): raised
 * before the first contract, its deposit deadline set by the first send,
 * brought to 0 when the booking is refused, cancelled or expires, and to
 * the amount the final settlement keeps once validated — against the real
 * Finance services, the way the module wires them.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalSingleReceivableTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private TransactionRepository $transactionRepository;
    private RentalPaymentService $payments;
    private RentalOperationsService $operations;
    private RentalStayService $stay;
    private RentalBookingService $bookings;
    private BookingAudit $bookingAudit;
    private JournalService $journal;
    private RentalPricingService $pricingService;
    private int $assetId;
    private int $accountId;
    private int $categoryId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $this->encryption);

        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accountRepository->create(
            'Compte unité',
            'bank',
            null,
            'BE68539007547034',
            'Unité Saint-Georges',
            'intendant'
        );
        $this->pdo->exec("UPDATE finance_accounts SET status = 'active'");
        $this->categoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactionRepository = new TransactionRepository($this->pdo, $this->encryption);

        $this->payments = new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            $this->journal,
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository),
            new SepaQrCodeService(),
            new FinanceAccountService($accountRepository)
        );

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $this->journal
        );
        $this->wire($this->payments);

        $this->assetId = (new RentalAssetRepository($this->pdo, $this->encryption))->create(
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

    /** The services that move the receivable, built around $payments. */
    private function wire(RentalPaymentService $payments): void
    {
        $this->stay = new RentalStayService(
            new RentalStayRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            $this->pricingService,
            new SettlementCalculator(),
            $this->journal,
            $payments
        );
        $this->bookings = new RentalBookingService(
            $this->bookingRepository,
            $this->journal,
            new RentalModificationRepository($this->pdo),
            $this->bookingAudit,
            null,
            $payments
        );
        $this->operations = new RentalOperationsService(
            $this->bookingRepository,
            $this->bookingAudit,
            new RentalBookingCommentRepository($this->pdo, $this->encryption),
            new RentalModificationRepository($this->pdo),
            new RentalAvailabilityService(
                new AvailabilityCalculator(),
                new RentalConstraintsRepository($this->pdo),
                [$this->bookings]
            ),
            $this->pricingService,
            $this->journal,
            $payments,
            $this->stay
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function createBooking(int $rentalCents = 66800): RentalBooking
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
                'phone' => '+32 495 11 22 33',
                'organisation' => 'Patro Sainte-Anne',
                'purpose' => null,
                'comment' => null,
            ],
            new PriceQuote(
                lines: [new PriceLine('Séjour', 1, null, $rentalCents, PriceLine::RULE_BASE)],
                totalCents: $rentalCents,
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

        return $this->reload($created['id']);
    }

    private function reload(int $bookingId): RentalBooking
    {
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function settings(): PaymentSettings
    {
        return $this->payments->settingsFor($this->assetId);
    }

    private function pay(string $communication, float $amount): void
    {
        $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            '2027-03-05',
            'Virement ' . $communication,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    /** @return array<string, mixed> */
    private function paymentStatus(RentalBooking $booking): array
    {
        return $this->payments->statusFor($this->reload($booking->id), $this->settings());
    }

    private function receivableCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables')->fetchColumn();
    }

    /** The communication the first contract prints, raising the receivable. */
    private function firstContract(RentalBooking $booking): string
    {
        $communication = $this->payments->communicationForContract($booking, $this->settings());
        $this->assertNotNull($communication);

        return $communication;
    }

    // ── Before and at the first contract ────────────────────────────────

    public function testTheFirstContractRaisesOneReceivableForTheRentalAndTheSecurityDeposit(): void
    {
        $booking = $this->createBooking();

        $communication = $this->firstContract($booking);

        $status = $this->paymentStatus($booking);
        $this->assertSame(1, $this->receivableCount());
        $this->assertSame(81800, $status['total_cents']);
        $this->assertSame($communication, $status['communication']);
        $this->assertSame(25000, $status['deposit_cents']);
        // Nothing sent yet: no deposit deadline; the balance is due 14 days
        // before the 12 March arrival.
        $this->assertNull($status['deposit_due_date']);
        $this->assertSame('2027-02-26', $status['balance_due_date']);
    }

    public function testADirectPriceChangeBeforeTheFirstSendMovesTheSameReceivable(): void
    {
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $asset = (new RentalAssetRepository($this->pdo, $this->encryption))->findById($this->assetId);
        $this->assertNotNull($asset);

        // A direct change before the first send (#927, chantier §2.3): the
        // tariff's price for the stay, with no discount.
        $this->pricingService->saveAssetPricing($this->assetId, 'flat_stay', 60000, null, null);
        $modifications = new \Modules\Rental\Service\RentalModificationService(
            $this->bookingRepository,
            new RentalModificationRepository($this->pdo),
            new \Modules\Rental\Repository\RentalDocumentRepository($this->pdo),
            new \Modules\Rental\Service\RentalOccupancyGuard(
                $this->bookingRepository,
                new RentalAvailabilityService(
                    new AvailabilityCalculator(),
                    new RentalConstraintsRepository($this->pdo),
                    [$this->bookings]
                ),
                $this->pricingService
            ),
            new RentalAvailabilityService(new AvailabilityCalculator(), new RentalConstraintsRepository($this->pdo)),
            $this->pricingService,
            $this->bookingAudit,
            $this->journal,
            null,
            $this->payments
        );
        $current = $this->reload($booking->id);
        $modifications->applyDirectly(
            $current,
            $asset,
            $modifications->termsFor($current, $asset, '2027-03-12', '2027-03-15', 32, 1, null, null),
            1,
            new \DateTimeImmutable()
        );

        $status = $this->paymentStatus($booking);
        $this->assertSame(75000, $status['total_cents']);
        $this->assertSame($communication, $status['communication']);
        $this->assertSame(1, $this->receivableCount());
        $this->assertSame($communication, $this->firstContract($this->reload($booking->id)));
    }

    public function testTheFirstSendSetsTheDepositDeadlineAndALaterOneMovesNothing(): void
    {
        $booking = $this->createBooking();
        $this->firstContract($booking);

        $this->operations->contractSent($booking, $this->asset(), 1, new \DateTimeImmutable('2027-01-20 10:00'));
        $this->operations->contractSent($this->reload($booking->id), $this->asset(), 1, new \DateTimeImmutable('2027-02-10 10:00'));

        $this->assertSame('2027-02-04', $this->paymentStatus($booking)['deposit_due_date']);
    }

    public function testConfirmingReusesTheReceivableTheContractRaised(): void
    {
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $asset = (new RentalAssetRepository($this->pdo, $this->encryption))->findById($this->assetId);
        $this->assertNotNull($asset);

        $this->operations->confirm($this->reload($booking->id), $asset, 1, new \DateTimeImmutable('2027-01-25'));

        $this->assertSame(1, $this->receivableCount());
        $this->assertSame($communication, $this->paymentStatus($booking)['communication']);
        $this->assertSame(81800, $this->paymentStatus($booking)['total_cents']);
    }

    // ── Abandoned: refused, cancelled, expired ──────────────────────────

    public function testARefusedBookingWithAPaymentKeepsItsReceivableAtZero(): void
    {
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $this->pay($communication, 250.00);

        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::REFUSED,
            1,
            new \DateTimeImmutable('2027-01-25')
        );

        $status = $this->paymentStatus($booking);
        $this->assertSame(0, $status['total_cents']);
        $this->assertSame(0, $status['remaining_cents']);
        // What came in is owed back, and Finance says so (chantier #927,
        // lot B): never « 0,00 € reçu » on a receivable brought to 0.
        $this->assertSame(25000, $status['received_cents']);
        $this->assertSame(25000, $status['overpaid_cents']);
        $this->assertSame($communication, $status['communication']);
        $this->assertSame(1, $this->receivableCount());
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT amount_due_cents FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testACancelledBookingKeepsItsReceivableAtZero(): void
    {
        $booking = $this->createBooking();
        $this->firstContract($booking);

        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::CANCELLED,
            1,
            new \DateTimeImmutable('2027-01-25')
        );

        $this->assertSame(0, $this->paymentStatus($booking)['total_cents']);
        $this->assertSame(1, $this->receivableCount());
    }

    public function testAFullyPaidBookingCancelledStillHoldsItsSecurityDeposit(): void
    {
        // The receivable is brought to 0, but the 150 € deposit the renter
        // paid is still in the unit's hands, to be given back — never
        // « À recevoir » again.
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $this->assertSame(
            \Modules\Rental\Payment\SecurityDepositStatus::RECEIVED,
            $this->paymentStatus($booking)['security_deposit']['status']
        );

        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::CANCELLED,
            1,
            new \DateTimeImmutable('2027-01-25')
        );

        $security = $this->paymentStatus($booking)['security_deposit'];
        $this->assertSame(\Modules\Rental\Payment\SecurityDepositStatus::RECEIVED, $security['status']);
        $this->assertSame(15000, $security['received_cents']);
    }

    public function testAPartlyPaidBookingCancelledDoesNotClaimItsSecurityDeposit(): void
    {
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 250.00);

        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::CANCELLED,
            1,
            new \DateTimeImmutable('2027-01-25')
        );

        $security = $this->paymentStatus($booking)['security_deposit'];
        $this->assertSame(\Modules\Rental\Payment\SecurityDepositStatus::TO_RECEIVE, $security['status']);
        $this->assertSame(0, $security['received_cents']);
    }

    public function testASettledBookingKeepsItsSecurityDepositReceived(): void
    {
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $security = $this->paymentStatus($booking)['security_deposit'];
        $this->assertSame(\Modules\Rental\Payment\SecurityDepositStatus::RECEIVED, $security['status']);
        $this->assertSame(15000, $security['received_cents']);
    }

    public function testAnExpiredOptionKeepsItsReceivableAtZero(): void
    {
        $booking = $this->createBooking();
        $this->firstContract($booking);
        $this->bookingRepository->setHold(
            $booking->id,
            new \DateTimeImmutable('2027-01-20 10:00'),
            HoldOrigin::MANAGER
        );

        $this->bookings->expireLapsedHolds(new \DateTimeImmutable('2027-01-25 10:00'));

        $this->assertSame(BookingStatus::EXPIRED, $this->reload($booking->id)->status);
        $this->assertSame(0, $this->paymentStatus($booking)['total_cents']);
        $this->assertSame(1, $this->receivableCount());
    }

    // ── The final settlement ────────────────────────────────────────────

    public function testAValidatedSettlementWithoutWithholdingLeavesTheSecurityDepositAsAnOverpayment(): void
    {
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);

        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->assertSame(-15000, $settlement->balanceCents);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $status = $this->paymentStatus($booking);
        $this->assertSame(66800, $status['total_cents']);
        $this->assertSame(15000, $status['overpaid_cents']);
        $this->assertSame(66800, $this->reload($booking->id)->effectiveTotalCents());
    }

    public function testAValidatedSettlementWithAChargedDamageAndAGestureKeepsTheirAmount(): void
    {
        // 668 € + 150 € paid. 34 € of damage charged, 80 € of goodwill: the
        // unit keeps 668 + 34 − 80 = 622 €, and owes 196 €.
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Vitre fêlée', 3400, null, 1);
        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::CHARGE, 3400, 1);
        $gesture = SettlementLine::gesture(null, 8000);
        $this->assertNotNull($gesture);

        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [$gesture], 1);
        $this->assertSame(62200, $settlement->keptCents());
        $this->assertSame(-19600, $settlement->balanceCents);
        $this->assertNotNull($settlement->gesture());
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $status = $this->paymentStatus($booking);
        $this->assertSame(62200, $status['total_cents']);
        $this->assertSame(19600, $status['overpaid_cents']);
        // The agreed price stays the witness of what was negotiated.
        $this->assertSame(66800, $this->reload($booking->id)->effectiveTotalCents());
    }

    public function testADamageDearerThanTheDepositLeavesABalanceDueOnTheSameCommunication(): void
    {
        // 668 € + 150 € paid, 800 € of damage charged (#980): the
        // receivable is brought to 1 468 €, the deposit is kept whole, and
        // 650 € remain due — on the one communication, with its QR.
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $this->pay($communication, 818.00);
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Châssis brisé', 80000, null, 1);
        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::CHARGE, null, 1);

        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->assertSame(146800, $settlement->keptCents());
        $this->assertSame(65000, $settlement->balanceCents);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $status = $this->paymentStatus($booking);
        $this->assertSame(146800, $status['total_cents']);
        $this->assertSame(65000, $status['remaining_cents']);
        $this->assertSame(0, $status['overpaid_cents']);
        $this->assertSame($communication, $status['communication']);
        $this->assertSame(1, $this->receivableCount());
        $this->assertSame(15000, $status['security_deposit']['withheld_cents']);
        $validated = $this->stay->latestSettlement($booking->id);
        $this->assertSame(15000, $validated?->securityDepositWithheldCents);
        $this->assertSame(0, $validated?->securityDepositReturnCents);
    }

    public function testTheDepositsShareIsDerivedFromTheMoneyAtValidation(): void
    {
        // Recorded before anything came in, validated once all of it had:
        // the version's deposit figures are the ones of the validation
        // (chantier §2.3), the whole deposit to give back.
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->assertSame(15000, $settlement->securityDepositWithheldCents, 'nothing received yet');

        $this->pay($communication, 818.00);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $validated = $this->stay->latestSettlement($booking->id);
        $this->assertSame(0, $validated?->securityDepositWithheldCents);
        $this->assertSame(15000, $validated?->securityDepositReturnCents);
        $this->assertSame(0, $this->paymentStatus($booking)['security_deposit']['withheld_cents']);
    }

    public function testASettlementIsNotValidatedOnABookingAbandonedSince(): void
    {
        // The receivable went to 0 with the cancellation; validating a
        // settlement recorded before it would raise it back.
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::CANCELLED,
            1,
            new \DateTimeImmutable('2027-01-25')
        );

        try {
            $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
            $this->fail('a settlement was validated on a cancelled booking');
        } catch (RentalException $e) {
            $this->assertStringContainsString('annulée', $e->getMessage());
        }

        $this->assertSame(0, $this->paymentStatus($booking)['total_cents']);
        $this->assertNull($this->stay->latestSettlement($booking->id)?->validatedAt);
    }

    public function testAnOlderSettlementIsNotValidatedOnceANewerOneIs(): void
    {
        // v2 validated: v1's figure is obsolete and must never overwrite
        // the receivable.
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $v1 = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $gesture = SettlementLine::gesture(null, 8000);
        $this->assertNotNull($gesture);
        $v2 = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [$gesture], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $v2->id, 1);

        try {
            $this->stay->validateSettlement($this->reload($booking->id), $v1->id, 1);
            $this->fail('an obsolete settlement was validated after a newer one');
        } catch (RentalException $e) {
            $this->assertStringContainsString('v2', $e->getMessage());
        }

        $this->assertSame(58800, $this->paymentStatus($booking)['total_cents']);
    }

    public function testANewerSettlementIsStillValidatedAfterAnOlderOne(): void
    {
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $v1 = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $v1->id, 1);
        $gesture = SettlementLine::gesture(null, 8000);
        $this->assertNotNull($gesture);
        $v2 = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [$gesture], 1);

        $this->stay->validateSettlement($this->reload($booking->id), $v2->id, 1);

        $this->assertSame(58800, $this->paymentStatus($booking)['total_cents']);
    }

    public function testRecordingASettlementWithoutValidatingItMovesNothing(): void
    {
        $booking = $this->createBooking();
        $this->firstContract($booking);

        $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);

        $this->assertSame(81800, $this->paymentStatus($booking)['total_cents']);
    }

    // ── The refund side (chantier #927, §2.6) ──────────────────────────

    /** A debit of the unit's account, as a bank import brings it. */
    private function refund(float $amount, string $label, string $date = '2027-03-20'): int
    {
        return $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            $date,
            $label,
            -$amount,
            null,
            null,
            'import',
            null,
            'Jeanne Martin'
        );
    }

    /** The booking's journey, as the dashboard reads it from this status. */
    private function depositReturnedLine(RentalBooking $booking): bool
    {
        $evidence = \Modules\Rental\Booking\MilestoneEvidence::collect(
            $this->reload($booking->id),
            null,
            $this->paymentStatus($booking),
            null,
            null
        );

        return $evidence->done[\Modules\Rental\Booking\BookingMilestones::SECURITY_DEPOSIT_RETURNED] ?? false;
    }

    public function testADepositRefundedWithTheCommunicationReadsReturned(): void
    {
        // 818 € in, settlement validated without withholding: 150 € owed
        // back. The manager transfers them from the bank with the booking's
        // communication; the import reconciles it, nothing is typed.
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $this->pay($communication, 818.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
        $this->assertSame(15000, $this->paymentStatus($booking)['overpaid_cents']);
        $this->assertFalse($this->depositReturnedLine($booking));

        $this->refund(150.00, 'Remboursement caution ' . $communication);

        $status = $this->paymentStatus($booking);
        $this->assertSame(0, $status['overpaid_cents']);
        $this->assertSame(15000, $status['refunded_cents']);
        $this->assertSame(
            \Modules\Rental\Payment\SecurityDepositStatus::RETURNED,
            $status['security_deposit']['status']
        );
        $this->assertTrue($this->depositReturnedLine($booking));
        $this->assertSame(15000, $status['security_deposit']['returned_cents']);
        $this->assertSame(0, $status['security_deposit']['withheld_cents']);

        $movements = $this->payments->movementsFor($this->reload($booking->id));
        $this->assertCount(2, $movements);
        // The receivable's SHARE of the payment (Finance\Api\ReceivableMovement):
        // what it absorbs at its kept amount, the surplus being the rest.
        $this->assertSame(66800, $movements[0]->amountCents);
        $this->assertSame(-15000, $movements[1]->amountCents);
        $this->assertTrue($movements[1]->isRefund());
    }

    /**
     * The settlement validated on the booking, with $chargedCents of damage
     * billed — which the deposit covers first (#980, chantier §2.3).
     */
    private function settleWithCharge(RentalBooking $booking, int $chargedCents): void
    {
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Dégâts', $chargedCents, null, 1);
        $this->stay->decideIncident(
            $this->reload($booking->id),
            $incident,
            IncidentDecision::CHARGE,
            $chargedCents,
            1
        );
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
    }

    public function testADepositKeptWholeIsSettledWithoutAnyRefund(): void
    {
        // 150 € of damage on a 150 € deposit: the settlement keeps 818 €,
        // nothing is owed back, and no refund will ever come to tick the
        // step.
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $this->settleWithCharge($booking, 15000);

        $status = $this->paymentStatus($booking);
        $this->assertSame(0, $status['overpaid_cents']);
        $this->assertSame(0, $status['refunded_cents']);
        $this->assertSame(
            \Modules\Rental\Payment\SecurityDepositStatus::FULLY_WITHHELD,
            $status['security_deposit']['status']
        );
        $this->assertSame(15000, $status['security_deposit']['withheld_cents']);
        $this->assertSame(0, $status['security_deposit']['returned_cents']);
        $this->assertTrue($this->depositReturnedLine($booking));
    }

    public function testADepositKeptInPartIsPartlyWithheldOnceTheRestIsRefunded(): void
    {
        // 34 € of damage: 116 € owed back, and only once they went back is
        // the deposit's life over — partly withheld, not « restituée ».
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $this->pay($communication, 818.00);
        $this->settleWithCharge($booking, 3400);

        $before = $this->paymentStatus($booking);
        $this->assertSame(11600, $before['overpaid_cents']);
        $this->assertSame(
            \Modules\Rental\Payment\SecurityDepositStatus::RECEIVED,
            $before['security_deposit']['status']
        );
        $this->assertFalse($this->depositReturnedLine($booking));

        $this->refund(116.00, 'Remboursement caution ' . $communication);

        $status = $this->paymentStatus($booking);
        $this->assertSame(0, $status['overpaid_cents']);
        $this->assertSame(
            \Modules\Rental\Payment\SecurityDepositStatus::PARTIALLY_WITHHELD,
            $status['security_deposit']['status']
        );
        $this->assertSame(3400, $status['security_deposit']['withheld_cents']);
        $this->assertSame(11600, $status['security_deposit']['returned_cents']);
        $this->assertTrue($this->depositReturnedLine($booking));
    }

    public function testTenEurosPaidTooMuchAndGivenBackAreNotTheDepositGoingBack(): void
    {
        // The receivable still asks for the deposit: a refund of a small
        // surplus, while the stay has not even happened, returns nothing of
        // the security deposit.
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $this->pay($communication, 828.00);
        $this->assertSame(1000, $this->paymentStatus($booking)['overpaid_cents']);

        $this->refund(10.00, 'Trop payé ' . $communication);

        $status = $this->paymentStatus($booking);
        $this->assertSame(0, $status['overpaid_cents']);
        $this->assertSame(1000, $status['refunded_cents']);
        $this->assertSame(
            \Modules\Rental\Payment\SecurityDepositStatus::RECEIVED,
            $status['security_deposit']['status']
        );
        $this->assertFalse($this->depositReturnedLine($booking));
    }

    public function testARefundMadeWithoutTheCommunicationIsReconciledByHand(): void
    {
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
        $debitId = $this->refund(150.00, 'Virement européen');
        $this->refund(42.10, 'Brico Namur');
        $since = RentalPaymentService::refundSince(
            $this->reload($booking->id),
            new \DateTimeImmutable('2027-03-18 15:00')
        );
        $this->assertSame('2027-03-18 00:00', $since->format('Y-m-d H:i'));

        $candidates = $this->payments->refundCandidates($this->reload($booking->id), $since);
        $this->assertSame(
            [$debitId, 15000],
            [$candidates[0]->transactionId, $candidates[0]->amountCents]
        );

        $this->payments->reconcileRefund($this->reload($booking->id), $debitId, $since, null, 1);

        $this->assertSame(0, $this->paymentStatus($booking)['overpaid_cents']);
        $this->assertTrue($this->depositReturnedLine($booking));
        $this->assertSame([], $this->payments->refundCandidates($this->reload($booking->id), $since));
    }

    public function testADebitBeforeTheSurplusAroseIsNoRefundOfIt(): void
    {
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
        $early = $this->refund(150.00, 'Virement européen', '2027-03-01');
        $since = new \DateTimeImmutable('2027-03-18');

        $this->assertSame([], $this->payments->refundCandidates($this->reload($booking->id), $since));
        $this->expectException(RentalException::class);
        $this->payments->reconcileRefund($this->reload($booking->id), $early, $since, null, 1);
    }

    public function testTheSurplusDatesFromTheSettlementTheEndOrTheRequest(): void
    {
        $booking = $this->createBooking();
        $this->assertSame(
            '2027-01-10',
            RentalPaymentService::refundSince($booking, null)->format('Y-m-d')
        );
        $this->assertSame(
            '2027-03-18',
            RentalPaymentService::refundSince($booking, new \DateTimeImmutable('2027-03-18 09:30'))->format('Y-m-d')
        );

        $this->operations->changeStatus($booking, BookingStatus::CANCELLED, 1, new \DateTimeImmutable('2027-01-25'));
        $finalAt = $this->reload($booking->id)->finalAt;
        $this->assertNotNull($finalAt);
        $this->assertSame(
            $finalAt->format('Y-m-d'),
            RentalPaymentService::refundSince($this->reload($booking->id), null)->format('Y-m-d')
        );
    }

    // ── How to pay (chantier #927, §2.4) ───────────────────────────────

    public function testTheInstructionsAskForTheDepositThenTheRest(): void
    {
        $booking = $this->createBooking();
        $communication = $this->firstContract($booking);
        $this->payments->contractSent($this->reload($booking->id), $this->settings(), new \DateTimeImmutable('2027-01-15'));

        $deposit = $this->payments->paymentInstructions($this->reload($booking->id), $this->settings());
        $this->assertNotNull($deposit);
        $this->assertSame($communication, $deposit['communication']);
        $this->assertSame('Unité Saint-Georges', $deposit['beneficiary']);
        $this->assertSame('BE68 5390 0754 7034', $deposit['iban']);
        $this->assertSame(25000, $deposit['amount_cents']);
        $this->assertSame('deposit', $deposit['stage']);
        $this->assertSame('2027-01-30', $deposit['due_date']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $deposit['qr_data_uri']);

        $this->pay($communication, 250.00);
        $balance = $this->payments->paymentInstructions($this->reload($booking->id), $this->settings());
        $this->assertNotNull($balance);
        $this->assertSame(56800, $balance['amount_cents']);
        $this->assertSame('balance', $balance['stage']);
        $this->assertTrue($balance['includes_security_deposit']);
        // 14 days before the arrival, the security deposit with it.
        $this->assertSame('2027-02-26', $balance['due_date']);

        $this->pay($communication, 568.00);
        $paid = $this->payments->paymentInstructions($this->reload($booking->id), $this->settings());
        $this->assertNotNull($paid);
        $this->assertSame(0, $paid['amount_cents']);
        $this->assertNull($paid['qr_data_uri']);
    }

    public function testThereAreNoInstructionsBeforeTheReceivableNorWithoutFinance(): void
    {
        $booking = $this->createBooking();
        $this->assertNull($this->payments->paymentInstructions($booking, $this->settings()));

        $this->firstContract($booking);
        $withoutFinance = new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            $this->journal
        );
        $this->assertNull($withoutFinance->paymentInstructions($this->reload($booking->id), $this->settings()));
        $this->assertSame([], $withoutFinance->movementsFor($this->reload($booking->id)));
    }

    // ── Without Finance ─────────────────────────────────────────────────

    public function testWithoutFinanceEveryStepDegradesWithoutAnError(): void
    {
        $withoutFinance = new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            $this->journal
        );
        $this->wire($withoutFinance);
        $booking = $this->createBooking();

        $this->assertNull($withoutFinance->communicationForContract($booking, $this->settings()));
        $this->operations->contractSent($booking, $this->asset(), 1, new \DateTimeImmutable('2027-01-20 10:00'));
        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::CANCELLED,
            1,
            new \DateTimeImmutable('2027-01-25')
        );

        $this->assertSame(0, $this->receivableCount());
        $status = $withoutFinance->statusFor($this->reload($booking->id), $this->settings());
        $this->assertFalse($status['available']);
        $this->assertNull($status['communication']);
        // The rental and its security deposit are still stated.
        $this->assertSame(81800, $status['total_cents']);
    }

    private function asset(): \Modules\Rental\Repository\RentalAsset
    {
        $asset = (new RentalAssetRepository($this->pdo, $this->encryption))->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }
}
