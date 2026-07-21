<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ReceiptMatchingService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class ReceiptMatchingServiceTest extends TestCase
{
    private \PDO $pdo;
    private AttachmentRepository $attachmentRepository;
    private TransactionRepository $transactionRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private JournalService $journalService;
    private int $accountId;
    private int $fiscalYearId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->attachmentRepository = new AttachmentRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->journalService = new JournalService(new JournalRepository($this->pdo));

        $this->accountId = $accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    private function service(?LlmConnectorInterface $llmConnector = null): ReceiptMatchingService
    {
        return new ReceiptMatchingService(
            $this->attachmentRepository, $this->transactionRepository, $this->transactionAttachmentRepository,
            $this->journalService, $llmConnector
        );
    }

    private function createReceipt(?float $suggestedAmount, ?string $suggestedDate, string $uploadedAt, ?string $suggestedLabel = null): Attachment
    {
        $id = $this->attachmentRepository->create(
            $this->accountId, $this->fileId, 'application/pdf', 'facture.pdf', $suggestedAmount, $suggestedDate, null, 1
        );
        $this->pdo->prepare('UPDATE finance_attachments SET uploaded_at = ? WHERE id = ?')->execute([$uploadedAt, $id]);
        if ($suggestedLabel !== null) {
            $this->attachmentRepository->updateSuggestedLabel($id, $suggestedLabel);
        }

        $receipt = $this->attachmentRepository->findById($id);
        \assert($receipt !== null);
        return $receipt;
    }

    private function createTransaction(string $date, float $amount, string $label, ?int $accountId = null): Transaction
    {
        $id = $this->transactionRepository->create(
            $accountId ?? $this->accountId, $this->fiscalYearId, 'ref-' . uniqid(), $date, $label, $amount, null, null, Transaction::SOURCE_IMPORT, null
        );
        $transaction = $this->transactionRepository->findById($id);
        \assert($transaction !== null);
        return $transaction;
    }

    // --- Rule-based matching ---

    public function testMatchesUniqueExactAmountWithinWindow(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $transaction = $this->createTransaction('2026-10-02', -12.50, 'Delhaize Bruxelles');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([$transaction->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testDoesNotMatchWhenAmountIsUnknown(): void
    {
        $receipt = $this->createReceipt(null, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -12.50, 'Delhaize');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testDoesNotMatchWhenNoTransactionHasTheAmount(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -20.00, 'Delhaize');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testDoesNotMatchTransactionFarOutsideTheDateWindow(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2027-03-01', -12.50, 'Delhaize');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testMatchesTransactionDatedShortlyBeforeTheReceipt(): void
    {
        // Small backward tolerance — a card payment can post a day or two
        // before the chef gets around to scanning the receipt.
        $receipt = $this->createReceipt(12.50, '2026-10-05', '2026-10-05 10:00:00');
        $transaction = $this->createTransaction('2026-10-03', -12.50, 'Delhaize');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([$transaction->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testFallsBackToUploadDateWhenSuggestedDateIsUnknown(): void
    {
        $receipt = $this->createReceipt(12.50, null, '2026-10-01 10:00:00');
        $transaction = $this->createTransaction('2026-10-02', -12.50, 'Delhaize');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([$transaction->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testStaysPendingWhenSeveralMovementsShareTheAmountWithoutAClearWinner(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -12.50, 'Achat');
        $this->createTransaction('2026-10-03', -12.50, 'Achat');

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testDisambiguatesTiedAmountUsingDateAndLabelWhenOneIsClearlyBest(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00', 'Delhaize');
        $farAndDifferent = $this->createTransaction('2026-11-10', -12.50, 'Colruyt Ottignies');
        $closeAndMatching = $this->createTransaction('2026-10-01', -12.50, 'VIR Delhaize Bruxelles');

        $this->service()->matchReceipt($receipt);

        $this->assertSame(
            [$closeAndMatching->id],
            $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id)
        );
    }

    public function testIgnoresMovementsAlreadyLinkedToAnotherReceipt(): void
    {
        $alreadyLinkedReceipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $transaction = $this->createTransaction('2026-10-01', -12.50, 'Delhaize');
        $this->transactionAttachmentRepository->associate($transaction->id, $alreadyLinkedReceipt->id);

        $newReceipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->service()->matchReceipt($newReceipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($newReceipt->id));
    }

    public function testIgnoresMovementsOnADifferentAccount(): void
    {
        $accountRepository = new AccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $otherAccountId = $accountRepository->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-01', -12.50, 'Delhaize', $otherAccountId);

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testDoesNothingWhenAlreadyAssociated(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $existingTransaction = $this->createTransaction('2026-10-01', -99.99, 'Ancien mouvement');
        $this->transactionAttachmentRepository->associate($existingTransaction->id, $receipt->id);

        $matchingTransaction = $this->createTransaction('2026-10-01', -12.50, 'Delhaize');
        $this->service()->matchReceipt($receipt);

        $this->assertSame(
            [$existingTransaction->id],
            $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id)
        );
        $this->assertSame([], $this->transactionAttachmentRepository->findAttachmentIdsForTransaction($matchingTransaction->id));
    }

    // --- Batch matching (bank import trigger) ---

    public function testMatchPendingReceiptsForAccountMatchesEachIndependently(): void
    {
        $receiptA = $this->createReceipt(10.0, '2026-10-01', '2026-10-01 10:00:00');
        $transactionA = $this->createTransaction('2026-10-01', -10.0, 'A');
        $receiptB = $this->createReceipt(20.0, '2026-10-05', '2026-10-05 10:00:00');
        $transactionB = $this->createTransaction('2026-10-05', -20.0, 'B');

        $matched = $this->service()->matchPendingReceiptsForAccount($this->accountId);

        $this->assertSame(2, $matched);
        $this->assertSame([$transactionA->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receiptA->id));
        $this->assertSame([$transactionB->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receiptB->id));
    }

    public function testMatchPendingReceiptsForAccountNeverAssignsTheSameMovementTwice(): void
    {
        // Two receipts, both for exactly 10.0, but only one movement of
        // that amount exists — at most one of them should end up matched.
        $receiptA = $this->createReceipt(10.0, '2026-10-01', '2026-10-01 10:00:00');
        $receiptB = $this->createReceipt(10.0, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-01', -10.0, 'Achat');

        $this->service()->matchPendingReceiptsForAccount($this->accountId);

        $totalLinks = count($this->transactionAttachmentRepository->findTransactionIdsForAttachment($receiptA->id))
            + count($this->transactionAttachmentRepository->findTransactionIdsForAttachment($receiptB->id));
        $this->assertSame(1, $totalLinks);
    }

    // --- AI fallback ---

    public function testDoesNotAttemptAiMatchWhenNoMovementExistsOnOrAfterTheReceiptDate(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-10', '2026-10-10 10:00:00');
        $this->createTransaction('2026-10-01', -99.0, 'Mouvement plus ancien, montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->never())->method('complete');

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testAttemptsAiMatchWhenNoRuleBasedCandidateAndAFutureMovementExists(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00', 'Delhaize');
        $transaction = $this->createTransaction('2026-10-05', -99.0, 'Montant différent, donc pas de match par règles');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->once())
            ->method('complete')
            ->with($this->callback(fn(LlmRequest $r) => str_contains($r->prompt, 'id=' . $transaction->id)))
            ->willReturn(new LlmResponse('{"transaction_id":' . $transaction->id . '}', ['transaction_id' => $transaction->id], 10, 10));

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertSame([$transaction->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $updated = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($updated->matchingAiAttemptedAt);
    }

    public function testAiMatchIgnoresATransactionIdNotAmongTheCandidates(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('{"transaction_id":9999}', ['transaction_id' => 9999], 10, 10));

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testAiMatchAcceptsAnExplicitNullAsNoMatch(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('{"transaction_id":null}', ['transaction_id' => null], 10, 10));

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $updated = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($updated->matchingAiAttemptedAt);
    }

    public function testNeverRetriesAiMatchOnceAlreadyAttempted(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');
        $this->attachmentRepository->markAiMatchAttempted($receipt->id);
        $alreadyAttempted = $this->attachmentRepository->findById($receipt->id);

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->expects($this->never())->method('complete');

        $this->service($llmConnector)->matchReceipt($alreadyAttempted);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testMarksAttemptEvenWhenLlmConnectorIsUnavailable(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(false);
        $llmConnector->expects($this->never())->method('complete');

        $this->service($llmConnector)->matchReceipt($receipt);

        $updated = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($updated->matchingAiAttemptedAt);
    }

    public function testMarksAttemptWhenNoLlmConnectorIsConfiguredAtAll(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $this->service(null)->matchReceipt($receipt);

        $updated = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($updated->matchingAiAttemptedAt);
    }

    public function testMarksAttemptWhenLlmCallFails(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(LlmException::apiError('boom'));

        $this->service($llmConnector)->matchReceipt($receipt);

        $updated = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($updated->matchingAiAttemptedAt);
        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testAiMatchOnlySharesCandidatesWithinThreeWeeksOfTheReferenceDate(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $withinWindow = $this->createTransaction('2026-10-10', -99.0, 'Dans la fenêtre');
        $outsideWindow = $this->createTransaction('2026-12-01', -99.0, 'Hors fenêtre');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->once())
            ->method('complete')
            ->with($this->callback(
                fn(LlmRequest $r) => str_contains($r->prompt, 'id=' . $withinWindow->id)
                    && !str_contains($r->prompt, 'id=' . $outsideWindow->id)
            ))
            ->willReturn(new LlmResponse('{"transaction_id":null}', ['transaction_id' => null], 10, 10));

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->addToAssertionCount(1);
    }
}
