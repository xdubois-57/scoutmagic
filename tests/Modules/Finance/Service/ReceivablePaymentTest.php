<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\PaymentCandidate;
use Modules\Finance\Api\ReceivableMovement;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocation;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The incoming side of a receivable (chantier #980, §2.6): the module that
 * raised it finds the credit that paid it without its communication — a
 * renter's insurer — among a deliberately narrow list, attaches it whole
 * (the written exception to rule 1 of ARCHITECTURE.md §8.81), and detaches
 * it again when it picked the wrong one; through
 * Api\ExpectedReceivableInterface only, and seeing nothing of any other
 * receivable.
 */
#[Group('database')]
final class ReceivablePaymentTest extends TestCase
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
    private \DateTimeImmutable $since;

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

        $this->pdo->exec(
            "INSERT INTO finance_accounts (name, account_type, status) VALUES ('Locations', 'bank', 'active')"
        );
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO finance_accounts (name, account_type, status) VALUES ('Unité', 'bank', 'active')"
        );
        $this->otherAccountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->categoryId = FinanceTestHelper::createCategory($this->pdo, 'Locations');
        $this->since = new \DateTimeImmutable('2027-03-01');
    }

    // ── the candidates ──────────────────────────────────────────────────

    /**
     * Only the unreconciled credits of the receivable's account, of exactly
     * that amount, since the date, and carrying no receivable's
     * communication — each with its date, amount, counterparty and text.
     */
    public function testTheCandidatesAreTheUnreconciledCreditsOfItsAccountOfThatAmountSinceTheDate(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $other = $this->receivable(30000, '1230000001');
        $this->receivable(30000, '1230000002', 'finance', $this->otherAccountId);
        $match = $this->movement('2027-03-14', 300.00, 'Sinistre 2027/118', 'ETHIAS SA');
        $this->movement('2027-02-27', 300.00, 'Avant la date', 'TROP TÔT');
        $this->movement('2027-03-15', 300.01, 'Un centime de plus', 'PRESQUE');
        $this->movement('2027-03-15', -300.00, 'Un débit', 'DÉBIT');
        $this->movement('2027-03-15', 300.00, 'Autre compte', 'AUTRE COMPTE', $this->otherAccountId);
        $this->movement('2027-03-16', 300.00, 'Virement ' . $this->comm('1230000001'), 'UNE FAMILLE');
        $this->movement('2027-03-16', 300.00, 'Mauvais compte ' . $this->comm('1230000002'), 'UNE AUTRE');
        $taken = $this->movement('2027-03-17', 300.00, 'Déjà rattaché', 'DÉJÀ PRIS');
        $this->allocationService->allocate($taken, $other, 30000, Role::INTENDANT, 7);
        $declared = $this->movement('2027-03-18', 300.00, 'Don', 'UN DONATEUR');
        $this->transactions->markNotAReceivable($declared, 7);
        $later = $this->movement('2027-03-20', 300.00, 'Pour la location', 'J. LAMBERT');

        $candidates = $this->api->findPaymentCandidates('rental', $id, 30000, null, $this->since);

        $this->assertEquals([
            new PaymentCandidate($match, '2027-03-14', 30000, 'ETHIAS SA', 'Sinistre 2027/118'),
            new PaymentCandidate($later, '2027-03-20', 30000, 'J. LAMBERT', 'Pour la location'),
        ], $candidates);
    }

    /** Partly attached elsewhere is reconciled: never offered, not even for its remainder. */
    public function testACreditPartlyPayingAnotherReceivableIsNotACandidate(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $other = $this->receivable(10000, '1230000001');
        $shared = $this->movement('2027-03-14', 300.00, 'Sans communication', 'ETHIAS SA');
        $this->allocationService->allocate($shared, $other, 10000, Role::INTENDANT, 7);

        $this->assertSame([], $this->api->findPaymentCandidates('rental', $id, 30000, null, $this->since));
    }

    /** A credit a person already said pays nothing here is not offered again. */
    public function testACreditRuledOutForThisReceivableIsNotACandidate(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-14', 300.00, 'Sans communication', 'ETHIAS SA');
        $this->allocations->create($credit, $id, 0, ReceivableAllocation::SOURCE_MANUAL, 7);

        $this->assertSame([], $this->api->findPaymentCandidates('rental', $id, 30000, null, $this->since));
    }

    /**
     * The payer's name narrows the list as a substring, ignoring case and
     * accents — compared after decryption, since it is encrypted at rest.
     *
     * @return array<string, array{?string, list<string>}>
     */
    public static function counterpartyFilters(): array
    {
        return [
            'no name' => [null, ['ÉTHIAS SA', 'J. Lambert-Dupré']],
            'blank' => ['   ', ['ÉTHIAS SA', 'J. Lambert-Dupré']],
            'case and accents' => ['ethias', ['ÉTHIAS SA']],
            'inside the name' => ['DUPRE', ['J. Lambert-Dupré']],
            'punctuation ignored' => ['lambert dupre', ['J. Lambert-Dupré']],
            'nobody' => ['AG Insurance', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('counterpartyFilters')]
    public function testTheCounterpartyNameNarrowsTheCandidates(?string $contains, array $expected): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-14', 300.00, 'Sinistre', 'ÉTHIAS SA');
        $this->movement('2027-03-15', 300.00, 'Virement', 'J. Lambert-Dupré');
        $this->movement('2027-03-16', 300.00, 'Sans nom');

        $candidates = $this->api->findPaymentCandidates('rental', $id, 30000, $contains, $this->since);

        $names = array_map(static fn (PaymentCandidate $c): ?string => $c->counterpartyName, $candidates);
        if ($contains === null || trim($contains) === '') {
            $expected[] = null;
        }
        $this->assertSame($expected, $names);
    }

    public function testAnAmountThatIsNotPositiveIsRefused(): void
    {
        $id = $this->receivable(62200, '0909337554');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('montant reçu');
        $this->api->findPaymentCandidates('rental', $id, 0, null, $this->since);
    }

    /**
     * A module reads the receivables it raised and nothing else: another
     * module's receivable has no candidates, and nothing is attached to or
     * detached from it.
     */
    public function testAnotherModulesReceivableIsAnsweredAsAbsent(): void
    {
        $campaign = $this->receivable(62200, '0909337554', 'finance');
        $credit = $this->movement('2027-03-14', 300.00, 'Sans communication', 'ETHIAS SA');

        $this->assertSame([], $this->api->findPaymentCandidates('rental', $campaign, 30000, null, $this->since));
        $this->assertSame([], $this->api->findPaymentCandidates('rental', 999, 30000, null, $this->since));
        foreach ([
            fn () => $this->api->reconcilePayment('rental', $campaign, $credit, 30000, $this->since, 7),
            fn () => $this->api->detachPayment('rental', $campaign, $credit),
        ] as $call) {
            try {
                $call();
                $this->fail('another module\'s receivable was touched');
            } catch (FinanceException $e) {
                $this->assertStringContainsString("n'existe pas", $e->getMessage());
            }
        }
        $this->assertSame(0, $this->allocationCount());
    }

    // ── attaching ───────────────────────────────────────────────────────

    /**
     * The whole credit, as a person's decision: the excess over what is
     * due reads as an overpayment (Q1, the exception to rule 1).
     */
    public function testReconcilingAttachesTheWholeCreditEvenBeyondWhatIsDue(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'), 'J. LAMBERT');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre 2027/118', 'ETHIAS SA');

        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $pair = $this->allocations->findPair($credit, $id);
        $this->assertSame(30000, $pair?->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_MANUAL, $pair?->source);
        $this->assertSame(7, $pair?->createdBy);
        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertSame(92200, $standing?->amountReceivedCents);
        $this->assertSame(30000, $standing?->amountOverpaidCents);
        $this->assertSame(0, $standing?->amountRemainingCents);
        $this->assertSame('paid', $standing?->status);
        $this->assertSame($this->categoryId, $this->transactions->findById($credit)?->categoryId);
    }

    /** On a receivable still waiting for its money, the credit simply pays it. */
    public function testReconcilingWithinWhatIsDueLeavesTheRestDue(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-14', 300.00, 'Pour la location', 'J. LAMBERT');

        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertSame(32200, $standing?->amountRemainingCents);
        $this->assertSame(0, $standing?->amountOverpaidCents);
        $this->assertSame('partial', $standing?->status);
    }

    /** The movement says who attached it and when, and that it can be detached. */
    public function testTheAttachedCreditIsAMovementSayingWhoReconciledItAndThatItIsDetachable(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $auto = $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'), 'J. LAMBERT');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre 2027/118', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $movements = $this->api->getReceivableMovements('rental', $id);

        $this->assertSame(
            [[$auto, true, null, false], [$credit, false, 7, true]],
            array_map(
                static fn (ReceivableMovement $m): array
                    => [$m->transactionId, $m->automatic, $m->reconciledBy, $m->detachable],
                $movements
            )
        );
        $this->assertNull($movements[0]->reconciledAt);
        $this->assertSame($this->allocations->findPair($credit, $id)?->createdAt, $movements[1]->reconciledAt);
        $this->assertSame(30000, $movements[1]->amountCents);
    }

    /**
     * Rule 1 is kept around the exception: the automatic share of the same
     * receivable makes room for the person's row, and takes it back when
     * the amount due rises to take the excess in (a validated settlement
     * charging the damage the insurer paid).
     */
    public function testTheAutomaticShareMakesRoomAndTakesItBackWhenTheAmountRises(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $auto = $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'), 'J. LAMBERT');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre 2027/118', 'ETHIAS SA');
        $this->api->getReceivableStanding('rental', $id);

        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $this->assertSame(32200, $this->allocations->findPair($auto, $id)?->amountCents);

        $this->api->updateReceivableAmount($id, 92200);

        $this->assertSame(62200, $this->allocations->findPair($auto, $id)?->amountCents);
        $this->assertSame(30000, $this->allocations->findPair($credit, $id)?->amountCents);
        $standing = $this->api->getReceivableStanding('rental', $id);
        $this->assertSame(0, $standing?->amountOverpaidCents);
        $this->assertSame(0, $standing?->amountRemainingCents);
    }

    /** What a person attached is theirs: no pass, no change of amount, revises it. */
    public function testTheAutomaticPassNeverRevisesThePersonsRow(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $this->allocationService->reconcileAccount($this->accountId);
        $this->api->updateReceivableAmount($id, 10000, true);
        $this->allocationService->reconcileAccount($this->accountId);
        $this->api->updateReceivableAmount($id, 150000);

        $pair = $this->allocations->findPair($credit, $id);
        $this->assertSame(30000, $pair?->amountCents);
        $this->assertSame(ReceivableAllocation::SOURCE_MANUAL, $pair?->source);
        $this->assertSame(7, $pair?->createdBy);
    }

    public function testTheSameCreditCannotBeReconciledTwice(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('déjà rapproché');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);
    }

    /** One credit never pays two receivables. */
    public function testACreditAlreadyAttachedToAnotherReceivableIsRefused(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $other = $this->receivable(62200, '1230000001');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $other, $credit, 30000, $this->since, 7);

        try {
            $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);
            $this->fail('one credit paid two receivables');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('déjà rapproché', $e->getMessage());
        }
        $this->assertNull($this->allocations->findPair($credit, $id));
    }

    /**
     * Every filter of the candidate list is checked again: a list shown a
     * minute ago — or an id that was never on it — is not a promise.
     *
     * @return array<string, array{float, string, string, bool, int, string}>
     */
    public static function creditsThatAreNotCandidates(): array
    {
        return [
            'another amount' => [300.00, '2027-03-14', 'Sinistre', false, 29900, "n'est pas de 299,00 €"],
            'before the date' => [300.00, '2027-02-27', 'Sinistre', false, 30000, 'antérieur'],
            'a debit' => [-300.00, '2027-03-14', 'Sinistre', false, 30000, 'débit'],
            'on another account' => [300.00, '2027-03-14', 'Sinistre', true, 30000, 'pas un mouvement du compte'],
            'carrying a communication' => [300.00, '2027-03-14', 'Virement +++123/0000/00137+++', false, 30000,
                "porte la communication d'une créance"],
        ];
    }

    #[DataProvider('creditsThatAreNotCandidates')]
    public function testACreditThatIsNotACandidateIsRefused(
        float $amount,
        string $date,
        string $label,
        bool $otherAccount,
        int $searched,
        string $message
    ): void {
        $id = $this->receivable(62200, '0909337554');
        // A receivable of another account, raised by another module: its
        // communication still makes the credit somebody's payment.
        $this->receivable(5000, '1230000001', 'finance', $this->otherAccountId);
        $credit = $this->movement($date, $amount, $label, 'ETHIAS SA', $otherAccount ? $this->otherAccountId : null);

        try {
            $this->api->reconcilePayment('rental', $id, $credit, $searched, $this->since, 7);
            $this->fail('a credit that is not a candidate was attached');
        } catch (FinanceException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
        $this->assertNull($this->allocations->findPair($credit, $id));
    }

    public function testACreditATreasurerDeclaredNoPaymentIsRefused(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-14', 300.00, 'Don', 'UN DONATEUR');
        $this->transactions->markNotAReceivable($credit, 7);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('ne correspond à aucune créance');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);
    }

    public function testAMovementThatDoesNotExistIsRefused(): void
    {
        $id = $this->receivable(62200, '0909337554');

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage('pas un mouvement du compte');
        $this->api->reconcilePayment('rental', $id, 999, 30000, $this->since, 7);
    }

    // ── detaching ───────────────────────────────────────────────────────

    /**
     * The undo: the row goes, the category it took goes with it, the
     * automatic share takes its room back, and the credit is a candidate
     * again — for this receivable or another.
     */
    public function testDetachingPutsEverythingBackAsItWas(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $auto = $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);

        $this->api->detachPayment('rental', $id, $credit);

        $this->assertNull($this->allocations->findPair($credit, $id));
        $this->assertSame(62200, $this->allocations->findPair($auto, $id)?->amountCents);
        $this->assertNull($this->transactions->findById($credit)?->categoryId);
        $this->assertSame(0, $this->api->getReceivableStanding('rental', $id)?->amountOverpaidCents);
        $this->assertSame(
            [$credit],
            array_map(
                static fn (PaymentCandidate $c): int => $c->transactionId,
                $this->api->findPaymentCandidates('rental', $id, 30000, null, $this->since)
            )
        );
    }

    public function testDetachingTwiceRemovesItOnce(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $id, $credit, 30000, $this->since, 7);
        $this->api->detachPayment('rental', $id, $credit);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage("n'est pas rapproché à la main de cette créance");
        $this->api->detachPayment('rental', $id, $credit);
    }

    /** Only this pair's row: a credit attached to another receivable is not detached from this one. */
    public function testACreditAttachedToAnotherReceivableIsNotDetachedFromThisOne(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $other = $this->receivable(62200, '1230000001');
        $credit = $this->movement('2027-03-14', 300.00, 'Sinistre', 'ETHIAS SA');
        $this->api->reconcilePayment('rental', $other, $credit, 30000, $this->since, 7);

        try {
            $this->api->detachPayment('rental', $id, $credit);
            $this->fail('a credit was detached from a receivable it does not pay');
        } catch (FinanceException $e) {
            $this->assertStringContainsString("n'est pas rapproché à la main", $e->getMessage());
        }
        $this->assertSame(30000, $this->allocations->findPair($credit, $other)?->amountCents);
    }

    /**
     * Never what the site matched, nor a refund, nor a person's « pays
     * nothing here » — and the movement list says so before anybody asks.
     */
    public function testWhatIsNotAPersonsAttachmentOfACreditIsNotDetachable(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $auto = $this->movement('2027-03-08', 818.00, 'Virement ' . $this->comm('0909337554'));
        $refund = $this->movement('2027-03-20', -196.00, 'Remboursement', 'J. LAMBERT');
        $ruledOut = $this->movement('2027-03-21', 50.00, 'Autre chose', 'BRICO NAMUR');
        $this->api->reconcileRefund('rental', $id, $refund, $this->since, 7);
        $this->allocations->create($ruledOut, $id, 0, ReceivableAllocation::SOURCE_MANUAL, 7);

        foreach ([$auto, $refund, $ruledOut] as $transactionId) {
            try {
                $this->api->detachPayment('rental', $id, $transactionId);
                $this->fail("movement {$transactionId} was detached");
            } catch (FinanceException $e) {
                $this->assertStringContainsString("n'est pas rapproché à la main", $e->getMessage());
            }
        }
        $this->assertSame([false, false], array_map(
            static fn (ReceivableMovement $m): bool => $m->detachable,
            $this->api->getReceivableMovements('rental', $id)
        ));
    }

    /**
     * A treasurer's attachment of a credit carrying the communication is a
     * person's row too, but deleting it would only let the import put the
     * automatic one back: it is not detachable from here.
     */
    public function testAPersonsRowOnACreditCarryingTheCommunicationIsNotDetachable(): void
    {
        $id = $this->receivable(62200, '0909337554');
        $credit = $this->movement('2027-03-08', 622.00, 'Virement ' . $this->comm('0909337554'));
        $this->allocationService->allocate($credit, $id, 60000, Role::INTENDANT, 7);

        $this->assertFalse($this->api->getReceivableMovements('rental', $id)[0]->detachable);
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessage("porte la communication d'une créance");
        $this->api->detachPayment('rental', $id, $credit);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function receivable(
        int $amountCents,
        string $tenDigits,
        string $sourceModule = 'rental',
        ?int $accountId = null
    ): int {
        return $this->receivables->create(
            $sourceModule,
            1,
            $accountId ?? $this->accountId,
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
            $counterpartyName
        );
    }

    private function allocationCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn();
    }
}
