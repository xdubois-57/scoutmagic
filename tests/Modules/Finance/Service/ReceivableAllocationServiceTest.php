<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocation;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReceivableSettlement;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivableAllocationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ExpectedReceivableRepository $receivables;
    private ReceivableAllocationRepository $allocations;
    private TransactionRepository $transactions;
    private ReceivableAllocationService $service;
    private int $accountId;
    private int $otherAccountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->allocations = new ReceivableAllocationRepository($this->pdo);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->service = FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Louveteaux', 'bank', 'active')");
        $this->otherAccountId = (int) $this->pdo->lastInsertId();

        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');
    }

    // ── the automatic pass ──────────────────────────────────────────────

    public function testAMatchingCreditIsAllocatedToItsReceivable(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00);

        $this->service->reconcileAccount($this->accountId);

        $written = $this->allocations->findByReceivableId($id);
        $this->assertCount(1, $written);
        $this->assertSame(4500, $written[0]->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_AUTO, $written[0]->source);
    }

    /**
     * On a reconciled account, the stored-allocations-only read reports
     * the same allocated/remaining/refunded/status as the full read — it
     * is what the home payment band relies on. Only amountDesignated may
     * differ (it needs the credit scan), which its docblock says.
     */
    public function testStoredSettlementsMatchTheFullReadOnceReconciled(): void
    {
        $paid = $this->receivable(4500, '+++123/4567/89012+++');
        $open = $this->receivable(3000, '+++123/4567/89013+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $receivables = $this->receivables->findByIds([$paid, $open]);
        $full = $this->service->settlementsFor($receivables);
        $stored = $this->service->storedSettlementsFor($receivables);

        foreach ([$paid, $open] as $id) {
            $this->assertSame($full[$id]->amountAllocatedCents, $stored[$id]->amountAllocatedCents);
            $this->assertSame($full[$id]->amountRemainingCents(), $stored[$id]->amountRemainingCents());
            $this->assertSame($full[$id]->amountRefundedCents, $stored[$id]->amountRefundedCents);
            $this->assertSame($full[$id]->status, $stored[$id]->status);
        }
        $this->assertSame(0, $stored[$open]->amountAllocatedCents);
        $this->assertSame(3000, $stored[$open]->amountRemainingCents());
    }

    /**
     * The single rule behind "paid too much", "paid in instalments that
     * overshoot" and "paid twice a month apart": a receivable never
     * absorbs more than it is worth. The surplus stays unallocated
     * instead of hiding inside a line that reads paid.
     */
    public function testAReceivableNeverAbsorbsMoreThanItIsDue(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);

        $this->service->reconcileAccount($this->accountId);

        $settlement = $this->settlement($id);
        $this->assertSame(3825, $settlement->amountAllocatedCents);
        $this->assertSame(4500, $settlement->amountDesignatedCents);
        $this->assertSame(675, $settlement->amountOverpaidCents);
        $this->assertSame(ReceivableSettlement::STATUS_PAID, $settlement->status);
    }

    /**
     * Two instalments of 30 € for a receivable of 45 €. No single
     * transaction shows an excess — the first is allocated whole, the
     * second only halfway. The surplus exists only in the sum, and that
     * is the number the screens have to show.
     */
    public function testTheOverpaymentBelongsToTheReceivableNotToAnyTransaction(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $this->credit('Premier versement +++123/4567/89012+++', 30.00);
        $this->credit('Second versement +++123/4567/89012+++', 30.00);

        $this->service->reconcileAccount($this->accountId);

        $written = $this->allocations->findByReceivableId($id);
        $this->assertSame([3000, 1500], array_map(static fn($a) => $a->amountCents, $written));

        $settlement = $this->settlement($id);
        $this->assertSame(4500, $settlement->amountAllocatedCents);
        $this->assertSame(6000, $settlement->amountDesignatedCents);
        $this->assertSame(1500, $settlement->amountOverpaidCents);
    }

    public function testRunningTheAutomaticPassTwiceWritesNothingTheSecondTime(): void
    {
        $this->receivable(4500, '+++123/4567/89012+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00);

        $this->assertSame(1, $this->service->reconcileAccount($this->accountId));
        $this->assertSame(0, $this->service->reconcileAccount($this->accountId));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn());
    }

    /**
     * Re-importing an overlapping statement is a no-op one level down:
     * finance_transactions refuses a line whose (account, bank reference)
     * it already knows, so there is no second movement to allocate.
     */
    public function testReimportingTheSameStatementLineDoublesNothing(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');

        foreach ([1, 2] as $unusedImportRun) {
            $this->transactions->insertOrSkip(
                $this->accountId,
                $this->fiscalYearId,
                'REF-0001',
                '2026-02-18',
                'Virement +++123/4567/89012+++',
                45.00,
                null,
                null,
                null,
                null
            );
            $this->service->reconcileAccount($this->accountId);
        }

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_transactions')->fetchColumn());
        $this->assertSame(4500, $this->settlement($id)->amountAllocatedCents);
    }

    public function testADebitNeverSettlesAReceivable(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $this->credit('Paiement sortant +++123/4567/89012+++', -45.00);

        $this->service->reconcileAccount($this->accountId);

        $this->assertSame(ReceivableSettlement::STATUS_UNPAID, $this->settlement($id)->status);
    }

    public function testACreditIsSpreadOverTheReceivablesItNamesUntilItRunsOut(): void
    {
        // One transfer of 60 € carrying two communications, for two
        // receivables of 45 €: the first is filled, the second gets the
        // remainder.
        $first = $this->receivable(4500, '+++123/4567/89012+++');
        $second = $this->receivable(4500, '+++123/4567/89025+++');
        $this->credit('Virement +++123/4567/89012+++ et +++123/4567/89025+++', 60.00);

        $this->service->reconcileAccount($this->accountId);

        $this->assertSame(4500, $this->settlement($first)->amountAllocatedCents);
        $this->assertSame(1500, $this->settlement($second)->amountAllocatedCents);
    }

    // ── the automatic pass never overrules a human ──────────────────────

    public function testTheAutomaticPassLeavesAManualAllocationAlone(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('Virement +++123/4567/89012+++', 45.00);

        $this->service->allocate($transactionId, $id, 2000, Role::INTENDANT, 7);
        $this->service->reconcileAccount($this->accountId);

        $written = $this->allocations->findPair($transactionId, $id);
        $this->assertNotNull($written);
        $this->assertSame(2000, $written->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_MANUAL, $written->source);
        $this->assertSame(7, $written->createdBy);
    }

    /**
     * Removing an automatic allocation writes a zero-amount manual row
     * rather than deleting it. A deletion leaves no trace, and the next
     * import would put the allocation straight back — which is the whole
     * point of provenance.
     */
    public function testRemovingAnAutomaticAllocationSurvivesTheNextPass(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('Virement +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $this->service->allocate($transactionId, $id, 0, Role::INTENDANT, 7);
        $this->service->reconcileAccount($this->accountId);

        $this->assertSame(0, $this->settlement($id)->amountAllocatedCents);
        $this->assertSame(ReceivableSettlement::STATUS_UNPAID, $this->settlement($id)->status);
    }

    /**
     * And a movement a treasurer has ruled out is not "arrived and
     * unabsorbed" either: it must not resurface as a trop-perçu.
     */
    public function testARuledOutMovementIsNotATropPercuEither(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('Virement +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $this->service->allocate($transactionId, $id, 0, Role::INTENDANT, 7);

        $settlement = $this->settlement($id);
        $this->assertSame(0, $settlement->amountDesignatedCents);
        $this->assertSame(0, $settlement->amountOverpaidCents);
        $this->assertSame(ReceivableSettlement::REFUND_NONE, $settlement->refundState);
    }

    // ── splitting one transfer over a household ─────────────────────────

    public function testOneTransferIsSplitOverThreeReceivablesOfTheSameHousehold(): void
    {
        $lucie = $this->receivable(4500, '+++123/4567/89012+++');
        $antoine = $this->receivable(4500, '+++123/4567/89025+++');
        $margaux = $this->receivable(3825, '+++123/4567/89038+++');
        // The communication names Lucie's receivable; the amount is the
        // household's total. This is the most frequent real case.
        $transactionId = $this->credit('VANDENBRANDE M +++123/4567/89012+++', 128.25);

        $this->service->split(
            $transactionId,
            [$lucie => 4500, $antoine => 4500, $margaux => 3825],
            Role::INTENDANT,
            7
        );

        $this->assertSame(4500, $this->settlement($lucie)->amountAllocatedCents);
        $this->assertSame(4500, $this->settlement($antoine)->amountAllocatedCents);
        $this->assertSame(3825, $this->settlement($margaux)->amountAllocatedCents);
        // Nothing is left over, so nobody carries a trop-perçu — least of
        // all Lucie, whose communication the transfer happened to name.
        $this->assertSame(0, $this->settlement($lucie)->amountOverpaidCents);
    }

    public function testASplitCannotHandOutMoreThanTheTransferCarries(): void
    {
        $first = $this->receivable(4500, '+++123/4567/89012+++');
        $second = $this->receivable(4500, '+++123/4567/89025+++');
        $transactionId = $this->credit('Virement groupé', 60.00);

        $this->service->allocate($transactionId, $first, 4500, Role::INTENDANT, 7);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/à répartir/');
        $this->service->allocate($transactionId, $second, 4500, Role::INTENDANT, 7);
    }

    public function testAllocatingMoreThanIsStillDueIsRefused(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('Virement', 100.00);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/n\'absorbe jamais plus/');
        $this->service->allocate($transactionId, $id, 10000, Role::INTENDANT, 7);
    }

    /**
     * A credit no communication matched, attached by hand — the "non
     * imputés" case. Nothing about the movement's text has to agree.
     */
    public function testACreditNoCommunicationMatchedCanBeAttachedByHand(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('DUPONT J « cotisation Léa »', 45.00);

        $this->service->allocate($transactionId, $id, 4500, Role::INTENDANT, 7);

        $this->assertSame(ReceivableSettlement::STATUS_PAID, $this->settlement($id)->status);
    }

    // ── the invariant: never across two accounts ────────────────────────

    public function testAnAllocationNeverJoinsTwoAccounts(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('Virement +++123/4567/89012+++', 45.00, $this->otherAccountId);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/même compte/');
        $this->service->allocate($transactionId, $id, 4500, Role::INTENDANT, 7);
    }

    public function testACreditOnAnotherAccountIsNeverMatchedAutomaticallyEither(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00, $this->otherAccountId);

        $this->service->reconcileAccount($this->accountId);
        $this->service->reconcileAccount($this->otherAccountId);

        $this->assertSame(ReceivableSettlement::STATUS_UNPAID, $this->settlement($id)->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn());
    }

    // ── abandon ─────────────────────────────────────────────────────────

    public function testAbandoningAReceivableSettlesItWithoutAnyMoneyComingIn(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');

        $this->service->waive($id, Role::INTENDANT, 7);

        $settlement = $this->settlement($id);
        $this->assertSame(ReceivableSettlement::STATUS_WAIVED, $settlement->status);
        $this->assertSame(0, $settlement->amountAllocatedCents);
        $this->assertTrue($settlement->isSettled());
        $this->assertFalse($settlement->needsAttention());
    }

    public function testLiftingAnAbandonPutsTheReceivableBackWhereItWas(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $this->service->waive($id, Role::INTENDANT, 7);

        $this->service->cancelWaiver($id, Role::INTENDANT);

        $this->assertSame(ReceivableSettlement::STATUS_UNPAID, $this->settlement($id)->status);
    }

    // ── the refund cycle ────────────────────────────────────────────────

    public function testASurplusIsNeutralUntilSomebodyDecidesOtherwise(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $this->assertSame(ReceivableSettlement::REFUND_OPEN, $this->settlement($id)->refundState);
    }

    public function testDeclaringASurplusOwedBackChangesNothingAboutTheMoney(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $this->service->requestRefund($id, Role::INTENDANT, 7);

        $settlement = $this->settlement($id);
        $this->assertSame(ReceivableSettlement::REFUND_REQUESTED, $settlement->refundState);
        $this->assertSame(675, $settlement->amountOverpaidCents);
    }

    public function testAReceivableWithoutASurplusCannotBeDeclaredOwedBack(): void
    {
        $id = $this->receivable(4500, '+++123/4567/89012+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/trop-per/');
        $this->service->requestRefund($id, Role::INTENDANT, 7);
    }

    /**
     * The cycle closes on the bank statement: the state becomes
     * "remboursé" because a debit left the account, never because a box
     * was ticked.
     */
    public function testARefundIsCompleteBecauseTheDebitExists(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);
        $this->service->requestRefund($id, Role::INTENDANT, 7);

        $debitId = $this->credit('Remboursement trop-perçu', -6.75);
        $this->service->allocateRefund($debitId, $id, 675, Role::INTENDANT, 7);

        $settlement = $this->settlement($id);
        $this->assertSame(ReceivableSettlement::REFUND_DONE, $settlement->refundState);
        $this->assertSame(0, $settlement->amountOverpaidCents);
        $this->assertSame(675, $settlement->amountRefundedCents);
        $this->assertFalse($settlement->needsAttention());
    }

    public function testARefundCannotExceedTheSurplus(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);
        $debitId = $this->credit('Remboursement', -45.00);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/trop-perçu/');
        $this->service->allocateRefund($debitId, $id, 4500, Role::INTENDANT, 7);
    }

    public function testACreditIsNeverAcceptedAsARefund(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $creditId = $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/débit/');
        $this->service->allocateRefund($creditId, $id, 675, Role::INTENDANT, 7);
    }

    /**
     * A paid receivable carrying a surplus nobody has settled still needs
     * a treasurer — which is what the campaign screen's default filter
     * leans on.
     */
    public function testAPaidReceivableCarryingASurplusStillNeedsAttention(): void
    {
        $id = $this->receivable(3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->service->reconcileAccount($this->accountId);

        $settlement = $this->settlement($id);
        $this->assertSame(ReceivableSettlement::STATUS_PAID, $settlement->status);
        $this->assertTrue($settlement->needsAttention());
    }

    // ── the account partition applies here too ──────────────────────────

    public function testAReceivableOnAnAccountTheViewerCannotSeeIsNotEvenAcknowledged(): void
    {
        // role_min_view = 'admin' on the account, viewer is an intendant:
        // the answer must be the same as for a receivable that does not
        // exist, rather than telling the caller which ones do.
        $this->pdo->exec("UPDATE finance_accounts SET role_min_view = 'admin' WHERE id = {$this->accountId}");
        $id = $this->receivable(4500, '+++123/4567/89012+++');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches("/n'existe pas/");
        $this->service->waive($id, Role::INTENDANT, 7);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function receivable(int $amountCents, string $communication, ?int $accountId = null): int
    {
        return $this->receivables->create(
            'finance',
            1,
            $accountId ?? $this->accountId,
            $amountCents,
            $communication,
            null
        );
    }

    private function credit(string $label, float $amount, ?int $accountId = null): int
    {
        return $this->transactions->create(
            $accountId ?? $this->accountId,
            $this->fiscalYearId,
            null,
            '2026-02-18',
            $label,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    private function settlement(int $receivableId): ReceivableSettlement
    {
        $receivable = $this->receivables->findById($receivableId);
        self::assertNotNull($receivable);

        return $this->service->settlementFor($receivable);
    }
}
