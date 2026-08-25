<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\FinanceException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ExpectedReceivableServiceTest extends TestCase
{
    private \PDO $pdo;
    private ExpectedReceivableService $service;
    private TransactionRepository $transactionRepository;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->service = new ExpectedReceivableService(new ExpectedReceivableRepository($this->pdo, $encryption), $this->transactionRepository);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    public function testCreateReceivableReturnsAnId(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', 'Camp d\'été');

        $this->assertGreaterThan(0, $id);
    }

    public function testLabelIsStoredEncryptedAtRestAndDecryptsBackOnRead(): void
    {
        $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', 'Jean Dupont — Camp d\'été');

        $raw = $this->pdo->query('SELECT label_encrypted FROM finance_expected_receivables')->fetchColumn();
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Jean Dupont', $raw);

        $repository = new \Modules\Finance\Repository\ExpectedReceivableRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $receivable = $repository->findBySource('news', 12)[0];
        $this->assertSame('Jean Dupont — Camp d\'été', $receivable->label);
    }

    public function testGetReceivableStatusIsUnpaidWithNoMatchingTransaction(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_due']);
        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    public function testGetReceivableStatusIsPaidWhenAMatchingTransactionCoversTheFullAmount(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $this->createTransaction('Virement de Jean Dupont +++100/0000/00034+++', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusIsPartialWhenMatchedAmountIsLessThanDue(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 5000, '+++100/0000/00034+++', null);

        $this->createTransaction('Acompte +++100/0000/00034+++', 20.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2000, $status['amount_received']);
        $this->assertSame('partial', $status['status']);
    }

    public function testGetReceivableStatusSumsMultipleMatchingTransactions(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 5000, '+++100/0000/00034+++', null);

        $this->createTransaction('Premier versement +++100/0000/00034+++', 20.00);
        $this->createTransaction('Solde +++100/0000/00034+++', 30.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(5000, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusIgnoresTransactionsWithADifferentCommunication(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $this->createTransaction('Virement sans rapport +++999/9999/99999+++', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    /**
     * Belgian bank statements don't all render the "communication
     * structurée" the same way — some keep the OGM/VCS punctuation exactly
     * as generated (+++NNN/NNNN/NNNNN+++), others export it as a bare
     * 12-digit run with no separators at all. Matching strips every
     * non-digit character from both sides before comparing (digitsOnly()),
     * so both must be recognized as the same communication.
     */
    public function testGetReceivableStatusMatchesWhenBankTextKeepsThePunctuatedFormat(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++104/1932/40720+++', null);

        $this->createTransaction('Virement +++104/1932/40720+++', 25.00);

        $status = $this->service->getReceivableStatus($id);
        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusMatchesWhenBankTextStripsAllPunctuation(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++104/1932/40720+++', null);

        // Same communication, exported by the bank as a bare digit run.
        $this->createTransaction('VIREMENT EUROPEEN COMMUNICATION 104193240720', 25.00);

        $status = $this->service->getReceivableStatus($id);
        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusMatchesABareDigitCommunicationAgainstABarePaymentReference(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '107000272186', null);

        $this->createTransaction('Communication: 107000272186', 25.00);

        $status = $this->service->getReceivableStatus($id);
        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    public function testGetReceivableStatusIgnoresDebitTransactions(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', null);

        $this->createTransaction('Paiement sortant +++100/0000/00034+++', -25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
    }

    public function testDeleteReceivablesForSourceRemovesAllMatchingRows(): void
    {
        $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->service->createReceivable('news', 12, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->service->createReceivable('news', 99, $this->accountId, 1000, '+++300/0000/00002+++', 'Carla');

        $this->service->deleteReceivablesForSource('news', 12);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables WHERE source_reference_id = 12')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables WHERE source_reference_id = 99')->fetchColumn());
    }

    /**
     * Regression: the communication is reduced to its digits and matched
     * with str_contains(). A communication carrying no digit reduced to ''
     * — and str_contains($haystack, '') is always true — so every credit on
     * the account was counted as settling the receivable and it reported
     * "paid". createReceivable() now refuses such a communication, and the
     * matching itself refuses an empty needle for rows written before it.
     */
    public function testCreateReceivableRejectsACommunicationWithoutDigits(): void
    {
        $this->expectException(FinanceException::class);
        $this->service->createReceivable('news', 12, $this->accountId, 2500, 'REFERENCE', null);
    }

    public function testADigitlessCommunicationAlreadyStoredNeverMatchesAnyCredit(): void
    {
        // Written straight to the repository, bypassing createReceivable()'s
        // new guard — this is the pre-existing row the guard cannot undo.
        $repository = new \Modules\Finance\Repository\ExpectedReceivableRepository(
            $this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $id = $repository->create('news', 12, $this->accountId, 2500, 'REFERENCE', null);

        $this->createTransaction('Virement sans rapport', 500.0);
        $this->createTransaction('Autre virement', 900.0);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    /**
     * Regression: label/comment/extra_details used to be concatenated and
     * stripped of every separator before matching, gluing the digits of
     * adjacent fields together — so a communication could be "found"
     * straddling a field boundary where it appears in neither field.
     */
    public function testACommunicationStraddlingTwoFieldsIsNotAMatch(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);

        // Digits of the communication are 123456789012. Split across the
        // label's tail and the comment's head, they only join up if the two
        // fields are concatenated before matching.
        $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01',
            'Paiement 123456', 25.0, null, '789012 suite', 'import', null
        );

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    public function testACommunicationWhollyInsideTheCommentStillMatches(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);

        $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01',
            'Virement', 25.0, null, 'communication 123 4567 89012 merci', 'import', null
        );

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    /**
     * Regression, and the reason IT-01 exists: the digits of a line were
     * flattened into one run and the communication was looked for inside
     * it with str_contains(). This line carries no communication at all —
     * four unrelated numbers — but stripping its separators produces
     * exactly "123456789012", so +++123/4567/89012+++ used to read paid
     * off somebody else's payment.
     *
     * As long as the status was recomputed on every display this only
     * made a page lie. IT-02 writes an allocation from the same match, so
     * from there on a stranger's payment marks this receivable settled and
     * the error stays in the database.
     */
    public function testDigitsGluedTogetherAcrossSeparatorsAreNotACommunication(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);

        $this->createTransaction('Virement 12 dossier 3456 lot 7890 caisse 12', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    /**
     * The same defect from the other side: the communication really is
     * present as a digit sub-sequence, but only because it sits inside a
     * longer number that is not a communication — here a counterparty
     * account number the export dropped into the free text.
     */
    public function testACommunicationFoundInsideALongerNumberIsNotAMatch(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);

        // 123456789012 is in there, between a leading 7 and a trailing 34.
        $this->createTransaction('Virement compte 712345678901234', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(0, $status['amount_received']);
        $this->assertSame('unpaid', $status['status']);
    }

    /**
     * A line can carry several twelve-digit sequences — a bank reference,
     * an account number, and the communication. Position decides nothing:
     * the communication is recognized wherever it sits.
     */
    public function testACommunicationIsFoundAmongOtherTwelveDigitSequences(): void
    {
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);

        $this->createTransaction('REF 987654321098 / 111122223333 / +++123/4567/89012+++', 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    /**
     * Some exports print the communication glued to whatever precedes it.
     * A window inside a longer run is only a candidate when its own mod-97
     * check passes, which is what keeps an account number from
     * volunteering half a dozen of them.
     */
    public function testACommunicationGluedToOtherDigitsIsStillFound(): void
    {
        $communication = \Modules\Finance\Service\StructuredCommunicationService::format('1234567890');
        $id = $this->service->createReceivable('news', 12, $this->accountId, 2500, $communication, null);

        $digits = preg_replace('/\D/', '', $communication) ?? '';
        $this->createTransaction('COMM' . '2026' . $digits, 25.00);

        $status = $this->service->getReceivableStatus($id);

        $this->assertSame(2500, $status['amount_received']);
        $this->assertSame('paid', $status['status']);
    }

    /**
     * The `***…***` form some banks print, and the dotted grouping, are
     * the same communication as the canonical one.
     */
    public function testTheStarredAndDottedFormsAreTheSameCommunication(): void
    {
        $starred = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);
        $dotted = $this->service->createReceivable('news', 13, $this->accountId, 2500, '+++104/1932/40720+++', null);

        $this->createTransaction('Virement ***123/4567/89012***', 25.00);
        $this->createTransaction('Virement 104.1932.40720', 25.00);

        $this->assertSame('paid', $this->service->getReceivableStatus($starred)['status']);
        $this->assertSame('paid', $this->service->getReceivableStatus($dotted)['status']);
    }

    /**
     * getReceivableStatuses() is the batch form the reconciliation page
     * uses; it must agree with getReceivableStatus() row for row.
     */
    public function testBatchStatusesAgreeWithThePerReceivableComputation(): void
    {
        $paidId = $this->service->createReceivable('news', 12, $this->accountId, 2500, '+++123/4567/89012+++', null);
        $unpaidId = $this->service->createReceivable('news', 12, $this->accountId, 4000, '+++999/8888/77766+++', null);
        $this->createTransaction('Paiement 123 4567 89012', 25.0);

        $repository = new \Modules\Finance\Repository\ExpectedReceivableRepository(
            $this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $statuses = $this->service->getReceivableStatuses($repository->findBySource('news', 12));

        $this->assertSame($this->service->getReceivableStatus($paidId), $statuses[$paidId]);
        $this->assertSame($this->service->getReceivableStatus($unpaidId), $statuses[$unpaidId]);
        $this->assertSame('paid', $statuses[$paidId]['status']);
        $this->assertSame('unpaid', $statuses[$unpaidId]['status']);
    }

    // ── updateReceivableAmount() ────────────────────────────────────────

    public function testUpdatingTheAmountKeepsTheCommunicationAndTheMatchedPayments(): void
    {
        // Deleting and recreating would mint a new communication and orphan
        // the transfer the payer already made — the amount has to move in
        // place.
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);
        $this->createTransaction('Acompte +++100/0000/00034+++', 200.00);

        $this->service->updateReceivableAmount($id, 40000);

        $status = $this->service->getReceivableStatus($id);
        $this->assertSame(40000, $status['amount_due']);
        $this->assertSame(20000, $status['amount_received']);
        $this->assertSame('partial', $status['status']);
    }

    public function testRaisingTheAmountTurnsAPaidReceivableBackIntoAPartialOne(): void
    {
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 20000, '+++100/0000/00034+++', null);
        $this->createTransaction('Solde +++100/0000/00034+++', 200.00);
        $this->assertSame('paid', $this->service->getReceivableStatus($id)['status']);

        $this->service->updateReceivableAmount($id, 25000);

        $this->assertSame('partial', $this->service->getReceivableStatus($id)['status']);
    }

    public function testLoweringBelowWhatHasAlreadyComeInIsRefused(): void
    {
        // Silently producing an overpayment is how a refund nobody knows
        // about happens: the receivable reads "paid", the surplus sits on
        // the account, and nothing says somebody is owed money.
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);
        $this->createTransaction('Paiement +++100/0000/00034+++', 300.00);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/trop-per/');

        $this->service->updateReceivableAmount($id, 25000);
    }

    public function testARefusedLoweringChangesNothing(): void
    {
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);
        $this->createTransaction('Paiement +++100/0000/00034+++', 300.00);

        try {
            $this->service->updateReceivableAmount($id, 25000);
            $this->fail('Lowering below the received amount must be refused.');
        } catch (FinanceException) {
            // Expected.
        }

        $this->assertSame(46750, $this->service->getReceivableStatus($id)['amount_due']);
    }

    public function testLoweringBelowTheReceivedAmountGoesThroughWhenTheCallerSaysSo(): void
    {
        // The caller has stated it knows it is creating an overpayment, and
        // owes the payer the difference.
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);
        $this->createTransaction('Paiement +++100/0000/00034+++', 300.00);

        $this->service->updateReceivableAmount($id, 25000, allowBelowReceived: true);

        $status = $this->service->getReceivableStatus($id);
        $this->assertSame(25000, $status['amount_due']);
        $this->assertSame(30000, $status['amount_received']);
        // Reads "paid" because more came in than is due — which is exactly
        // the state the flag exists to make deliberate.
        $this->assertSame('paid', $status['status']);
    }

    public function testLoweringToExactlyWhatCameInIsNotAnOverpaymentAndIsAllowed(): void
    {
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);
        $this->createTransaction('Paiement +++100/0000/00034+++', 300.00);

        $this->service->updateReceivableAmount($id, 30000);

        $this->assertSame('paid', $this->service->getReceivableStatus($id)['status']);
    }

    public function testLoweringToZeroOnAnUnpaidReceivableNeedsNoConfirmation(): void
    {
        // Nothing has come in, so there is no overpayment to warn about —
        // the guard is about money already received, not about the amount.
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);

        $this->service->updateReceivableAmount($id, 0);

        $this->assertSame(0, $this->service->getReceivableStatus($id)['amount_due']);
    }

    public function testANegativeAmountIsRefused(): void
    {
        $id = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/négatif/');

        $this->service->updateReceivableAmount($id, -100);
    }

    public function testUpdatingAReceivableThatDoesNotExistIsRefused(): void
    {
        $this->expectException(FinanceException::class);

        $this->service->updateReceivableAmount(9999, 1000);
    }

    public function testUpdatingOneReceivableLeavesTheOthersAlone(): void
    {
        $first = $this->service->createReceivable('rental', 3, $this->accountId, 46750, '+++100/0000/00034+++', null);
        $second = $this->service->createReceivable('rental', 4, $this->accountId, 12000, '+++100/0000/00047+++', null);

        $this->service->updateReceivableAmount($first, 40000);

        $this->assertSame(12000, $this->service->getReceivableStatus($second)['amount_due']);
    }

    private function createTransaction(string $label, float $amount): void
    {
        $this->transactionRepository->create(
            $this->accountId,
            $this->fiscalYearId,
            null,
            '2026-10-01',
            $label,
            $amount,
            null,
            null,
            'import',
            null
        );
    }
}
