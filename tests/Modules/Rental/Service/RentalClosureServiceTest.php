<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalClosureService;
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
 * The guarded closure and « Clôturer avec un solde non recouvré » (#980,
 * CHANTIER-locations-incidents.md §2.9), against the real Finance services on SQLite — the
 * receivable that is brought down, and the payment that arrives after, are
 * Finance's own figures, not a double's.
 *
 * The running example: 668 € of rental and a 150 € deposit, 818 € paid,
 * 800 € of damage charged — the receivable is at 1 468 € and 650 € are
 * still due.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalClosureServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private TransactionRepository $transactionRepository;
    private RentalPaymentService $payments;
    private RentalStayService $stay;
    private RentalClosureService $closure;
    private RentalOperationsService $operations;
    private BookingAudit $bookingAudit;
    private JournalService $journal;
    private RentalPricingService $pricingService;
    private int $assetId;
    private int $accountId;
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
        $categoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $receivableRepository = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactionRepository = new TransactionRepository($this->pdo, $this->encryption);

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $this->journal
        );
        $this->wire(new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            $this->journal,
            FinanceTestHelper::receivableService($this->pdo, $this->encryption, $receivableRepository),
            new StructuredCommunicationService($receivableRepository),
            new SepaQrCodeService(),
            new FinanceAccountService($accountRepository)
        ));

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
            financeCategoryId: $categoryId
        ));
    }

    /** The services around $payments, wired as public/index.php does. */
    private function wire(RentalPaymentService $payments): void
    {
        $this->payments = $payments;
        $stayRepository = new RentalStayRepository($this->pdo, $this->encryption);
        $this->stay = new RentalStayService(
            $stayRepository,
            $this->bookingAudit,
            $this->pricingService,
            new SettlementCalculator(),
            $this->journal,
            $payments
        );
        $this->closure = new RentalClosureService(
            $this->bookingRepository,
            $stayRepository,
            $this->stay,
            new RentalModificationRepository($this->pdo),
            $this->bookingAudit,
            $this->journal,
            $payments
        );
        $this->operations = new RentalOperationsService(
            $this->bookingRepository,
            $this->bookingAudit,
            new RentalBookingCommentRepository($this->pdo, $this->encryption),
            new RentalModificationRepository($this->pdo),
            new RentalAvailabilityService(new AvailabilityCalculator(), new RentalConstraintsRepository($this->pdo)),
            $this->pricingService,
            $this->journal,
            $payments,
            $this->stay,
            $this->closure
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /** A confirmed booking of 668 € with its receivable raised (818 € with the deposit). */
    private function confirmedBooking(): RentalBooking
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
        $this->bookingRepository->setStatus($created['id'], BookingStatus::CONFIRMED, new \DateTimeImmutable());
        $booking = $this->reload($created['id']);
        $this->payments->communicationForContract($booking, $this->settings());

        return $this->reload($booking->id);
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

    /** A transfer carrying the booking's communication, as an import records it. */
    private function pay(RentalBooking $booking, float $amount, string $date = '2027-03-05'): void
    {
        $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            $date,
            'Virement ' . (string) $this->moneyOf($booking)['communication'],
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    /** @return array<string, mixed> */
    private function moneyOf(RentalBooking $booking): array
    {
        return $this->payments->statusFor($this->reload($booking->id), $this->settings());
    }

    /** $chargedCents of damage decided, a settlement recorded — and validated unless told otherwise. */
    private function settleWithDamage(RentalBooking $booking, int $chargedCents, bool $validate = true): void
    {
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Châssis brisé', $chargedCents, null, 1);
        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::CHARGE, null, 1);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        if ($validate) {
            $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
        }
    }

    /** The running example: 818 € in, 800 € of damage, 650 € still due. */
    private function bookingWithABalanceDue(): RentalBooking
    {
        $booking = $this->confirmedBooking();
        $this->pay($booking, 818.00);
        $this->settleWithDamage($booking, 80000);
        $this->assertSame(65000, $this->moneyOf($booking)['remaining_cents']);

        return $this->reload($booking->id);
    }

    private function close(RentalBooking $booking): void
    {
        $this->operations->changeStatus(
            $this->reload($booking->id),
            BookingStatus::CLOSED,
            1,
            new \DateTimeImmutable()
        );
    }

    private function assertClosingIsRefused(RentalBooking $booking, string $expected): void
    {
        try {
            $this->close($booking);
            $this->fail('the booking was closed');
        } catch (RentalException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking->id)->status);
    }

    // ── The ordinary closure, guarded ───────────────────────────────────

    public function testADamageStillAssessedRefusesTheClosure(): void
    {
        $booking = $this->confirmedBooking();
        $this->pay($booking, 818.00);
        $this->stay->reportIncident($this->reload($booking->id), 'Vitre fêlée', 12000, null, 1);

        $this->assertClosingIsRefused($booking, "tranchez l'incident sur la page État des lieux");
        $this->assertFalse($this->closure->check($this->reload($booking->id))->offersUnrecovered());
    }

    public function testADepositStillHeldBeforeTheSettlementRefusesTheClosure(): void
    {
        // Everything paid and nothing settled: the deposit is in hand, and
        // what goes back is decided by the settlement.
        $booking = $this->confirmedBooking();
        $this->pay($booking, 818.00);

        $this->assertClosingIsRefused($booking, 'validez le décompte final sur la page Facture');
    }

    public function testADepositStillToGiveBackRefusesTheClosure(): void
    {
        // Settled without damage: the 150 € are owed back.
        $booking = $this->confirmedBooking();
        $this->pay($booking, 818.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);

        $this->assertClosingIsRefused($booking, 'La caution reste à rendre');
    }

    public function testMoneyOwedBackWithoutADepositHeldRefusesTheClosure(): void
    {
        // No deposit on this hall, and 50 € paid twice: nothing is held,
        // and the renter is still owed 50 € once the stay is settled.
        $settings = $this->payments->settingsFor($this->assetId);
        $this->payments->saveSettings($this->assetId, new PaymentSettings(
            enabled: true,
            financeAccountId: $this->accountId,
            depositMode: DepositMode::FIXED,
            depositAmountCents: 25000,
            depositDueDays: 15,
            balanceDueDays: 14,
            securityDepositEnabled: false,
            securityDepositAmountCents: null,
            financeCategoryId: $settings->financeCategoryId
        ));
        $booking = $this->confirmedBooking();
        $this->pay($booking, 718.00);
        $settlement = $this->stay->recordSettlement($this->reload($booking->id), $this->assetId, null, [], 1);
        $this->stay->validateSettlement($this->reload($booking->id), $settlement->id, 1);
        $this->assertSame(SecurityDepositStatus::NONE, $this->moneyOf($booking)['security_deposit']['status']);
        $this->assertSame(5000, $this->moneyOf($booking)['overpaid_cents']);

        $this->assertClosingIsRefused($booking, '50,00 € sont à rembourser au locataire');
        $this->assertFalse($this->closure->check($this->reload($booking->id))->offersUnrecovered());
    }

    /**
     * The figure written off is the validated settlement's, the sentence
     * the receivable's: when the receivable was moved since, they part,
     * and nothing is offered — on the page or under the lock.
     */
    public function testAReceivableThatNoLongerMatchesTheSettlementIsNotWrittenOff(): void
    {
        $booking = $this->bookingWithABalanceDue();
        $this->payments->settleAmount($this->reload($booking->id), 140000);
        $this->assertSame(58200, $this->moneyOf($booking)['remaining_cents']);

        $check = $this->closure->check($this->reload($booking->id));
        $this->assertFalse($check->offersUnrecovered());
        $this->assertStringContainsString('582,00 € restent dus', (string) $check->refusal);
        $this->assertStringContainsString(RentalClosureService::REFUSED_RECEIVABLE_DIFFERS, (string) $check->refusal);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage(RentalClosureService::REFUSED_RECEIVABLE_DIFFERS);
        $this->closure->closeUnrecovered($this->reload($booking->id), 65000, 'Locataire injoignable', 1, new \DateTimeImmutable());
    }

    public function testAnAmountStillDuePointsToTheUnrecoveredClosure(): void
    {
        $booking = $this->bookingWithABalanceDue();

        $this->assertClosingIsRefused($booking, '650,00 € restent dus');
        $check = $this->closure->check($this->reload($booking->id));
        $this->assertStringContainsString('« Clôturer avec un solde non recouvré »', (string) $check->refusal);
        $this->assertTrue($check->offersUnrecovered());
        $this->assertSame(65000, $check->unrecoveredCents);
    }

    public function testBeforeAValidatedSettlementTheUnrecoveredClosureIsNotOffered(): void
    {
        // Nothing paid at all: 818 € due, and no settlement to write off from.
        $booking = $this->confirmedBooking();

        $check = $this->closure->check($booking);
        $this->assertFalse($check->offersUnrecovered());
        $this->assertStringContainsString('validez d\'abord le décompte final', (string) $check->refusal);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('Validez d\'abord le décompte final');
        $this->closure->closeUnrecovered($booking, 81800, 'Locataire injoignable', 1, new \DateTimeImmutable());
    }

    public function testASettledBookingCloses(): void
    {
        // 150 € of damage on a 150 € deposit: nothing due, nothing owed back.
        $booking = $this->confirmedBooking();
        $this->pay($booking, 818.00);
        $this->settleWithDamage($booking, 15000);

        $this->assertTrue($this->closure->check($this->reload($booking->id))->isClosable());
        $this->close($booking);

        $this->assertSame(BookingStatus::CLOSED, $this->reload($booking->id)->status);
    }

    // ── Without Finances (Q19) ──────────────────────────────────────────

    public function testWithoutFinancesOnlyTheDamageAndTheDepositRecordedByHandCount(): void
    {
        $this->wire(new RentalPaymentService(
            new RentalPaymentRepository($this->pdo, $this->encryption),
            $this->bookingAudit,
            $this->journal
        ));
        $booking = $this->confirmedBooking();

        // Nothing paid that the site can see, which is not a refusal; the
        // deposit's return not recorded is.
        $this->assertClosingIsRefused($booking, "La restitution de la caution n'est pas enregistrée");

        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Vitre fêlée', 12000, null, 1);
        $this->payments->recordSecurityDepositReturn(
            $this->reload($booking->id),
            15000,
            null,
            new \DateTimeImmutable('2027-03-20'),
            1,
            $this->settings()
        );
        $this->assertClosingIsRefused($booking, "Un dégât est en cours d'évaluation");

        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::WAIVE, null, 1);
        $this->assertFalse($this->closure->check($this->reload($booking->id))->offersUnrecovered());
        $this->close($booking);

        $this->assertSame(BookingStatus::CLOSED, $this->reload($booking->id)->status);
    }

    // ── « Clôturer avec un solde non recouvré » ─────────────────────────

    public function testTheReasonIsRequired(): void
    {
        $booking = $this->bookingWithABalanceDue();

        foreach (['', '   ', null] as $reason) {
            try {
                $this->closure->closeUnrecovered($booking, 65000, $reason, 1, new \DateTimeImmutable());
                $this->fail('closed without a reason');
            } catch (RentalException $e) {
                $this->assertStringContainsString('Indiquez pourquoi', $e->getMessage());
            }
        }

        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking->id)->status);
        $this->assertCount(1, $this->stay->settlementsFor($booking->id));
        $this->assertSame(65000, $this->moneyOf($booking)['remaining_cents']);
    }

    public function testAReasonTooLongIsRefused(): void
    {
        $booking = $this->bookingWithABalanceDue();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('500 caractères');
        $this->closure->closeUnrecovered(
            $booking,
            65000,
            str_repeat('x', RentalClosureService::REASON_MAX + 1),
            1,
            new \DateTimeImmutable()
        );
    }

    public function testTheUnrecoveredClosureWritesTheBalanceOffAndCloses(): void
    {
        $booking = $this->bookingWithABalanceDue();
        $before = $this->stay->latestSettlement($booking->id);
        $this->assertNotNull($before);

        $settlement = $this->closure->closeUnrecovered(
            $booking,
            65000,
            'Assurance refusée',
            1,
            new \DateTimeImmutable('2027-05-02 10:00')
        );

        // The receivable is brought down to what came in: nothing due,
        // nothing owed back, the deposit kept.
        $status = $this->moneyOf($booking);
        $this->assertSame(81800, $status['total_cents']);
        $this->assertSame(0, $status['remaining_cents']);
        $this->assertSame(0, $status['overpaid_cents']);
        $this->assertSame(SecurityDepositStatus::FULLY_WITHHELD, $status['security_deposit']['status']);

        // A new validated version: the last validated one's lines, plus the
        // line written off, with its date and its reason.
        $this->assertSame($before->version + 1, $settlement->version);
        $this->assertTrue($settlement->isValidated);
        $this->assertSame(
            array_map(static fn(SettlementLine $l): string => $l->label, $before->lines),
            array_map(static fn(SettlementLine $l): string => $l->label, array_slice($settlement->lines, 0, -1))
        );
        $line = $settlement->linesOfOrigin(SettlementLine::ORIGIN_UNRECOVERED)[0] ?? null;
        $this->assertNotNull($line);
        $this->assertSame('Montant non recouvré', $line->label);
        $this->assertSame(-65000, $line->amountCents);
        $this->assertSame('Le 02/05/2027', $line->detail);
        $this->assertSame($before->totalCents - 65000, $settlement->totalCents);
        $this->assertSame(81800, $settlement->keptCents());
        $this->assertSame(0, $settlement->balanceCents);
        $this->assertSame(65000, $settlement->unrecoveredCents);
        $this->assertSame('Assurance refusée', $settlement->unrecoveredReason);
        $this->assertSame('2027-05-02', $settlement->unrecoveredAt?->format('Y-m-d'));
        $this->assertSame(15000, $settlement->securityDepositWithheldCents);
        $this->assertSame(0, $settlement->securityDepositReturnCents);
        $this->assertFalse($settlement->isOutdatedFor($this->stay->incidentBasisHash($booking->id)));

        // Closed, with the history saying who, when and why.
        $this->assertSame(BookingStatus::CLOSED, $this->reload($booking->id)->status);
        $summaries = array_column($this->historyOf($booking), 'summary');
        $this->assertContains('Clôturée avec un solde non recouvré de 650,00 € : Assurance refusée', $summaries);
    }

    public function testTheReasonIsEncryptedAndNeverJournaled(): void
    {
        $booking = $this->bookingWithABalanceDue();
        $this->closure->closeUnrecovered($booking, 65000, 'Locataire injoignable', 1, new \DateTimeImmutable());

        $raw = (string) $this->pdo->query(
            'SELECT unrecovered_reason_encrypted FROM rental_settlements WHERE unrecovered_cents IS NOT NULL'
        )->fetchColumn();
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('injoignable', $raw);

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('rental_booking_closed_unrecovered', $journal);
        $this->assertStringNotContainsString('injoignable', $journal);
        $this->assertStringNotContainsString('Jeanne', $journal);
    }

    public function testAPaymentArrivingAfterReadsAsAnOverpayment(): void
    {
        $booking = $this->bookingWithABalanceDue();
        $this->closure->closeUnrecovered($booking, 65000, 'Locataire injoignable', 1, new \DateTimeImmutable());

        $this->pay($booking, 100.00, '2027-06-01');

        $status = $this->moneyOf($booking);
        $this->assertSame(81800, $status['total_cents']);
        $this->assertSame(10000, $status['overpaid_cents']);
        $this->assertSame(0, $status['remaining_cents']);
    }

    public function testAnAmountThatMovedSinceTheDialogIsRefused(): void
    {
        // 50 € arrived while the dialog was open: 600 € are due, not 650 €.
        $booking = $this->bookingWithABalanceDue();
        $this->pay($booking, 50.00, '2027-05-01');

        try {
            $this->closure->closeUnrecovered($booking, 65000, 'Assurance refusée', 1, new \DateTimeImmutable());
            $this->fail('closed on a figure that moved');
        } catch (RentalException $e) {
            $this->assertStringContainsString('600,00 €, et non de 650,00 €', $e->getMessage());
        }

        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking->id)->status);
        $this->assertCount(1, $this->stay->settlementsFor($booking->id));
        $this->assertSame(146800, $this->moneyOf($booking)['total_cents']);
    }

    public function testASecondSubmitIsRefused(): void
    {
        $booking = $this->bookingWithABalanceDue();
        $this->closure->closeUnrecovered($booking, 65000, 'Assurance refusée', 1, new \DateTimeImmutable());

        try {
            $this->closure->closeUnrecovered($booking, 65000, 'Assurance refusée', 1, new \DateTimeImmutable());
            $this->fail('closed twice');
        } catch (RentalException $e) {
            $this->assertStringContainsString('déjà « Clôturée »', $e->getMessage());
        }

        $this->assertCount(2, $this->stay->settlementsFor($booking->id));
    }

    public function testADamageAssessedRefusesTheUnrecoveredClosureToo(): void
    {
        $booking = $this->bookingWithABalanceDue();
        $this->stay->reportIncident($this->reload($booking->id), 'Volet arraché', 5000, null, 1);

        $this->assertFalse($this->closure->check($this->reload($booking->id))->offersUnrecovered());
        $this->expectException(RentalException::class);
        $this->expectExceptionMessage("Un dégât est en cours d'évaluation");
        $this->closure->closeUnrecovered($booking, 65000, 'Assurance refusée', 1, new \DateTimeImmutable());
    }

    public function testASettlementOutdatedByADecisionIsNotWrittenOff(): void
    {
        // A damage decided after the validation: the validated version does
        // not carry it, and writing off from it would forget it.
        $booking = $this->bookingWithABalanceDue();
        $incident = $this->stay->reportIncident($this->reload($booking->id), 'Volet arraché', 5000, null, 1);
        $this->stay->decideIncident($this->reload($booking->id), $incident, IncidentDecision::CHARGE, null, 1);

        $this->assertFalse($this->closure->check($this->reload($booking->id))->offersUnrecovered());
        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('enregistrez et validez-en un nouveau');
        $this->closure->closeUnrecovered($booking, 65000, 'Assurance refusée', 1, new \DateTimeImmutable());
    }

    public function testNothingDueIsNotWrittenOff(): void
    {
        $booking = $this->confirmedBooking();
        $this->pay($booking, 818.00);
        $this->settleWithDamage($booking, 15000);

        $this->assertNull($this->closure->check($this->reload($booking->id))->unrecoveredCents);
        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('Rien ne reste dû');
        $this->closure->closeUnrecovered($booking, 0, 'Assurance refusée', 1, new \DateTimeImmutable());
    }

    /** @return list<array<string, mixed>> */
    private function historyOf(RentalBooking $booking): array
    {
        $audit = new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($this->pdo, $this->encryption));

        return array_map(
            static fn($entry): array => ['summary' => $entry->summary, 'to' => $entry->toValue],
            $audit->page(BookingAudit::ENTITY_TYPE, $booking->id, 1, 50)->entries
        );
    }
}
