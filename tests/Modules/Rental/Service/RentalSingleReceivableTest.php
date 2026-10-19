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
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
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
            new RentalChangeRequestRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            null,
            $payments
        );
        $this->operations = new RentalOperationsService(
            $this->bookingRepository,
            $this->bookingAudit,
            new RentalBookingCommentRepository($this->pdo, $this->encryption),
            new RentalChangeRequestRepository($this->pdo, $this->encryption),
            new RentalAvailabilityService(
                new AvailabilityCalculator(),
                new RentalConstraintsRepository($this->pdo),
                [$this->bookings]
            ),
            $this->pricingService,
            new QuoteEditor(),
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

        $this->operations->editPriceLine($this->reload($booking->id), $asset, 0, 'Séjour', 1, 60000, 1);

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

    public function testAValidatedSettlementWithAWithholdingAndAGestureKeepsTheirAmount(): void
    {
        // 668 € + 150 € paid. 34 € withheld from the deposit, 80 € of
        // goodwill: the unit keeps 668 − 80 + 34 = 622 €, and owes 196 €.
        $booking = $this->createBooking();
        $this->pay($this->firstContract($booking), 818.00);
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Vitre fêlée', 3400, null, 1);
        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::WITHHOLD, 3400, 1);
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
