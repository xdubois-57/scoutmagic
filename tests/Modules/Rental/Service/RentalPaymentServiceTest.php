<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\FinanceAccountService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPaymentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Rental payments (§6.19, §6.20), against the **real** Finance services
 * rather than mocks of them.
 *
 * Mocking Finance here would test that `rental` calls the methods it thinks
 * it calls, which is the least interesting thing that could go wrong. What
 * matters is the behaviour at the seam: that one receivable — rental plus
 * security deposit, one communication — serves the deposit, the balance and
 * the security deposit (issue #927), that its amount follows the booking to
 * 0 or to the kept amount without ever being deleted, and — the point of the
 * whole nullable dependency — that every one of these paths degrades
 * quietly when Finance is not there at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalPaymentServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private RentalPaymentRepository $paymentRepository;
    private BookingAudit $bookingAudit;
    private TransactionRepository $transactionRepository;
    private RentalPaymentService $service;
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
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->paymentRepository = new RentalPaymentRepository($this->pdo, $this->encryption);
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

        $this->service = new RentalPaymentService(
            $this->paymentRepository,
            $this->bookingAudit,
            $journal,
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository),
            new SepaQrCodeService(),
            new FinanceAccountService($accountRepository)
        );

        $assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->assetId = $assetRepository->create(
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
    }

    /** The same service with every Finance dependency absent. */
    private function withoutFinance(): RentalPaymentService
    {
        return new RentalPaymentService(
            $this->paymentRepository,
            $this->bookingAudit,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function settings(
        bool $enabled = true,
        DepositMode $depositMode = DepositMode::NONE,
        ?int $depositCents = null,
        ?int $depositPercentage = null,
        bool $securityDeposit = false,
        ?int $securityDepositCents = null
    ): PaymentSettings {
        return new PaymentSettings(
            enabled: $enabled,
            financeAccountId: $enabled ? $this->accountId : null,
            financeCategoryId: $enabled ? $this->categoryId : null,
            depositMode: $depositMode,
            depositAmountCents: $depositCents,
            depositPercentage: $depositPercentage,
            depositDueDays: 14,
            balanceDueDays: 30,
            securityDepositEnabled: $securityDeposit,
            securityDepositAmountCents: $securityDepositCents
        );
    }

    private function quote(int $totalCents): PriceQuote
    {
        return new PriceQuote(
            lines: [new PriceLine('Séjour', 1, null, $totalCents, PriceLine::RULE_BASE)],
            totalCents: $totalCents,
            nights: 3,
            persons: 20,
            quantity: 3,
            billingUnit: BillingUnit::PER_NIGHT
        );
    }

    private function createBooking(int $totalCents = 46750, string $reference = 'LOC-A2B3C4'): RentalBooking
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            $this->quote($totalCents),
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

    private function reload(RentalBooking $booking): RentalBooking
    {
        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function pay(string $communication, float $amount): void
    {
        $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            '2027-02-01',
            'Virement ' . $communication,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-02-01 10:00:00');
    }

    // ── Finance disabled: the whole module keeps working ─────────────────

    public function testWithoutFinanceNothingIsAvailableAndNothingBreaks(): void
    {
        $service = $this->withoutFinance();
        $booking = $this->createBooking();

        $this->assertFalse($service->isAvailable());
        $this->assertSame([], $service->availableAccounts());

        $raised = $service->ensureReceivable($booking, $this->settings());

        $this->assertNull($raised);
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testWithoutFinanceTheStatusStillReadsSensibly(): void
    {
        // The booking page renders this: it must be an honest "nothing to
        // show", never a crash or a half-filled panel.
        $service = $this->withoutFinance();
        $status = $service->statusFor($this->createBooking(), $this->settings());

        $this->assertFalse($status['available']);
        $this->assertSame(0, $status['received_cents']);
        $this->assertSame(SecurityDepositStatus::NONE, $status['security_deposit']['status']);
    }

    public function testWithoutFinanceNoQrIsOffered(): void
    {
        $service = $this->withoutFinance();
        $booking = $this->createBooking();

        $this->assertNull($service->sepaQrPng($booking, $this->settings()));
    }

    public function testAnAssetWithPaymentsOffRaisesNothingEvenWithFinanceOn(): void
    {
        $raised = $this->service->ensureReceivable($this->createBooking(), $this->settings(enabled: false));

        $this->assertNull($raised);
    }

    // ── Configuration ───────────────────────────────────────────────────

    public function testPaymentsCannotBeEnabledWithoutAnAccount(): void
    {
        // Half-configured is worse than off: it looks like it is working.
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/compte/');

        $this->service->saveSettings($this->assetId, new PaymentSettings(enabled: true));
    }

    public function testAnOutOfRangePercentageIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service->saveSettings($this->assetId, new PaymentSettings(
            enabled: true,
            financeAccountId: $this->accountId,
            financeCategoryId: $this->categoryId,
            depositMode: DepositMode::PERCENTAGE,
            depositPercentage: 150
        ));
    }

    public function testSettingsRoundTrip(): void
    {
        $this->service->saveSettings($this->assetId, $this->settings(
            depositMode: DepositMode::PERCENTAGE,
            depositPercentage: 30,
            securityDeposit: true,
            securityDepositCents: 50000
        ));

        $loaded = $this->service->settingsFor($this->assetId);

        $this->assertTrue($loaded->enabled);
        $this->assertSame($this->accountId, $loaded->financeAccountId);
        $this->assertSame(DepositMode::PERCENTAGE, $loaded->depositMode);
        $this->assertSame(30, $loaded->depositPercentage);
        $this->assertTrue($loaded->securityDepositEnabled);
        $this->assertSame(50000, $loaded->securityDepositAmountCents);
    }

    public function testAnAssetKeepsItsPaymentConfigurationWhenPaymentsAreSwitchedOff(): void
    {
        // Switching off must not wipe what was configured: switching back on
        // should not mean typing it all again.
        $this->service->saveSettings($this->assetId, $this->settings(
            depositMode: DepositMode::FIXED,
            depositCents: 10000
        ));
        $this->service->saveSettings($this->assetId, new PaymentSettings(
            enabled: false,
            depositMode: DepositMode::FIXED,
            depositAmountCents: 10000
        ));

        $this->assertSame(10000, $this->service->settingsFor($this->assetId)->depositAmountCents);
    }

    // ── One receivable for the whole rental (§6.19) ─────────────────────

    public function testRaisingCreatesExactlyOneReceivable(): void
    {
        $booking = $this->createBooking(46750);

        $this->service->ensureReceivable($booking, $this->settings());

        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
        $this->assertSame(46750, (int) $this->pdo->query(
            'SELECT amount_due_cents FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testTheDepositIsAThresholdNotASecondReceivable(): void
    {
        // Two receivables would mean two communications, and a renter
        // paying the balance with the deposit's reference would settle the
        // wrong one (§6.19).
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::PERCENTAGE, depositPercentage: 30);

        $this->service->ensureReceivable($booking, $settings);

        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(14025, $status['deposit_cents']);
        $this->assertFalse($status['deposit_received']);
    }

    public function testTheDepositThresholdIsReachedByAPartialPayment(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::FIXED, depositCents: 10000);
        $this->service->ensureReceivable($booking, $settings);

        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 100.00);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertTrue($status['deposit_received']);
        $this->assertFalse($status['fully_paid']);
        $this->assertSame(36750, $status['remaining_cents']);
    }

    public function testTheDepositAndTheBalanceShareOneCommunication(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::FIXED, depositCents: 10000);
        $this->service->ensureReceivable($booking, $settings);

        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 100.00);
        $this->pay($communication, 367.50);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertTrue($status['fully_paid']);
        $this->assertSame(0, $status['remaining_cents']);
    }

    public function testAnOverpaymentIsVisibleRatherThanClampedAway(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings();
        $this->service->ensureReceivable($booking, $settings);
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];

        $this->pay($communication, 500.00);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(50000, $status['received_cents']);
        // Nothing is owed any more, and the surplus is plain to see in the
        // two figures rather than hidden by a clamp.
        $this->assertSame(0, $status['remaining_cents']);
        $this->assertTrue($status['fully_paid']);
    }

    public function testRaisingReceivablesTwiceNeverMintsASecondCommunication(): void
    {
        // Minting a new one would orphan every transfer the renter already
        // made against the old.
        $booking = $this->createBooking(46750);
        $settings = $this->settings();

        $first = $this->service->ensureReceivable($booking, $settings);
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 200.00);

        $second = $this->service->ensureReceivable($this->reload($booking), $settings);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
        $this->assertSame(20000, $this->service->statusFor($booking, $settings)['received_cents']);
    }

    public function testABookingWithNoPriceYetCannotRaiseAReceivable(): void
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-2027-0009',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            ['name' => 'Sans prix', 'email' => 'x@example.be', 'phone' => null, 'organisation' => null, 'purpose' => null, 'comment' => null],
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

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/prix/');

        $this->service->ensureReceivable($booking, $this->settings());
    }

    // ── Amount updates (§6.12) ──────────────────────────────────────────

    public function testANegotiatedPriceMovesTheReceivableInPlace(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::PERCENTAGE, depositPercentage: 30);
        $this->service->ensureReceivable($booking, $settings);

        $this->service->updateRentalAmount($booking, $settings, 40000);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(40000, $status['total_cents']);
        // The threshold follows the new total, because it is a percentage
        // of it.
        $this->assertSame(12000, $status['deposit_cents']);
    }

    public function testLoweringBelowWhatCameInIsRefusedAsARentalException(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings();
        $this->service->ensureReceivable($booking, $settings);
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 300.00);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/trop-per/');

        $this->service->updateRentalAmount($booking, $settings, 25000);
    }

    public function testLoweringBelowWhatCameInGoesThroughWhenExplicitlyAllowed(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings();
        $this->service->ensureReceivable($booking, $settings);
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 300.00);

        $this->service->updateRentalAmount($booking, $settings, 25000, allowOverpayment: true);

        $this->assertSame(25000, $this->service->statusFor($booking, $settings)['total_cents']);
    }

    public function testUpdatingWithNoReceivableRaisedIsANoOp(): void
    {
        $booking = $this->createBooking(46750);

        $this->service->updateRentalAmount($booking, $this->settings(), 40000);

        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testAChangedPriceKeepsTheSecurityDepositOnTop(): void
    {
        // A direct change before the first send, or a countersigned
        // modification after it: the new rental price, plus the security
        // deposit snapshotted with the receivable — same receivable.
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 15000);
        $id = $this->service->ensureReceivable($booking, $settings);
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 250.00);

        $this->service->updateRentalAmount($booking, $settings, 66800);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(81800, $status['total_cents']);
        $this->assertSame($communication, $status['communication']);
        $this->assertSame(25000, $status['received_cents']);
        $this->assertSame($id, $this->service->ensureReceivable($booking, $settings));
    }

    // ── The contract prints the communication (issue #927) ──────────────

    public function testTheContractCommunicationRaisesTheReceivableFirst(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 15000);

        $communication = $this->service->communicationForContract($booking, $settings);

        $this->assertNotNull($communication);
        $this->assertMatchesRegularExpression('/^\+\+\+\d{3}\/\d{4}\/\d{5}\+\+\+$/', $communication);
        $this->assertSame($communication, $this->service->statusFor($booking, $settings)['communication']);
        $this->assertSame(61750, (int) $this->pdo->query(
            'SELECT amount_due_cents FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testRegeneratingAContractReusesTheSameCommunication(): void
    {
        // Every regeneration, version or modification prints the one
        // communication the renter may already have paid against.
        $booking = $this->createBooking(46750);
        $settings = $this->settings();

        $first = $this->service->communicationForContract($booking, $settings);
        $second = $this->service->communicationForContract($this->reload($booking), $settings);

        $this->assertSame($first, $second);
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testARegeneratedContractKeepsItsCommunicationAfterTheAccountWasUnpinned(): void
    {
        // The communication exists and the renter may have paid against
        // it: losing the account or category since does not refuse the
        // next version.
        $booking = $this->createBooking(46750);
        $first = $this->service->communicationForContract($booking, $this->settings());

        $again = $this->service->communicationForContract(
            $this->reload($booking),
            new PaymentSettings(enabled: true, balanceDueDays: 14)
        );

        $this->assertNotNull($first);
        $this->assertSame($first, $again);
    }

    public function testAContractIsRefusedWhenPaymentsAreOnButNoReceivableCanBeRaised(): void
    {
        // Never a contract without a communication: switched on with no
        // account to raise it on, the generation is refused, and says why.
        $settings = new PaymentSettings(enabled: true, balanceDueDays: 14);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/Le contrat n.a pas été généré.*compte/');

        $this->service->communicationForContract($this->createBooking(), $settings);
    }

    public function testAContractIsRefusedForABookingWithNoPriceWhenPaymentsAreOn(): void
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-B2B3C4',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            ['name' => 'Sans prix', 'email' => 'x@example.be', 'phone' => null, 'organisation' => null,
                'purpose' => null, 'comment' => null],
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

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/Le contrat n.a pas été généré.*prix/');

        $this->service->communicationForContract($booking, $this->settings());
    }

    public function testWithoutFinanceTheContractIsGeneratedWithoutACommunication(): void
    {
        // The explicit degraded mode: no Finance, no communication, and no
        // refusal either — the unit follows its payments by hand.
        $this->assertNull($this->withoutFinance()->communicationForContract($this->createBooking(), $this->settings()));
    }

    public function testWithPaymentsOffTheContractIsGeneratedWithoutACommunication(): void
    {
        $this->assertNull($this->service->communicationForContract(
            $this->createBooking(),
            $this->settings(enabled: false)
        ));
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    // ── Abandoned and settled bookings (issue #927) ─────────────────────

    public function testAnAbandonedBookingBringsTheReceivableToZeroAndKeepsIt(): void
    {
        // Refused, cancelled, expired: nothing is owed, what came in is an
        // overpayment, and the receivable stays with its communication.
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 15000);
        $this->service->ensureReceivable($booking, $settings);
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 100.00);

        $this->service->abandonAmount($booking, 1);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(0, $status['total_cents']);
        $this->assertSame(0, $status['remaining_cents']);
        $this->assertFalse($status['fully_paid']);
        $this->assertSame($communication, $status['communication']);
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testAbandoningABookingWithoutAReceivableDoesNothing(): void
    {
        $booking = $this->createBooking();

        $this->service->abandonAmount($booking);
        $this->withoutFinance()->abandonAmount($booking);

        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testASettledBookingKeepsTheSettlementsAmount(): void
    {
        // 668 € rental + 150 € security deposit all paid; the settlement
        // keeps 622 €, and the 196 € beyond it read as an overpayment.
        $booking = $this->createBooking(66800);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 15000);
        $this->service->ensureReceivable($booking, $settings);
        $this->pay((string) $this->service->statusFor($booking, $settings)['communication'], 818.00);

        $this->service->settleAmount($booking, 62200, 1);

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(62200, $status['total_cents']);
        $this->assertSame(19600, $status['overpaid_cents']);
        // The agreed price is not the settlement's to change.
        $this->assertSame(66800, $this->reload($booking)->effectiveTotalCents());
    }

    // ── The security deposit (§6.20) ────────────────────────────────────

    public function testTheSecurityDepositIsAddedToTheSameReceivable(): void
    {
        // One receivable, one communication, for the rental and its
        // security deposit alike (issue #927).
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);

        $this->service->ensureReceivable($booking, $settings);

        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
        $this->assertSame(96750, (int) $this->pdo->query(
            'SELECT amount_due_cents FROM finance_expected_receivables'
        )->fetchColumn());

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame(96750, $status['total_cents']);
        $this->assertSame(46750, $status['rental_cents']);
        $this->assertSame(50000, $status['security_deposit']['amount_cents']);
        $this->assertArrayNotHasKey('communication', $status['security_deposit']);
    }

    public function testPayingTheRentalAloneLeavesTheSecurityDepositOutstanding(): void
    {
        // The receipts are compared to the whole receivable, never split:
        // 467,50 € in leaves 500 € due, security deposit and all.
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);

        $this->pay((string) $this->service->statusFor($booking, $settings)['communication'], 467.50);

        $after = $this->service->statusFor($booking, $settings);
        $this->assertSame(46750, $after['received_cents']);
        $this->assertSame(50000, $after['remaining_cents']);
        $this->assertFalse($after['fully_paid']);
        $this->assertSame(0, $after['security_deposit']['received_cents']);
        $this->assertSame(SecurityDepositStatus::TO_RECEIVE, $after['security_deposit']['status']);
    }

    public function testAnAssetWithoutASecurityDepositRaisesTheRentalAlone(): void
    {
        $booking = $this->createBooking(46750);
        $this->service->ensureReceivable($booking, $this->settings());

        $this->assertSame(46750, (int) $this->pdo->query(
            'SELECT amount_due_cents FROM finance_expected_receivables'
        )->fetchColumn());
        $this->assertNull($this->service->statusFor($booking, $this->settings())['security_deposit']['amount_cents']);
    }

    public function testTheSecurityDepositIsSnapshottedWithTheReceivable(): void
    {
        // A security deposit changed on the asset afterwards does not
        // rewrite what this renter was asked.
        $booking = $this->createBooking(46750);
        $this->service->ensureReceivable($booking, $this->settings(securityDeposit: true, securityDepositCents: 50000));

        $status = $this->service->statusFor(
            $booking,
            $this->settings(securityDeposit: true, securityDepositCents: 80000)
        );

        $this->assertSame(50000, $status['security_deposit']['amount_cents']);
        $this->assertSame(96750, $status['total_cents']);
    }

    public function testTheSecurityDepositStartsAsToReceive(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);

        $this->assertSame(
            SecurityDepositStatus::TO_RECEIVE,
            $this->service->statusFor($booking, $settings)['security_deposit']['status']
        );
    }

    public function testAFullyPaidSecurityDepositReadsAsReceived(): void
    {
        // Derived from the account, exactly like `deposit_received`: nothing
        // reconciles an incoming transfer into a status column, so the badge
        // used to read "À recevoir" over money the unit was already holding.
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);

        $status = $this->service->statusFor($booking, $settings);
        $this->pay((string) $status['communication'], 967.50);

        $after = $this->service->statusFor($booking, $settings)['security_deposit'];
        $this->assertSame(SecurityDepositStatus::RECEIVED, $after['status']);
        $this->assertTrue($after['status']->isHeld());
    }

    public function testAPartlyPaidSecurityDepositIsStillToReceive(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);

        // 500 € in: as much as the security deposit, but the receivable
        // is not split, and the whole of it is not there yet.
        $status = $this->service->statusFor($booking, $settings);
        $this->pay((string) $status['communication'], 500.00);

        $this->assertSame(
            SecurityDepositStatus::TO_RECEIVE,
            $this->service->statusFor($booking, $settings)['security_deposit']['status']
        );
    }

    public function testWithFinancesTheReturnIsNeverTypedByHand(): void
    {
        // Chantier #927, §2.6: the deposit goes back by bank transfer with
        // the booking's communication and the account says it went. A
        // hand-typed return beside it would be a second answer that could
        // disagree with the bank.
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);
        $status = $this->service->statusFor($booking, $settings);
        $this->pay((string) $status['communication'], 967.50);

        try {
            $this->service->recordSecurityDepositReturn(
                $booking,
                50000,
                null,
                new \DateTimeImmutable('2027-07-10'),
                1,
                $settings
            );
            $this->fail('A return was typed by hand on a booking Finances follows.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('virement', $e->getMessage());
        }

        $this->assertSame(
            SecurityDepositStatus::RECEIVED,
            $this->service->statusFor($booking, $settings)['security_deposit']['status']
        );
    }

    /**
     * Without Finances the asset's own setting says how much the deposit
     * is — there is no receivable to have snapshotted it — and the return
     * is written down by hand, the only place it can be.
     */
    private function withoutFinanceReturn(
        int $returnedCents,
        ?string $note,
        ?RentalBooking $booking = null
    ): RentalBooking {
        $booking ??= $this->createBooking();
        $this->withoutFinance()->recordSecurityDepositReturn(
            $booking,
            $returnedCents,
            $note,
            new \DateTimeImmutable('2027-07-10'),
            1,
            $this->settings(enabled: false, securityDeposit: true, securityDepositCents: 50000)
        );

        return $booking;
    }

    /** @return array<string, mixed> the security deposit, as read without Finances */
    private function securityWithoutFinance(RentalBooking $booking): array
    {
        return $this->withoutFinance()->statusFor(
            $booking,
            $this->settings(enabled: false, securityDeposit: true, securityDepositCents: 50000)
        )['security_deposit'];
    }

    public function testAFullReturnIsRecordedByHandAndLandsOnReturned(): void
    {
        $booking = $this->withoutFinanceReturn(50000, null);

        $status = $this->securityWithoutFinance($booking);
        $this->assertSame(SecurityDepositStatus::RETURNED, $status['status']);
        $this->assertSame(50000, $status['returned_cents']);
        $this->assertSame(0, $status['withheld_cents']);
        $this->assertSame('2027-07-10', $status['returned_at']);
    }

    public function testAPartialReturnNeedsAWrittenReason(): void
    {
        // Withholding somebody's money without a written reason is not
        // something to allow quietly.
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/retenue/');

        $this->withoutFinanceReturn(30000, null);
    }

    public function testAPartialReturnWithAReasonLandsOnPartiallyWithheld(): void
    {
        $booking = $this->withoutFinanceReturn(30000, 'Vitre cassée dans le réfectoire.');

        $status = $this->securityWithoutFinance($booking);
        $this->assertSame(SecurityDepositStatus::PARTIALLY_WITHHELD, $status['status']);
        $this->assertSame(20000, $status['withheld_cents']);
        $this->assertSame('Vitre cassée dans le réfectoire.', $status['note']);
    }

    public function testTheWithholdingReasonIsStoredEncrypted(): void
    {
        $this->withoutFinanceReturn(0, 'Le groupe a laissé le local dans un état déplorable.');

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('état déplorable', $raw);
    }

    public function testTheWithholdingReasonNeverReachesTheJournal(): void
    {
        $this->withoutFinanceReturn(0, 'Le groupe a laissé le local dans un état déplorable.');

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('LOC-A2B3C4', $journal);
        $this->assertStringNotContainsString('état déplorable', $journal);
        $this->assertStringNotContainsString('Jeanne Martin', $journal);
    }

    public function testReturningMoreThanTheDepositIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->withoutFinanceReturn(60000, null);
    }

    public function testRecordingAReturnWithNoDepositAtAllIsRefused(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/caution/');

        $this->service->recordSecurityDepositReturn(
            $this->createBooking(),
            0,
            'Rien à restituer',
            new \DateTimeImmutable('2027-07-10'),
            1
        );
    }

    public function testMarkingADepositToReturnIsRecordedInTheHistory(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);

        $this->service->markSecurityDepositToReturn($booking, 1);

        $this->assertSame(
            SecurityDepositStatus::TO_RETURN,
            $this->service->statusFor($booking, $settings)['security_deposit']['status']
        );
        $this->assertNotSame([], RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id));
    }

    // ── SEPA QR (§6.19) ─────────────────────────────────────────────────

    public function testTheQrAsksForTheDepositWhileItIsOutstanding(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::FIXED, depositCents: 10000);
        $this->service->ensureReceivable($booking, $settings);

        $png = $this->service->sepaQrPng($booking, $settings);

        $this->assertIsString($png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function testNoQrIsOfferedOnceEverythingIsPaid(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings();
        $this->service->ensureReceivable($booking, $settings);
        $this->pay((string) $this->service->statusFor($booking, $settings)['communication'], 467.50);

        $this->assertNull($this->service->sepaQrPng($booking, $settings));
    }

    public function testNoQrIsOfferedWithoutAnIbanOnTheAccount(): void
    {
        $this->pdo->exec('UPDATE finance_accounts SET iban = NULL, iban_blind_index = NULL');
        $booking = $this->createBooking();
        $settings = $this->settings();
        $this->service->ensureReceivable($booking, $settings);

        $this->assertNull($this->service->sepaQrPng($booking, $settings));
    }

    public function testTheQrStillAsksOnceOnlyTheRentalIsPaid(): void
    {
        // The security deposit is part of the balance the QR asks for.
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);
        $this->pay((string) $this->service->statusFor($booking, $settings)['communication'], 467.50);

        $this->assertIsString($this->service->sepaQrPng($booking, $settings));

        $this->pay((string) $this->service->statusFor($booking, $settings)['communication'], 500.00);

        $this->assertNull($this->service->sepaQrPng($booking, $settings));
    }

    // ── Deleting a booking ──────────────────────────────────────────────

    public function testForgettingABookingDropsItsReceivables(): void
    {
        // Finance must not be left expecting money for a rental that no
        // longer exists.
        $booking = $this->createBooking();
        $this->service->ensureReceivable($booking, $this->settings(securityDeposit: true, securityDepositCents: 50000));

        $this->service->forgetBooking($booking->id);

        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testForgettingABookingIsSafeWithoutFinance(): void
    {
        // Finance disabled after the receivables were written: rental has
        // no way to reach them, so it must leave them exactly as they are
        // rather than reach around the missing capability.
        $booking = $this->createBooking();
        $this->service->ensureReceivable($booking, $this->settings(securityDeposit: true, securityDepositCents: 50000));
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables')->fetchColumn();
        $this->assertGreaterThan(0, $before);

        $this->withoutFinance()->forgetBooking($booking->id);

        $this->assertSame($before, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    // ── Due dates ───────────────────────────────────────────────────────

    public function testTheDepositFallsDueAfterTheFirstContractSent(): void
    {
        // Nothing is due before a contract went out; then N days after the
        // first send, and a later send — a replacement contract — moves
        // nothing (issue #927).
        $booking = $this->createBooking();
        $settings = $this->settings(depositMode: DepositMode::FIXED, depositCents: 10000);
        $this->service->ensureReceivable($booking, $settings);

        $this->assertNull($this->service->statusFor($booking, $settings)['deposit_due_date']);

        $this->service->contractSent($booking, $settings, new \DateTimeImmutable('2027-02-01 10:00'));
        $this->service->contractSent($booking, $settings, new \DateTimeImmutable('2027-03-20 10:00'));

        $this->assertSame('2027-02-15', $this->service->statusFor($booking, $settings)['deposit_due_date']);
    }

    public function testTheDepositDeadlineIsKeptEvenBeforeAnyReceivable(): void
    {
        // Payments switched on after the contract went out still know when
        // the deposit was due.
        $booking = $this->createBooking();
        $settings = $this->settings();

        $this->service->contractSent($booking, $settings, new \DateTimeImmutable('2027-02-01 10:00'));
        $this->service->ensureReceivable($booking, $settings);

        $this->assertSame('2027-02-15', $this->service->statusFor($booking, $settings)['deposit_due_date']);
    }

    public function testTheBalanceAndTheSecurityDepositFallDueBeforeTheArrival(): void
    {
        // Arrival 2027-07-01, balance due 30 days before it.
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivable($booking, $settings);

        $status = $this->service->statusFor($booking, $settings);

        $this->assertSame('2027-06-01', $status['balance_due_date']);
        $this->assertSame('2027-06-01', $status['security_deposit']['due_date']);
    }

    public function testNoBalanceDeadlineIsAnnouncedWithoutOne(): void
    {
        $booking = $this->createBooking();
        $settings = new PaymentSettings(
            enabled: true,
            financeAccountId: $this->accountId,
            financeCategoryId: $this->categoryId
        );

        $this->assertNull($this->service->statusFor($booking, $settings)['balance_due_date']);
    }

    // ── The two-screen split of one settings row ────────────────────────

    public function testPinningTheAccountLeavesAManagersDepositRulesAlone(): void
    {
        // Unit staff own the Finance account; the asset's managers own the
        // deposit, the balance and the security deposit. Each screen reads
        // the other's half back, so neither can blank work it never showed.
        $this->service->saveManagedSettings($this->assetId, new PaymentSettings(
            enabled: false,
            depositMode: DepositMode::PERCENTAGE,
            depositPercentage: 30,
            depositDueDays: 14,
            balanceDueDays: 30,
            securityDepositEnabled: true,
            securityDepositAmountCents: 50000
        ));

        $this->service->saveFinanceAccount($this->assetId, $this->accountId, $this->categoryId);

        $settings = $this->service->settingsFor($this->assetId);
        $this->assertSame($this->accountId, $settings->financeAccountId);
        $this->assertSame(30, $settings->depositPercentage);
        $this->assertSame(14, $settings->depositDueDays);
        $this->assertSame(30, $settings->balanceDueDays);
        $this->assertTrue($settings->securityDepositEnabled);
        $this->assertSame(50000, $settings->securityDepositAmountCents);
    }

    /**
     * Every receivable names its category (issue #838): pinning an account
     * without one is refused while unit staff can still fix it, not when a
     * renter is confirmed.
     */
    public function testAnAccountWithoutACategoryIsRefused(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/catégorie Finances/');

        $this->service->saveFinanceAccount($this->assetId, $this->accountId, null);
    }

    public function testTheRentalReceivableIsBookedUnderTheAssetsCategory(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 20000);

        $this->service->ensureReceivable($booking, $settings);

        // One receivable, the security deposit inside it.
        $categories = $this->pdo->query('SELECT category_id FROM finance_expected_receivables')
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([$this->categoryId], array_map('intval', $categories));
    }

    public function testAManagerSavingThePaymentsBlockCannotClearTheAccount(): void
    {
        // The manager's form has no account field at all — it is never
        // rendered for them, because the picker carries the unit's IBANs.
        // What matters here is that its absence cannot silently unset it.
        $this->service->saveFinanceAccount($this->assetId, $this->accountId, $this->categoryId);

        $this->service->saveManagedSettings($this->assetId, new PaymentSettings(
            enabled: true,
            depositMode: DepositMode::FIXED,
            depositAmountCents: 10000
        ));

        $settings = $this->service->settingsFor($this->assetId);
        $this->assertSame($this->accountId, $settings->financeAccountId);
        $this->assertTrue($settings->enabled);
        $this->assertSame(10000, $settings->depositAmountCents);
    }

    public function testAManagerCannotSwitchPaymentsOnBeforeAnAccountIsPinned(): void
    {
        // Half-configured is worse than off, and the message says who can
        // actually fix it — the manager reading it usually cannot.
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/chef d.unité/');

        $this->service->saveManagedSettings($this->assetId, new PaymentSettings(enabled: true));
    }

    public function testWithFinanceAccountChangesNothingElse(): void
    {
        $settings = new PaymentSettings(
            enabled: true,
            financeAccountId: 1,
            depositMode: DepositMode::PERCENTAGE,
            depositPercentage: 25,
            depositDueDays: 7,
            balanceDueDays: 21,
            securityDepositEnabled: true,
            securityDepositAmountCents: 30000
        );

        $moved = $settings->withFinanceAccount(9, 4);

        $this->assertSame(9, $moved->financeAccountId);
        $this->assertSame(4, $moved->financeCategoryId);
        $this->assertSame(1, $settings->financeAccountId, 'The original value object is immutable.');
        foreach ([
            'enabled', 'depositMode', 'depositAmountCents', 'depositPercentage',
            'depositDueDays', 'balanceDueDays', 'securityDepositEnabled',
            'securityDepositAmountCents',
        ] as $property) {
            $this->assertSame($settings->$property, $moved->$property, $property . ' must be carried across.');
        }
    }
}
