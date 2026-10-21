<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\PaymentCandidate;
use Modules\Finance\Api\ReceivableMovement;
use Modules\Finance\Api\StatementImportStatusInterface;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPaymentService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * « Rapprocher un virement » on the rental side (#980, chantier §2.6),
 * against a double of Finance's Api: what RentalPaymentService hands
 * Finance, what it refuses before asking, what it writes in the journal
 * (ids and counts, never a name or an amount), the « since » of Q10, the
 * « Détacher » rule of Q11, and — the point of the nullable dependency —
 * that every path says so plainly without Finance.
 *
 * The same flow against the real Finance services is
 * RentalTransferReconcileTest.
 */
#[Group('database')]
#[AllowMockObjectsWithoutExpectations]
final class RentalPaymentTransferTest extends TestCase
{
    private const RECEIVABLE_ID = 42;

    private \PDO $pdo;
    private RentalPaymentRepository $paymentRepository;
    private JournalService $journal;
    private ExpectedReceivableInterface&MockObject $receivables;
    private RentalPaymentService $service;
    private RentalBooking $booking;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->paymentRepository = new RentalPaymentRepository($this->pdo, $encryption);
        $this->receivables = $this->createMock(ExpectedReceivableInterface::class);
        $this->service = $this->serviceWith($this->receivables);

        $assetId = (new RentalAssetRepository($this->pdo, $encryption))->create(
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
        $bookings = new RentalBookingRepository($this->pdo, $encryption);
        $created = $bookings->create(
            $assetId,
            'LOC-A2B3C4',
            '2027-03-12',
            '2027-03-15',
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
            new PriceQuote(
                lines: [new PriceLine('Séjour', 1, null, 66800, PriceLine::RULE_BASE)],
                totalCents: 66800,
                nights: 3,
                persons: 20,
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
        $this->pdo->prepare('UPDATE rental_bookings SET rental_receivable_id = ? WHERE id = ?')
            ->execute([self::RECEIVABLE_ID, $created['id']]);
        $booking = $bookings->findById($created['id']);
        $this->assertNotNull($booking);
        $this->booking = $booking;
    }

    private function serviceWith(
        ?ExpectedReceivableInterface $receivables,
        ?StatementImportStatusInterface $statementStatus = null
    ): RentalPaymentService {
        return new RentalPaymentService(
            $this->paymentRepository,
            RentalTestHelper::bookingAudit(
                $this->pdo,
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
            ),
            $this->journal,
            $receivables,
            null,
            null,
            null,
            $statementStatus
        );
    }

    private function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-01-10');
    }

    /** @return list<array{event_type: string, description: string, context: string}> */
    private function journalEntries(): array
    {
        /** @var list<array{event_type: string, description: string, context: string}> $rows */
        $rows = $this->pdo->query(
            "SELECT event_type, description, context FROM event_log WHERE category = 'rental' ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function manualMovement(
        int $transactionId,
        string $reconciledAt,
        bool $detachable = true
    ): ReceivableMovement {
        return new ReceivableMovement(
            date: '2027-03-14',
            label: 'Sinistre 2027/118',
            counterpartyName: 'ETHIAS SA',
            amountCents: 100000,
            automatic: false,
            transactionId: $transactionId,
            reconciledAt: $reconciledAt,
            reconciledBy: 7,
            detachable: $detachable
        );
    }

    private function sendContract(string $sentAt): void
    {
        $this->pdo->prepare(
            "INSERT INTO rental_documents (booking_id, file_id, document_type, sent_at) VALUES (?, 1, 'contract', ?)"
        )->execute([$this->booking->id, $sentAt]);
    }

    // ── Since when (Q10) ────────────────────────────────────────────────

    public function testBeforeAnyContractTheSearchStartsTheDayTheRequestCameIn(): void
    {
        $this->assertSame('2027-01-10 00:00:00', $this->service->transferSince($this->booking)->format('Y-m-d H:i:s'));
    }

    public function testTheSearchStartsTheDayTheFirstContractWentOut(): void
    {
        $this->sendContract('2027-02-03 15:20:00');
        $this->sendContract('2027-01-20 09:00:00');
        $this->pdo->exec("INSERT INTO rental_documents (booking_id, file_id, document_type, sent_at)
            VALUES ({$this->booking->id}, 1, 'contract', NULL)");
        $this->pdo->exec("INSERT INTO rental_documents (booking_id, file_id, document_type, sent_at)
            VALUES ({$this->booking->id}, 1, 'invoice', '2027-01-15 08:00:00')");

        $this->assertSame('2027-01-20 00:00:00', $this->service->transferSince($this->booking)->format('Y-m-d H:i:s'));
    }

    // ── How far the account is known ────────────────────────────────────

    public function testTheLastImportIsAskedForTheAssetsAccount(): void
    {
        $status = $this->createMock(StatementImportStatusInterface::class);
        $status->expects($this->once())->method('lastStatementImportedAt')->with(5)->willReturn('2027-03-20 18:00:00');

        $at = $this->serviceWith($this->receivables, $status)->lastStatementImportedAt(
            new PaymentSettings(enabled: true, financeAccountId: 5, financeCategoryId: 3)
        );

        $this->assertSame('2027-03-20 18:00:00', $at?->format('Y-m-d H:i:s'));
    }

    public function testNoImportIsKnownWithoutAnAccountOrWithoutTheInterface(): void
    {
        $status = $this->createMock(StatementImportStatusInterface::class);
        $status->expects($this->never())->method('lastStatementImportedAt');

        $this->assertNull($this->serviceWith($this->receivables, $status)->lastStatementImportedAt(
            new PaymentSettings(enabled: false)
        ));
        $this->assertNull($this->service->lastStatementImportedAt(
            new PaymentSettings(enabled: true, financeAccountId: 5, financeCategoryId: 3)
        ));
    }

    // ── The search ──────────────────────────────────────────────────────

    public function testTheSearchAsksFinanceForTheExactAmountAndTheTrimmedName(): void
    {
        $found = [new PaymentCandidate(91, '2027-03-14', 100000, 'ETHIAS SA', 'Sinistre 2027/118')];
        $this->receivables->expects($this->once())->method('findPaymentCandidates')
            ->with('rental', self::RECEIVABLE_ID, 100000, 'Ethias', $this->since())
            ->willReturn($found);

        $this->assertSame(
            $found,
            $this->service->paymentCandidates($this->booking, 100000, '  Ethias ', $this->since())
        );
    }

    public function testABlankNameSearchesWithoutOne(): void
    {
        $this->receivables->expects($this->once())->method('findPaymentCandidates')
            ->with('rental', self::RECEIVABLE_ID, 100000, null, $this->since())
            ->willReturn([]);

        $this->assertSame([], $this->service->paymentCandidates($this->booking, 100000, '   ', $this->since()));
    }

    public function testTheJournalKeepsTheIdsAndTheCountNeverTheNameNorTheAmount(): void
    {
        $this->receivables->method('findPaymentCandidates')->willReturn([
            new PaymentCandidate(91, '2027-03-14', 123456, 'ETHIAS SA', 'Sinistre'),
            new PaymentCandidate(92, '2027-03-15', 123456, 'ETHIAS SA', 'Sinistre bis'),
        ]);

        $this->service->paymentCandidates($this->booking, 123456, 'Ethias', $this->since());

        $entries = $this->journalEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('rental_transfer_searched', $entries[0]['event_type']);
        $this->assertSame(
            ['booking_id' => $this->booking->id, 'candidates' => 2],
            json_decode($entries[0]['context'], true)
        );
        $written = $entries[0]['description'] . $entries[0]['context'];
        $this->assertStringNotContainsStringIgnoringCase('ethias', $written);
        $this->assertStringNotContainsString('123456', $written);
        $this->assertStringNotContainsString('1234', $written);
    }

    public function testAMissingAmountOrAnOverlongNameIsRefusedBeforeFinanceIsAsked(): void
    {
        $this->receivables->expects($this->never())->method('findPaymentCandidates');

        foreach ([[0, null], [-500, null], [100000, str_repeat('a', 101)]] as [$amount, $name]) {
            try {
                $this->service->paymentCandidates($this->booking, $amount, $name, $this->since());
                $this->fail('A search for ' . $amount . ' must be refused.');
            } catch (RentalException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], $this->journalEntries());
    }

    // ── Reconciling ─────────────────────────────────────────────────────

    public function testReconcilingHandsFinanceTheCreditTheAmountTheDateAndWhoDecided(): void
    {
        $this->receivables->expects($this->once())->method('reconcilePayment')
            ->with('rental', self::RECEIVABLE_ID, 91, 100000, $this->since(), 7);

        $this->service->reconcilePayment($this->booking, 91, 100000, $this->since(), 7, null);

        $entries = $this->journalEntries();
        $this->assertSame('rental_transfer_reconciled', $entries[0]['event_type']);
        $this->assertSame(
            ['booking_id' => $this->booking->id, 'transaction_id' => 91],
            json_decode($entries[0]['context'], true)
        );
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM entity_changes WHERE field_key = 'payment_recorded'"
        )->fetchColumn());
    }

    public function testFinancesRefusalIsPassedOnAsItIsAndNothingIsRecorded(): void
    {
        $this->receivables->method('reconcilePayment')
            ->willThrowException(new FinanceException("Ce virement n'est plus un candidat."));

        try {
            $this->service->reconcilePayment($this->booking, 91, 100000, $this->since(), 7);
            $this->fail('The refusal must reach the manager.');
        } catch (RentalException $e) {
            $this->assertSame("Ce virement n'est plus un candidat.", $e->getMessage());
        }
        $this->assertSame([], $this->journalEntries());
    }

    // ── Detaching (Q11) ─────────────────────────────────────────────────

    public function testAManualAttachmentIsDetachableUntilASettlementValidatedAfterItCountsIt(): void
    {
        $movement = $this->manualMovement(91, '2027-03-20 10:00:00');

        $this->assertTrue(RentalPaymentService::canDetach($movement, null));
        $this->assertTrue(RentalPaymentService::canDetach($movement, new \DateTimeImmutable('2027-03-19 18:00:00')));
        $this->assertFalse(RentalPaymentService::canDetach($movement, new \DateTimeImmutable('2027-03-20 10:00:00')));
        $this->assertFalse(RentalPaymentService::canDetach($movement, new \DateTimeImmutable('2027-03-21 09:00:00')));
    }

    public function testWhatFinanceDoesNotCallDetachableNeverIs(): void
    {
        $undetachable = $this->manualMovement(91, '2027-03-20 10:00:00', false);
        $this->assertFalse(RentalPaymentService::canDetach($undetachable, null));
        $automatic = new ReceivableMovement('2027-03-05', 'Virement +++090/9337/55493+++', null, 25000, true, 90);
        $this->assertFalse(RentalPaymentService::canDetach($automatic, null));
    }

    public function testDetachingAsksFinanceForTheMovementItAttached(): void
    {
        $this->receivables->method('getReceivableMovements')
            ->willReturn([$this->manualMovement(91, '2027-03-20 10:00:00')]);
        $this->receivables->expects($this->once())->method('detachPayment')->with('rental', self::RECEIVABLE_ID, 91);

        $this->service->detachPayment($this->booking, 91, new \DateTimeImmutable('2027-03-19 18:00:00'));

        $entries = $this->journalEntries();
        $this->assertSame('rental_transfer_detached', $entries[0]['event_type']);
        $this->assertSame(
            ['booking_id' => $this->booking->id, 'transaction_id' => 91],
            json_decode($entries[0]['context'], true)
        );
    }

    public function testDetachingIsRefusedOnceAValidatedSettlementCountedTheTransfer(): void
    {
        $this->receivables->method('getReceivableMovements')
            ->willReturn([$this->manualMovement(91, '2027-03-20 10:00:00')]);
        $this->receivables->expects($this->never())->method('detachPayment');

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('Le décompte validé le 22/03/2027 tient compte de ce virement');

        $this->service->detachPayment($this->booking, 91, new \DateTimeImmutable('2027-03-22 09:00:00'));
    }

    public function testDetachingAMovementThatIsNotAHandAttachmentIsRefused(): void
    {
        $this->receivables->method('getReceivableMovements')->willReturn([
            new ReceivableMovement('2027-03-05', 'Virement', null, 25000, true, 90),
        ]);
        $this->receivables->expects($this->never())->method('detachPayment');

        foreach ([90, 404] as $transactionId) {
            try {
                $this->service->detachPayment($this->booking, $transactionId, null);
                $this->fail('Transaction ' . $transactionId . ' is no hand attachment of this booking.');
            } catch (RentalException $e) {
                $this->assertSame("Ce virement n'est pas rapproché à la main de cette réservation.", $e->getMessage());
            }
        }
    }

    // ── Without Finances ────────────────────────────────────────────────

    public function testWithoutFinancesEveryPathSaysSoAndNothingIsWritten(): void
    {
        $service = $this->serviceWith(null);
        $calls = [
            fn() => $service->paymentCandidates($this->booking, 100000, null, $this->since()),
            fn() => $service->reconcilePayment($this->booking, 91, 100000, $this->since(), 7),
            fn() => $service->detachPayment($this->booking, 91, null),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Without Finances there is nothing to reconcile.');
            } catch (RentalException $e) {
                $this->assertSame(
                    'Les paiements de cette réservation ne sont pas suivis dans les Finances.',
                    $e->getMessage()
                );
            }
        }
        $this->assertSame([], $this->journalEntries());
        $this->assertNull($service->lastStatementImportedAt(
            new PaymentSettings(enabled: true, financeAccountId: 5, financeCategoryId: 3)
        ));
        $this->assertSame('2027-01-10', $service->transferSince($this->booking)->format('Y-m-d'));
    }

    public function testABookingWithoutAReceivableIsNotReconciledEither(): void
    {
        $this->pdo->prepare('UPDATE rental_bookings SET rental_receivable_id = NULL WHERE id = ?')
            ->execute([$this->booking->id]);
        $this->receivables->expects($this->never())->method('findPaymentCandidates');

        $this->expectException(RentalException::class);
        $this->service->paymentCandidates($this->booking, 100000, null, $this->since());
    }
}
