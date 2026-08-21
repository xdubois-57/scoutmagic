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
use Modules\Rental\Repository\RentalBookingEventRepository;
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
 * matters is the behaviour at the seam: that one receivable serves both the
 * deposit and the balance, that the security deposit is a genuinely separate
 * debt with a separate communication, and — the point of the whole nullable
 * dependency — that every one of these paths degrades quietly when Finance
 * is not there at all.
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
    private RentalBookingEventRepository $eventRepository;
    private TransactionRepository $transactionRepository;
    private RentalPaymentService $service;
    private int $assetId;
    private int $accountId;
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
        $this->eventRepository = new RentalBookingEventRepository($this->pdo);

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
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactionRepository = new TransactionRepository($this->pdo, $this->encryption);

        $this->service = new RentalPaymentService(
            $this->paymentRepository,
            $this->eventRepository,
            $journal,
            new ExpectedReceivableService($receivableRepository, $this->transactionRepository),
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
            true,
            false
        );
    }

    /** The same service with every Finance dependency absent. */
    private function withoutFinance(): RentalPaymentService
    {
        return new RentalPaymentService(
            $this->paymentRepository,
            $this->eventRepository,
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
            depositMode: $depositMode,
            depositAmountCents: $depositCents,
            depositPercentage: $depositPercentage,
            depositDueDays: 14,
            balanceDueDays: 30,
            securityDepositEnabled: $securityDeposit,
            securityDepositAmountCents: $securityDepositCents,
            securityDepositDueDays: 30
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

    private function createBooking(int $totalCents = 46750, string $reference = 'LOC-2027-0001'): RentalBooking
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

        $raised = $service->ensureReceivables($booking, $this->settings(), $this->now());

        $this->assertNull($raised['rental']);
        $this->assertNull($raised['security_deposit']);
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
        $this->assertNull($service->securityDepositQrPng($booking, $this->settings()));
    }

    public function testAnAssetWithPaymentsOffRaisesNothingEvenWithFinanceOn(): void
    {
        $raised = $this->service->ensureReceivables(
            $this->createBooking(),
            $this->settings(enabled: false),
            $this->now()
        );

        $this->assertNull($raised['rental']);
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

    public function testConfirmationRaisesExactlyOneRentalReceivable(): void
    {
        $booking = $this->createBooking(46750);

        $this->service->ensureReceivables($booking, $this->settings(), $this->now());

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

        $this->service->ensureReceivables($booking, $settings, $this->now());

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
        $this->service->ensureReceivables($booking, $settings, $this->now());

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
        $this->service->ensureReceivables($booking, $settings, $this->now());

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
        $this->service->ensureReceivables($booking, $settings, $this->now());
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

        $first = $this->service->ensureReceivables($booking, $settings, $this->now());
        $communication = (string) $this->service->statusFor($booking, $settings)['communication'];
        $this->pay($communication, 200.00);

        $second = $this->service->ensureReceivables($this->reload($booking), $settings, $this->now());

        $this->assertSame($first['rental'], $second['rental']);
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

        $this->service->ensureReceivables($booking, $this->settings(), $this->now());
    }

    // ── Amount updates (§6.12) ──────────────────────────────────────────

    public function testANegotiatedPriceMovesTheReceivableInPlace(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::PERCENTAGE, depositPercentage: 30);
        $this->service->ensureReceivables($booking, $settings, $this->now());

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
        $this->service->ensureReceivables($booking, $settings, $this->now());
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
        $this->service->ensureReceivables($booking, $settings, $this->now());
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

    // ── The security deposit (§6.20) ────────────────────────────────────

    public function testTheSecurityDepositIsItsOwnReceivableWithItsOwnCommunication(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);

        $raised = $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->assertNotNull($raised['security_deposit']);
        $this->assertNotSame($raised['rental'], $raised['security_deposit']);
        $this->assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());

        $status = $this->service->statusFor($booking, $settings);
        // Separable on the account: a renter transferring the security
        // deposit must not accidentally settle part of the rental.
        $this->assertNotSame($status['communication'], $status['security_deposit']['communication']);
    }

    public function testPayingTheSecurityDepositNeverCountsTowardsTheRental(): void
    {
        // A unit holding 500 € of somebody else's money has not earned it.
        $booking = $this->createBooking(46750);
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $status = $this->service->statusFor($booking, $settings);
        $this->pay((string) $status['security_deposit']['communication'], 500.00);

        $after = $this->service->statusFor($booking, $settings);
        $this->assertSame(50000, $after['security_deposit']['received_cents']);
        $this->assertSame(0, $after['received_cents']);
        $this->assertFalse($after['fully_paid']);
    }

    public function testAnAssetWithoutASecurityDepositRaisesOnlyTheRentalReceivable(): void
    {
        $raised = $this->service->ensureReceivables($this->createBooking(), $this->settings(), $this->now());

        $this->assertNull($raised['security_deposit']);
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testTheSecurityDepositStartsAsToReceive(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->assertSame(
            SecurityDepositStatus::TO_RECEIVE,
            $this->service->statusFor($booking, $settings)['security_deposit']['status']
        );
    }

    public function testAFullReturnIsRecordedByHandAndLandsOnReturned(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->service->recordSecurityDepositReturn($booking, 50000, null, new \DateTimeImmutable('2027-07-10'), 1);

        $status = $this->service->statusFor($booking, $settings)['security_deposit'];
        $this->assertSame(SecurityDepositStatus::RETURNED, $status['status']);
        $this->assertSame(50000, $status['returned_cents']);
        $this->assertSame(0, $status['withheld_cents']);
        $this->assertSame('2027-07-10', $status['returned_at']);
    }

    public function testAPartialReturnNeedsAWrittenReason(): void
    {
        // Withholding somebody's money without a written reason is not
        // something to allow quietly.
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/retenue/');

        $this->service->recordSecurityDepositReturn($booking, 30000, null, new \DateTimeImmutable('2027-07-10'), 1);
    }

    public function testAPartialReturnWithAReasonLandsOnPartiallyWithheld(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->service->recordSecurityDepositReturn(
            $booking,
            30000,
            'Vitre cassée dans le réfectoire.',
            new \DateTimeImmutable('2027-07-10'),
            1
        );

        $status = $this->service->statusFor($booking, $settings)['security_deposit'];
        $this->assertSame(SecurityDepositStatus::PARTIALLY_WITHHELD, $status['status']);
        $this->assertSame(20000, $status['withheld_cents']);
        $this->assertSame('Vitre cassée dans le réfectoire.', $status['note']);
    }

    public function testTheWithholdingReasonIsStoredEncrypted(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->service->recordSecurityDepositReturn(
            $booking,
            0,
            'Le groupe a laissé le local dans un état déplorable.',
            new \DateTimeImmutable('2027-07-10'),
            1
        );

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('état déplorable', $raw);
    }

    public function testTheWithholdingReasonNeverReachesTheJournal(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->service->recordSecurityDepositReturn(
            $booking,
            0,
            'Le groupe a laissé le local dans un état déplorable.',
            new \DateTimeImmutable('2027-07-10'),
            1
        );

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('LOC-2027-0001', $journal);
        $this->assertStringNotContainsString('état déplorable', $journal);
        $this->assertStringNotContainsString('Jeanne Martin', $journal);
    }

    public function testReturningMoreThanTheDepositIsRefused(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->expectException(RentalException::class);

        $this->service->recordSecurityDepositReturn($booking, 60000, null, new \DateTimeImmutable('2027-07-10'), 1);
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
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->service->markSecurityDepositToReturn($booking, 1);

        $this->assertSame(
            SecurityDepositStatus::TO_RETURN,
            $this->service->statusFor($booking, $settings)['security_deposit']['status']
        );
        $this->assertNotSame([], $this->eventRepository->findForBooking($booking->id));
    }

    // ── SEPA QR (§6.19) ─────────────────────────────────────────────────

    public function testTheQrAsksForTheDepositWhileItIsOutstanding(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings(depositMode: DepositMode::FIXED, depositCents: 10000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $png = $this->service->sepaQrPng($booking, $settings);

        $this->assertIsString($png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function testNoQrIsOfferedOnceEverythingIsPaid(): void
    {
        $booking = $this->createBooking(46750);
        $settings = $this->settings();
        $this->service->ensureReceivables($booking, $settings, $this->now());
        $this->pay((string) $this->service->statusFor($booking, $settings)['communication'], 467.50);

        $this->assertNull($this->service->sepaQrPng($booking, $settings));
    }

    public function testNoQrIsOfferedWithoutAnIbanOnTheAccount(): void
    {
        $this->pdo->exec('UPDATE finance_accounts SET iban = NULL, iban_blind_index = NULL');
        $booking = $this->createBooking();
        $settings = $this->settings();
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->assertNull($this->service->sepaQrPng($booking, $settings));
    }

    public function testTheSecurityDepositHasItsOwnQr(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());

        $this->assertIsString($this->service->securityDepositQrPng($booking, $settings));
    }

    public function testNoSecurityDepositQrOnceItHasArrived(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(securityDeposit: true, securityDepositCents: 50000);
        $this->service->ensureReceivables($booking, $settings, $this->now());
        $status = $this->service->statusFor($booking, $settings);
        $this->pay((string) $status['security_deposit']['communication'], 500.00);

        $this->assertNull($this->service->securityDepositQrPng($booking, $settings));
    }

    // ── Deleting a booking ──────────────────────────────────────────────

    public function testForgettingABookingDropsItsReceivables(): void
    {
        // Finance must not be left expecting money for a rental that no
        // longer exists.
        $booking = $this->createBooking();
        $this->service->ensureReceivables(
            $booking,
            $this->settings(securityDeposit: true, securityDepositCents: 50000),
            $this->now()
        );

        $this->service->forgetBooking($booking->id);

        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_expected_receivables'
        )->fetchColumn());
    }

    public function testForgettingABookingIsSafeWithoutFinance(): void
    {
        $this->withoutFinance()->forgetBooking(1);

        $this->assertTrue(true);
    }

    // ── Due dates ───────────────────────────────────────────────────────

    public function testDueDatesAreCountedFromWhenTheReceivableIsRaised(): void
    {
        $booking = $this->createBooking();
        $settings = $this->settings(depositMode: DepositMode::FIXED, depositCents: 10000);

        $this->service->ensureReceivables($booking, $settings, new \DateTimeImmutable('2027-02-01'));

        $status = $this->service->statusFor($booking, $settings);
        $this->assertSame('2027-02-15', $status['deposit_due_date']);
        $this->assertSame('2027-03-03', $status['balance_due_date']);
    }
}
