<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceAccountService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StatementImportStatusService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * « Rapprocher un virement » end to end on the rental side (#980,
 * chantier §2.6), against the **real** Finance services on SQLite, wired
 * as public/index.php wires them: a transfer that came in without the
 * booking's communication — the renter's insurer — is found among the
 * account's unreconciled credits of that exact amount, attached whole
 * (Q1), and the remaining balance, the overpayment and the booking's
 * steps follow; « Détacher » undoes it, until a settlement validated
 * since has counted it (Q11).
 */
#[Group('database')]
final class RentalTransferReconcileTest extends TestCase
{
    private \PDO $pdo;
    private RentalBookingRepository $bookingRepository;
    private TransactionRepository $transactionRepository;
    private StatementImportRepository $imports;
    private RentalPaymentService $payments;
    private RentalStayService $stay;
    private int $assetId;
    private int $accountId;
    private int $fiscalYearId;
    private \DateTimeImmutable $since;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        FinanceTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $encryption);
        $bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $encryption);

        $accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->accountId = $accountRepository->create(
            'Compte unité',
            'bank',
            null,
            'BE68539007547034',
            'Unité Saint-Georges',
            'intendant'
        );
        $this->pdo->exec("UPDATE finance_accounts SET status = 'active'");
        $categoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->imports = new StatementImportRepository($this->pdo);

        $this->payments = new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $encryption),
            $bookingAudit,
            $journal,
            FinanceTestHelper::receivableService($this->pdo, $encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository),
            new SepaQrCodeService(),
            new FinanceAccountService($accountRepository),
            new StatementImportStatusService($this->imports)
        );
        $this->stay = new RentalStayService(
            new RentalStayRepository($this->pdo, $encryption),
            $bookingAudit,
            new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal),
            new SettlementCalculator(),
            $journal,
            $this->payments
        );

        $this->assetId = (new RentalAssetRepository($this->pdo, $encryption))->create(
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
            financeCategoryId: $categoryId
        ));
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /** 668 € of rental and a 150 € deposit on one receivable, its communication returned. */
    private function bookingWithReceivable(): array
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
                'organisation' => null,
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
        $booking = $this->reload($created['id']);
        $communication = $this->payments->communicationForContract($booking, $this->settings());
        $this->assertNotNull($communication);
        $this->since = $this->payments->transferSince($booking);

        return [$booking, $communication];
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

    private function credit(string $date, float $amount, string $label, ?string $counterparty): int
    {
        return $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            $date,
            $label,
            $amount,
            null,
            null,
            'import',
            null,
            $counterparty
        );
    }

    /** @return list<\Modules\Finance\Api\PaymentCandidate> */
    private function candidatesOf(RentalBooking $booking): array
    {
        return $this->payments->paymentCandidates($this->reload($booking->id), 56800, null, $this->since);
    }

    /** @return array<string, mixed> */
    private function moneyOf(RentalBooking $booking): array
    {
        return $this->payments->statusFor($this->reload($booking->id), $this->settings());
    }

    private function balanceReceived(RentalBooking $booking): bool
    {
        $evidence = MilestoneEvidence::collect($this->reload($booking->id), null, $this->moneyOf($booking), null, null);

        return $evidence->done[BookingMilestones::BALANCE_RECEIVED] ?? false;
    }

    // ── Reconcile, then detach ──────────────────────────────────────────

    public function testTheInsurersTransferPaysTheBalanceAndDetachingPutsItBack(): void
    {
        [$booking, $communication] = $this->bookingWithReceivable();
        $this->credit('2027-02-01', 250.00, 'Acompte ' . $communication, 'Jeanne Martin');
        $insurer = $this->credit('2027-03-14', 568.00, 'Sinistre 2027/118', 'ÉTHIAS SA');
        // Not candidates: another amount, and the same amount from somebody else.
        $this->credit('2027-03-14', 568.01, 'Presque', 'ETHIAS SA');
        $other = $this->credit('2027-03-15', 568.00, 'Cotisation', 'J. LAMBERT');

        $this->assertSame(56800, $this->moneyOf($booking)['remaining_cents']);
        $this->assertFalse($this->balanceReceived($booking));

        $candidates = $this->payments->paymentCandidates($this->reload($booking->id), 56800, 'ethias', $this->since);
        $this->assertCount(1, $candidates);
        $this->assertSame($insurer, $candidates[0]->transactionId);
        $this->assertSame('Sinistre 2027/118', $candidates[0]->label);
        $this->assertCount(2, $this->candidatesOf($booking));

        $this->payments->reconcilePayment($this->reload($booking->id), $insurer, 56800, $this->since, 7, 1);

        $status = $this->moneyOf($booking);
        $this->assertSame(0, $status['remaining_cents']);
        $this->assertTrue($status['fully_paid']);
        $this->assertTrue($this->balanceReceived($booking));
        $movements = $this->payments->movementsFor($this->reload($booking->id));
        $this->assertCount(2, $movements);
        $manual = $movements[1];
        $this->assertSame($insurer, $manual->transactionId);
        $this->assertFalse($manual->automatic);
        $this->assertSame(7, $manual->reconciledBy);
        $this->assertNotNull($manual->reconciledAt);
        $this->assertTrue(RentalPaymentService::canDetach($manual, null));
        $this->assertFalse(RentalPaymentService::canDetach($movements[0], null));
        // The one picked is no candidate any more; the other still is.
        $left = $this->candidatesOf($booking);
        $this->assertSame([$other], array_map(static fn($c) => $c->transactionId, $left));

        $this->payments->detachPayment($this->reload($booking->id), $insurer, null, 1);

        $this->assertSame(56800, $this->moneyOf($booking)['remaining_cents']);
        $this->assertFalse($this->balanceReceived($booking));
        $this->assertCount(1, $this->payments->movementsFor($this->reload($booking->id)));
        $this->assertCount(2, $this->candidatesOf($booking));
        // Twice is refused, and changes nothing.
        try {
            $this->payments->detachPayment($this->reload($booking->id), $insurer, null, 1);
            $this->fail('A transfer is detached once.');
        } catch (RentalException) {
        }
        $this->assertSame(56800, $this->moneyOf($booking)['remaining_cents']);
        $this->assertSame(2, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM entity_changes WHERE field_key = '" . BookingAudit::PAYMENT_RECORDED . "'"
        )->fetchColumn());
    }

    public function testACreditBeforeTheSearchStartsIsNoCandidate(): void
    {
        [$booking] = $this->bookingWithReceivable();
        $early = $this->credit('2027-01-05', 568.00, 'Avant la demande', 'ETHIAS SA');

        $this->assertSame([], $this->candidatesOf($booking));

        $this->expectException(RentalException::class);
        $this->payments->reconcilePayment($this->reload($booking->id), $early, 56800, $this->since, 7);
    }

    // ── Beyond what is due (Q1), then a settlement (Q11) ────────────────

    public function testTheWholeTransferIsAttachedBeyondTheDueAndTheSettlementTakesItIn(): void
    {
        [$booking, $communication] = $this->bookingWithReceivable();
        $this->credit('2027-02-01', 818.00, 'Location ' . $communication, 'Jeanne Martin');
        $insurer = $this->credit('2027-03-20', 1000.00, 'Sinistre 2027/118', 'ETHIAS SA');

        $this->payments->reconcilePayment($this->reload($booking->id), $insurer, 100000, $this->since, 7);

        // Everything was already paid: the whole 1 000 € reads as overpaid.
        $status = $this->moneyOf($booking);
        $this->assertSame(181800, $status['received_cents']);
        $this->assertSame(100000, $status['overpaid_cents']);

        // The damage it paid, charged and settled: the overpayment is the
        // deposit owed back, nothing more.
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Châssis brisé', 100000, [], 1);
        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::CHARGE, 100000, 1);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        // The reconciliation is dated before the validation, as in life.
        $this->pdo->exec("UPDATE finance_receivable_allocations SET created_at = '2026-01-05 09:00:00'
            WHERE transaction_id = {$insurer}");
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $this->assertSame(15000, $this->moneyOf($booking)['overpaid_cents']);
        $validated = $this->stay->latestSettlement($booking->id);
        $this->assertNotNull($validated?->validatedAt);
        $manual = $this->payments->movementsFor($this->reload($booking->id))[1];
        $this->assertFalse(RentalPaymentService::canDetach($manual, $validated->validatedAt));

        try {
            $this->payments->detachPayment($this->reload($booking->id), $insurer, $validated->validatedAt, 1);
            $this->fail('A transfer a validated settlement counted stays attached.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('tient compte de ce virement', $e->getMessage());
        }
        $this->assertSame(181800, $this->moneyOf($booking)['received_cents']);
    }

    // ── How far the account is known ────────────────────────────────────

    public function testTheLastImportOfTheAssetsAccountIsRead(): void
    {
        $this->assertNull($this->payments->lastStatementImportedAt($this->settings()));

        $this->imports->create($this->accountId, 'belfius', 'extrait.csv', 3, 3, 0, null, '2027-03-21 18:30:00');

        $this->assertSame(
            '2027-03-21 18:30:00',
            $this->payments->lastStatementImportedAt($this->settings())?->format('Y-m-d H:i:s')
        );
    }
}
