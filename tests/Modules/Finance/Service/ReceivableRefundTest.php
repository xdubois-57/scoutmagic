<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\ReceivableMovement;
use Modules\Finance\Api\RefundCandidate;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocation;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The refund side of a receivable (chantier #927, §2.6): a debit carrying
 * its communication is reconciled as its refund by the automatic pass, up
 * to the surplus; and the module that raised it reads its standing and its
 * movements, and points at the debit that refunded it when the transfer
 * left without the communication — through Api\ExpectedReceivableInterface
 * only, and seeing nothing of any other receivable.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ReceivableRefundTest extends TestCase
{
    private \PDO $pdo;
    private ExpectedReceivableRepository $receivables;
    private ReceivableAllocationRepository $allocations;
    private TransactionRepository $transactions;
    private ReceivableAllocationService $allocationService;
    private ExpectedReceivableService $api;
    private int $accountId;
    private int $otherAccountId;
    private int $fiscalYearId;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->allocations = new ReceivableAllocationRepository($this->pdo);
        $this->transactions = new TransactionRepository($this->pdo, $encryption);
        $this->allocationService = FinanceTestHelper::allocationService($this->pdo, $encryption, $this->receivables);
        $this->api = FinanceTestHelper::receivableService($this->pdo, $encryption, $this->receivables);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Locations', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Unité', 'bank', 'active')");
        $this->otherAccountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->categoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
    }

    // ── the automatic pass ──────────────────────────────────────────────

    public function testADebitCarryingTheCommunicationRefundsTheSurplus(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -196.00, 'Remboursement caution ' . $this->comm('0909337554'));

        $this->allocationService->reconcileAccount($this->accountId);

        $refund = $this->allocations->findPair($debit, $id);
        $this->assertNotNull($refund);
        $this->assertSame(-19600, $refund->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_AUTO, $refund->source);

        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertNotNull($standing);
        $this->assertSame(62200, $standing->amountDueCents);
        $this->assertSame(81800, $standing->amountReceivedCents);
        $this->assertSame(19600, $standing->amountRefundedCents);
        $this->assertSame(0, $standing->amountOverpaidCents);
        $this->assertSame(0, $standing->amountRemainingCents);
        $this->assertSame('paid', $standing->status);
        $this->assertFalse($standing->isOverpaid());
    }

    /**
     * A debit larger than what is owed back refunds the surplus and no
     * more: the rest of it stays unreconciled for a treasurer to look at.
     */
    public function testARefundIsCappedAtTheSurplusAndTheRestOfTheDebitStaysUnreconciled(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -200.00, 'Remboursement ' . $this->comm('0909337554'));

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertSame(-19600, $this->allocations->findPair($debit, $id)?->amountCents);
        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertSame(19600, $standing?->amountRefundedCents);
        $this->assertSame(0, $standing?->amountOverpaidCents);
    }

    /** No surplus, no refund: a debit never settles a receivable either. */
    public function testADebitNamingAReceivableWithoutSurplusWritesNothing(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-20', -50.00, 'Paiement ' . $this->comm('0909337554'));

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertCount(1, $this->allocations->findByReceivableId($id));
    }

    public function testRunningThePassAgainWritesNothing(): void
    {
        $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));

        $this->assertSame(2, $this->allocationService->reconcileAccount($this->accountId));
        $this->assertSame(0, $this->allocationService->reconcileAccount($this->accountId));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn());
    }

    /** Two refund transfers: the second gives back only what the first left owed. */
    public function testASecondDebitRefundsOnlyWhatIsStillOwedBack(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $first = $this->movement('2027-03-20', -150.00, 'Remboursement 1 ' . $this->comm('0909337554'));
        $second = $this->movement('2027-03-21', -150.00, 'Remboursement 2 ' . $this->comm('0909337554'));

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertSame(-15000, $this->allocations->findPair($first, $id)?->amountCents);
        $this->assertSame(-4600, $this->allocations->findPair($second, $id)?->amountCents);
    }

    /** One debit naming two receivables never gives back more than it carries. */
    public function testOneDebitNeverRefundsMoreThanItCarriesAcrossTwoReceivables(): void
    {
        $first = $this->receivable(1000, '1230000001');
        $second = $this->receivable(1000, '1230000002');
        $this->movement('2027-03-08', 20.00, 'Virement ' . $this->comm('1230000001'));
        $this->movement('2027-03-08', 20.00, 'Virement ' . $this->comm('1230000002'));
        $debit = $this->movement(
            '2027-03-20',
            -15.00,
            'Remboursements ' . $this->comm('1230000001') . ' ' . $this->comm('1230000002')
        );

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertSame(-1000, $this->allocations->findPair($debit, $first)?->amountCents);
        $this->assertSame(-500, $this->allocations->findPair($debit, $second)?->amountCents);
    }

    /**
     * One credit naming two receivables, with more than both are worth:
     * the rest of it is nobody's surplus in particular, so it is nobody's
     * at all — never counted twice, never refunded automatically. A
     * treasurer decides whose it is.
     */
    public function testTheRestOfACreditNamingTwoReceivablesIsNeitherOnesSurplus(): void
    {
        $first = $this->receivable(1000, '1230000001');
        $second = $this->receivable(1000, '1230000002');
        $this->movement(
            '2027-03-08',
            30.00,
            'Deux paiements ' . $this->comm('1230000001') . ' ' . $this->comm('1230000002')
        );
        $refundFirst = $this->movement('2027-03-20', -10.00, 'Remboursement ' . $this->comm('1230000001'));
        $refundSecond = $this->movement('2027-03-20', -10.00, 'Remboursement ' . $this->comm('1230000002'));

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertNull($this->allocations->findPair($refundFirst, $first));
        $this->assertNull($this->allocations->findPair($refundSecond, $second));
        $this->assertSame(0, $this->api->getReceivableStanding('rental', $first)?->amountOverpaidCents);
        $this->assertSame(0, $this->api->getReceivableStanding('rental', $second)?->amountOverpaidCents);
        $this->assertSame([], $this->api->findRefundCandidates('rental', $first, new \DateTimeImmutable('2027-03-01')));
    }

    /** A person's word on the pair — here, "this debit refunds nothing here" — outlives the next pass. */
    public function testThePassLeavesAPersonsRowOnThePairAlone(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));
        $this->allocations->create($debit, $id, 0, ReceivableAllocation::SOURCE_MANUAL, 7);

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertSame(0, $this->allocations->findPair($debit, $id)?->amountCents);
    }

    /**
     * The money that went back stays gone: an automatic refund is never
     * reduced when the surplus it was capped at shrinks afterwards…
     */
    public function testAnAutomaticRefundIsNeverReducedWhenTheSurplusShrinks(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));
        $this->allocationService->reconcileAccount($this->accountId);

        $this->api->updateReceivableAmount($id, 72200);

        $this->assertSame(-19600, $this->allocations->findPair($debit, $id)?->amountCents);
    }

    /** …but grows, up to what the debit carries, when the surplus grows. */
    public function testAnAutomaticRefundCappedEarlierGrowsWithTheSurplus(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -250.00, 'Remboursement ' . $this->comm('0909337554'));
        $this->allocationService->reconcileAccount($this->accountId);
        $this->assertSame(-19600, $this->allocations->findPair($debit, $id)?->amountCents);

        $this->api->updateReceivableAmount($id, 50000, allowBelowReceived: true);

        $this->assertSame(-25000, $this->allocations->findPair($debit, $id)?->amountCents);
    }

    /**
     * A receivable brought to 0 — the booking refused, cancelled or
     * expired after a payment (chantier #927, §2.5) — keeps what arrived
     * for it as a surplus: the automatic share the pass rewrote to 0 is
     * not a person saying « pays nothing here », and the refund debit that
     * follows is reconciled like any other.
     */
    public function testAReceivableBroughtToZeroShowsWhatArrivedAsSurplusAndTakesItsRefund(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $this->allocationService->reconcileAccount($this->accountId);

        $this->api->updateReceivableAmount($id, 0, allowBelowReceived: true);

        $this->assertSame(0, $this->allocations->findPair($credit, $id)?->amountCents);
        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertSame(62200, $standing?->amountReceivedCents);
        $this->assertSame(62200, $standing?->amountOverpaidCents);

        $debit = $this->movement('2027-03-20', -622.00, 'Remboursement ' . $this->comm('0909337554'));
        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertSame(-62200, $this->allocations->findPair($debit, $id)?->amountCents);
        $this->assertSame(0, $this->api->getReceivableStanding('rental', $id)?->amountOverpaidCents);
    }

    /**
     * A refund given, then the amount raised back to what first arrived:
     * the money that went back is expected again, so the standing reads
     * « partial » with the refund still to come — not « paid » because the
     * credit's share alone covers the new amount.
     */
    public function testTheStandingNetsTheRefundsOutOfWhatWasReceived(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));
        $this->allocationService->reconcileAccount($this->accountId);

        $this->api->updateReceivableAmount($id, 81800);

        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertSame('partial', $standing?->status);
        $this->assertSame(19600, $standing?->amountRemainingCents);
        $this->assertSame(0, $standing?->amountOverpaidCents);
    }

    /**
     * What already went back is no longer money held: lowering the amount
     * is compared with what came in less the refunds, not with the gross.
     */
    public function testLoweringTheAmountIsComparedWithWhatWasKeptAfterRefunds(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));
        $this->allocationService->reconcileAccount($this->accountId);

        try {
            $this->api->updateReceivableAmount($id, 60000);
            $this->fail('a lowering below what was kept went through unconfirmed');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('(622,00 €, remboursements déduits)', $e->getMessage());
        }
    }

    public function testARefundIsBookedUnderTheReceivablesCategory(): void
    {
        $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));

        $this->allocationService->reconcileAccount($this->accountId);

        $this->assertSame($this->categoryId, $this->transactions->findById($debit)?->categoryId);
    }

    /** The one refund a treasurer used to have to record by hand is no longer open on « Trop-perçus ». */
    public function testARefundedReceivableReadsRefunded(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'));

        $this->allocationService->reconcileAccount($this->accountId);

        $receivable = $this->receivables->findById($id);
        $this->assertNotNull($receivable);
        $settlement = $this->allocationService->settlementFor($receivable);
        $this->assertSame('refunded', $settlement->refundState);
        $this->assertFalse($settlement->needsAttention());
    }

    // ── the standing and the movements, through the Api ────────────────

    public function testTheStandingSaysWhatIsStillOwedBack(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));

        $standing = $this->api->getReceivableStanding('rental', $id);

        $this->assertSame(19600, $standing?->amountOverpaidCents);
        $this->assertSame(0, $standing?->amountRefundedCents);
        $this->assertTrue($standing?->isOverpaid());
    }

    public function testTheStandingOfAnUnpaidReceivableSaysWhatIsStillExpected(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 250.00, 'Acompte ' . $this->comm('0909337554'));

        $standing = $this->api->getReceivableStanding('rental', $id);

        $this->assertSame(25000, $standing?->amountReceivedCents);
        $this->assertSame(37200, $standing?->amountRemainingCents);
        $this->assertSame('partial', $standing?->status);
    }

    public function testTheMovementsAreThePaymentsAndRefundsOfThisReceivableOldestFirst(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 568.00, 'Solde ' . $this->comm('0909337554'), 'J. LAMBERT');
        $this->movement('2027-03-05', 250.00, 'Acompte ' . $this->comm('0909337554'), 'J. LAMBERT');
        $this->movement('2027-03-20', -196.00, 'Remboursement ' . $this->comm('0909337554'), 'J. LAMBERT');
        $this->movement('2027-03-06', 42.00, 'Autre chose', 'BRICO NAMUR');
        $this->api->getReceivableStanding('rental', $id);

        $movements = $this->api->getReceivableMovements('rental', $id);

        $this->assertSame(
            [['2027-03-05', 25000, true], ['2027-03-08', 37200, true], ['2027-03-20', -19600, true]],
            array_map(
                static fn (ReceivableMovement $m): array => [$m->date, $m->amountCents, $m->automatic],
                $movements
            )
        );
        $this->assertSame('Acompte ' . $this->comm('0909337554'), $movements[0]->label);
        $this->assertSame('J. LAMBERT', $movements[0]->counterpartyName);
        $this->assertTrue($movements[2]->isRefund());
    }

    /**
     * One transfer paying two receivables shows, on each, its own share —
     * never the whole amount, and nothing of the other receivable.
     */
    public function testAMovementSharedWithAnotherReceivableShowsOnlyThisShare(): void
    {
        $mine = $this->receivable(3000, '1230000001');
        $theirs = $this->receivable(5000, '1230000002', 'finance');
        $this->movement('2027-03-08', 80.00, 'Deux paiements ' . $this->comm('1230000001') . ' ' . $this->comm('1230000002'));
        $ruledOut = $this->movement('2027-03-09', 10.00, 'Encore ' . $this->comm('1230000001'));
        $this->allocationService->reconcileAccount($this->accountId);
        $this->allocations->create($ruledOut, $mine, 0, ReceivableAllocation::SOURCE_MANUAL, 7);

        $movements = $this->api->getReceivableMovements('rental', $mine);

        $this->assertCount(1, $movements);
        $this->assertSame(3000, $movements[0]->amountCents);
        $this->assertSame(5000, $this->allocationService->allocationsFor($theirs)[0]->amountCents);
    }

    public function testAReadingWritesNothing(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));

        $this->assertSame([], $this->api->getReceivableMovements('rental', $id));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn());
    }

    /**
     * A module reads the receivables it raised and nothing else: another
     * module's receivable is answered exactly as one that does not exist.
     */
    public function testAnotherModulesReceivableIsAnsweredAsAbsent(): void
    {
        $campaign = $this->receivable(62200, '0909337554', 'finance');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -100.00, 'Sans communication');

        $this->assertNull($this->api->getReceivableStanding('rental', $campaign));
        $this->assertSame([], $this->api->getReceivableMovements('rental', $campaign));
        $this->assertSame([], $this->api->findRefundCandidates('rental', $campaign, new \DateTimeImmutable('2027-03-01')));
        $this->assertNull($this->api->getReceivableStanding('rental', 999));

        try {
            $this->api->reconcileRefund('rental', $campaign, $debit, new \DateTimeImmutable('2027-03-01'), 7);
            $this->fail('another module\'s receivable was refunded');
        } catch (FinanceException $e) {
            $this->assertStringContainsString("n'existe pas", $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM finance_receivable_allocations WHERE amount_cents < 0'
        )->fetchColumn());
    }

    // ── candidates and the manual refund ────────────────────────────────

    public function testTheCandidatesAreTheUnreconciledDebitsOfItsAccountUpToTheSurplusSinceTheDate(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $other = $this->receivable(1000, '1230000001');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-08', 20.00, 'Virement ' . $this->comm('1230000001'));
        $this->movement('2027-03-17', -50.00, 'Avant la date', 'TROP TÔT');
        $match = $this->movement('2027-03-20', -196.00, 'Virement sans communication', 'J. LAMBERT', 'BE71096123456769');
        $small = $this->movement('2027-03-21', -42.10, 'Facture', 'BRICO NAMUR');
        $this->movement('2027-03-21', -196.01, 'Trop grand', 'TROP GRAND');
        $this->movement('2027-03-22', 30.00, 'Un crédit', 'CRÉDIT');
        $this->movement('2027-03-22', -20.00, 'Autre compte', 'AUTRE COMPTE', null, $this->otherAccountId);
        $taken = $this->movement('2027-03-23', -5.00, 'Déjà pris', 'DÉJÀ PRIS');
        $this->allocationService->allocateRefund($taken, $other, 500, Role::INTENDANT, 7);

        $candidates = $this->api->findRefundCandidates('rental', $id, new \DateTimeImmutable('2027-03-18'));

        $this->assertEquals([
            new RefundCandidate($match, '2027-03-20', 19600, 'J. LAMBERT'),
            new RefundCandidate($small, '2027-03-21', 4210, 'BRICO NAMUR'),
        ], $candidates);
    }

    public function testNothingIsACandidateWhenNothingIsOwedBack(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-20', -10.00, 'Facture', 'BRICO NAMUR');

        $this->assertSame([], $this->api->findRefundCandidates('rental', $id, new \DateTimeImmutable('2027-03-01')));
    }

    /** A debit a person already said is no refund of this receivable is not offered again. */
    public function testADebitRuledOutForThisReceivableIsNotACandidate(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -100.00, 'Facture', 'BRICO NAMUR');
        $this->allocations->create($debit, $id, 0, ReceivableAllocation::SOURCE_MANUAL, 7);

        $this->assertSame([], $this->api->findRefundCandidates('rental', $id, new \DateTimeImmutable('2027-03-01')));
    }

    public function testReconcilingACandidateRecordsItsWholeAmountAsAPersonsRefund(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -196.00, 'Virement sans communication', 'J. LAMBERT');

        $this->api->reconcileRefund('rental', $id, $debit, new \DateTimeImmutable('2027-03-18'), 7);

        $refund = $this->allocations->findPair($debit, $id);
        $this->assertSame(-19600, $refund?->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_MANUAL, $refund?->source);
        $this->assertSame(7, $refund?->createdBy);
        $this->assertSame(0, $this->api->getReceivableStanding('rental', $id)?->amountOverpaidCents);
        $this->assertSame($this->categoryId, $this->transactions->findById($debit)?->categoryId);
        $this->assertSame(
            [-19600, false],
            [$this->api->getReceivableMovements('rental', $id)[1]->amountCents,
                $this->api->getReceivableMovements('rental', $id)[1]->automatic]
        );
    }

    public function testTheSameDebitCannotBeReconciledTwice(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -96.00, 'Virement sans communication');
        $since = new \DateTimeImmutable('2027-03-18');
        $this->api->reconcileRefund('rental', $id, $debit, $since, 7);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('déjà rapproché');
        $this->api->reconcileRefund('rental', $id, $debit, $since, 7);
    }

    /** One debit never refunds two receivables. */
    public function testADebitAlreadyRefundingAnotherReceivableIsRefused(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $other = $this->receivable(1000, '1230000001');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $this->movement('2027-03-08', 20.00, 'Virement ' . $this->comm('1230000001'));
        $debit = $this->movement('2027-03-20', -10.00, 'Virement sans communication');
        $this->allocationService->allocateRefund($debit, $other, 1000, Role::INTENDANT, 7);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('déjà rapproché');
        $this->api->reconcileRefund('rental', $id, $debit, new \DateTimeImmutable('2027-03-18'), 7);
    }

    /**
     * Every filter of the candidate list is checked again: a list shown a
     * minute ago — or an id that was never on it — is not a promise.
     *
     * @return array<string, array{float, string, bool, string}>
     */
    public static function debitsThatAreNotCandidates(): array
    {
        return [
            'larger than the surplus' => [-196.01, '2027-03-20', false, 'dépasse le trop-perçu'],
            'before the date' => [-50.00, '2027-03-17', false, 'antérieur'],
            'a credit' => [50.00, '2027-03-20', false, "n'en est pas un"],
            'on another account' => [-50.00, '2027-03-20', true, 'pas un mouvement du compte'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('debitsThatAreNotCandidates')]
    public function testADebitThatIsNotACandidateIsRefused(
        float $amount,
        string $date,
        bool $otherAccount,
        string $message
    ): void {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement($date, $amount, 'Virement', null, null, $otherAccount ? $this->otherAccountId : null);

        try {
            $this->api->reconcileRefund('rental', $id, $debit, new \DateTimeImmutable('2027-03-18'), 7);
            $this->fail('a debit that is not a candidate was reconciled');
        } catch (FinanceException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertNull($this->allocations->findPair($debit, $id));
    }

    public function testNoRefundIsRecordedWhenNothingIsOwedBack(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -10.00, 'Virement');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('aucun trop-perçu');
        $this->api->reconcileRefund('rental', $id, $debit, new \DateTimeImmutable('2027-03-18'), 7);
    }

    /** Two debits pointed at one after the other cannot together refund more than the surplus. */
    public function testTwoCandidatesCannotTogetherRefundMoreThanTheSurplus(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $first = $this->movement('2027-03-20', -150.00, 'Virement 1');
        $second = $this->movement('2027-03-21', -150.00, 'Virement 2');
        $since = new \DateTimeImmutable('2027-03-18');
        $this->assertCount(2, $this->api->findRefundCandidates('rental', $id, $since));

        $this->api->reconcileRefund('rental', $id, $first, $since, 7);

        $this->assertSame([], $this->api->findRefundCandidates('rental', $id, $since));
        $this->expectException(FinanceException::class);
        $this->api->reconcileRefund('rental', $id, $second, $since, 7);
    }

    /** What a person reconciled is theirs: the automatic pass never revises it. */
    public function testAManualRefundSurvivesTheNextPass(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $debit = $this->movement('2027-03-20', -196.00, 'Virement sans communication');
        $this->api->reconcileRefund('rental', $id, $debit, new \DateTimeImmutable('2027-03-18'), 7);

        $this->api->updateReceivableAmount($id, 81800);

        $this->assertSame(-19600, $this->allocations->findPair($debit, $id)?->amountCents);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function receivable(int $amountCents, string $tenDigits, string $sourceModule = 'rental'): int
    {
        return $this->receivables->create(
            $sourceModule,
            1,
            $this->accountId,
            $amountCents,
            $this->comm($tenDigits),
            null,
            null,
            $this->categoryId
        );
    }

    private function comm(string $tenDigits): string
    {
        return StructuredCommunicationService::format($tenDigits);
    }

    private function movement(
        string $date,
        float $amount,
        string $label,
        ?string $counterpartyName = null,
        ?string $counterpartyAccount = null,
        ?int $accountId = null
    ): int {
        return $this->transactions->create(
            $accountId ?? $this->accountId,
            $this->fiscalYearId,
            null,
            $date,
            $label,
            $amount,
            null,
            null,
            'import',
            null,
            $counterpartyName,
            $counterpartyAccount
        );
    }
}
