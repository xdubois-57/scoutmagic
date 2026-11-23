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
use Modules\LlmConnector\Api\LlmTier;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
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
            $this->journalService, $llmConnector, $this->receiptService()
        );
    }

    /**
     * The same service without anything able to move a receipt onto an
     * account — the shape a caller that only ever matches attributed
     * receipts builds.
     */
    private function serviceWithoutFiling(): ReceiptMatchingService
    {
        return new ReceiptMatchingService(
            $this->attachmentRepository, $this->transactionRepository, $this->transactionAttachmentRepository,
            $this->journalService, null
        );
    }

    private function receiptService(): \Modules\Finance\Service\ReceiptService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new \Modules\Finance\Service\ReceiptService(
            $this->attachmentRepository,
            new AccountRepository($this->pdo, $encryption),
            $this->transactionAttachmentRepository,
            new \Core\File\EncryptedFileStorageService(
                new \Core\File\FileRepository($this->pdo),
                $encryption,
                sys_get_temp_dir()
            ),
            $this->transactionRepository
        );
    }

    /**
     * A receipt in the sorting pile: no account at all, which is where a
     * document arriving by e-mail from an address nothing could place
     * lands.
     */
    private function createUnattributedReceipt(?float $suggestedAmount, ?string $suggestedDate, string $uploadedAt): Attachment
    {
        $id = $this->attachmentRepository->create(
            null, $this->fileId, 'application/pdf', 'facture.pdf', $suggestedAmount, $suggestedDate, null, null
        );
        $this->pdo->prepare('UPDATE finance_attachments SET uploaded_at = ? WHERE id = ?')->execute([$uploadedAt, $id]);

        $receipt = $this->attachmentRepository->findById($id);
        \assert($receipt !== null);

        return $receipt;
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

    /**
     * A receipt in « Compte inconnu » that arrived before its movement is
     * matched, and filed on the movement's account, by the pass an import
     * triggers (#1342 §B). That pass selected by account, and the sorting
     * pile has none: such a receipt was never looked at again.
     */
    public function testMatchPendingReceiptsAlsoMatchesTheSortingPile(): void
    {
        $receipt = $this->createUnattributedReceipt(42.0, '2026-10-01', '2026-10-01 10:00:00');
        $transaction = $this->createTransaction('2026-10-02', -42.0, 'Arrivé après le reçu');

        $matched = $this->service()->matchPendingReceiptsForAccount($this->accountId);

        $this->assertSame(1, $matched);
        $this->assertSame([$transaction->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $this->assertSame($this->accountId, $this->attachmentRepository->findById($receipt->id)?->accountId);
        // And its file follows it, or a download would still answer to
        // the pile's boundary rather than the account's.
        $stmt = $this->pdo->prepare('SELECT owner_type, owner_id, role_min FROM files WHERE id = ?');
        $stmt->execute([$receipt->fileId]);
        $file = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($file);
        $this->assertSame(\Modules\Finance\File\FinanceAccountOwnershipChecker::OWNER_TYPE, $file['owner_type']);
        $this->assertSame($this->accountId, (int) $file['owner_id']);
        $this->assertSame('intendant', $file['role_min'], "the account's role_min_view, not the pile's floor");
    }

    /**
     * The pass an import triggers offers the pile THAT account's movements:
     * they are the new ones. Every account's would mean reading and
     * decrypting the whole movement table per pile receipt per account.
     */
    public function testThePostImportPassOffersThePileOnlyTheImportedAccountsMovements(): void
    {
        $otherAccountId = (new AccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))
            ->create('Autre', Account::TYPE_BANK, null, null, null, 'intendant');
        $receipt = $this->createUnattributedReceipt(42.0, '2026-10-01', '2026-10-01 10:00:00');
        $elsewhere = $this->createTransaction('2026-10-02', -42.0, 'Sur un autre compte', $otherAccountId);

        $this->assertSame(0, $this->service()->matchPendingReceiptsForAccount($this->accountId));
        $this->assertNull($this->attachmentRepository->findById($receipt->id)?->accountId);

        // …and the other account's own import does offer it.
        $this->assertSame(1, $this->service()->matchPendingReceiptsForAccount($otherAccountId));
        $this->assertSame([$elsewhere->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $this->assertSame($otherAccountId, $this->attachmentRepository->findById($receipt->id)?->accountId);
    }

    /** Nothing to answer it, nothing done: the receipt stays in the pile, unfiled. */
    public function testASortingPileReceiptWithNoMatchStaysInThePile(): void
    {
        $receipt = $this->createUnattributedReceipt(42.0, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -7.0, 'Autre montant');

        $this->assertSame(0, $this->service()->matchPendingReceiptsForAccount($this->accountId));
        $this->assertNull($this->attachmentRepository->findById($receipt->id)?->accountId);
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
        $llmConnector->method('isTierAvailable')->willReturn(true);
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

        $llmConnector = $this->createStub(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(new LlmResponse('{"transaction_id":9999}', ['transaction_id' => 9999], 10, 10));

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testAiMatchAcceptsAnExplicitNullAsNoMatch(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createStub(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
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

    /**
     * A connector down for the moment is not a question put to the model
     * (#1342 §B): the receipt stays open to the AI pass, and the screen
     * does not say « IA : aucun mouvement trouvé » about it.
     */
    public function testDoesNotMarkAnAttemptWhileTheConnectorIsUnavailable(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(false);
        $llmConnector->expects($this->never())->method('complete');

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertNull($this->attachmentRepository->findById($receipt->id)?->matchingAiAttemptedAt);
    }

    /**
     * A connector with models, but none for the tier the request asks:
     * the call would fail before any provider, so nothing is marked.
     */
    /**
     * A pile receipt's one AI attempt, made during the pass of one
     * account, is offered every account's movements: spent on that
     * account's alone, it would be gone before an import touching
     * several reached the account holding the right movement.
     */
    public function testThePileReceiptsOneAiAttemptSeesEveryAccount(): void
    {
        $otherAccountId = (new AccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))
            ->create('Autre', Account::TYPE_BANK, null, null, null, 'intendant');
        $receipt = $this->createUnattributedReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -99.0, 'Sur ce compte, sans rapport');
        $right = $this->createTransaction('2026-10-03', -12.40, 'Delhaize', $otherAccountId);

        $offered = [];
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
        $llmConnector->expects($this->once())->method('complete')->willReturnCallback(
            static function (LlmRequest $request) use (&$offered, $right): LlmResponse {
                $offered[] = $request->prompt;

                return new LlmResponse('{}', ['transaction_id' => $right->id], 10, 10);
            }
        );

        $this->service($llmConnector)->matchPendingReceiptsForAccount($this->accountId);

        $this->assertStringContainsString('Delhaize', $offered[0] ?? '', "the other account's movement was offered");
        $this->assertSame([$right->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $this->assertSame($otherAccountId, $this->attachmentRepository->findById($receipt->id)?->accountId);
    }

    /**
     * Without a model to ask, the pile receipt's AI candidates — every
     * account's movements, read and decrypted — are never fetched.
     */
    public function testThePassReadsNoOtherAccountWhenNoModelCanBeAsked(): void
    {
        $this->createUnattributedReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -99.0, 'Sans rapport');

        $transactions = $this->getMockBuilder(TransactionRepository::class)
            ->setConstructorArgs([$this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))])
            ->onlyMethods(['findAll'])
            ->getMock();
        $transactions->expects($this->never())->method('findAll');

        $noCheapModel = $this->createStub(LlmConnectorInterface::class);
        $noCheapModel->method('isAvailable')->willReturn(true);
        $noCheapModel->method('isTierAvailable')->willReturn(false);

        $service = new ReceiptMatchingService(
            $this->attachmentRepository, $transactions, $this->transactionAttachmentRepository,
            $this->journalService, $noCheapModel, $this->receiptService()
        );
        $this->assertSame(0, $service->matchPendingReceiptsForAccount($this->accountId));
    }

    public function testDoesNotMarkAnAttemptWithoutACheapModel(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturnCallback(static fn(LlmTier $tier): bool => $tier !== LlmTier::CHEAP);
        $llmConnector->expects($this->never())->method('complete');

        $this->service($llmConnector)->matchReceipt($receipt);

        $this->assertNull($this->attachmentRepository->findById($receipt->id)?->matchingAiAttemptedAt);
    }

    public function testDoesNotMarkAnAttemptWhenNoConnectorIsConfiguredAtAll(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $this->service(null)->matchReceipt($receipt);

        $this->assertNull($this->attachmentRepository->findById($receipt->id)?->matchingAiAttemptedAt);
    }

    /** …so the model is asked once it is back, on the next pass. */
    public function testTheModelIsAskedOnceTheConnectorIsBack(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $transaction = $this->createTransaction('2026-10-05', -12.40, 'Delhaize');

        $down = $this->createStub(LlmConnectorInterface::class);
        $down->method('isAvailable')->willReturn(false);
        $this->service($down)->matchReceipt($receipt);

        $up = $this->createMock(LlmConnectorInterface::class);
        $up->method('isAvailable')->willReturn(true);
        $up->method('isTierAvailable')->willReturn(true);
        $up->expects($this->once())->method('complete')
            ->willReturn(new LlmResponse('{}', ['transaction_id' => $transaction->id], 10, 10));
        $reloaded = $this->attachmentRepository->findById($receipt->id);
        \assert($reloaded !== null);
        $this->service($up)->matchReceipt($reloaded);

        $this->assertSame([$transaction->id], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    public function testMarksAttemptWhenLlmCallFails(): void
    {
        $receipt = $this->createReceipt(12.50, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-05', -99.0, 'Montant différent');

        $llmConnector = $this->createStub(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
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
        $llmConnector->method('isTierAvailable')->willReturn(true);
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
    // --- The sorting pile: matched against every account ---------------

    /**
     * A receipt with no account used to be skipped outright, which left
     * the one case the sorting pile exists for at its least helpful: the
     * module knows the amount, the date and the merchant, and refuses to
     * look at the movement answering all three because it does not yet
     * know which account to look in. Backwards — the movement is what
     * NAMES the account.
     */
    public function testAReceiptWithNoAccountIsMatchedAgainstEveryAccount(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAccountId = (new AccountRepository($this->pdo, $encryption))
            ->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $receipt = $this->createUnattributedReceipt(42.00, '2026-10-01', '2026-10-01 10:00:00');
        $movement = $this->createTransaction('2026-10-02', -42.00, 'Fournitures', $otherAccountId);

        $this->service()->matchReceipt($receipt);

        $this->assertSame(
            [$movement->id],
            $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id)
        );
    }

    /**
     * Finding the movement settles both questions at once: the receipt
     * is filed on the account the movement belongs to, because
     * ReceiptService::associate() guarantees a receipt and its movements
     * share one.
     */
    public function testTheAccountIsTakenFromTheMovementThatWasMatched(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAccountId = (new AccountRepository($this->pdo, $encryption))
            ->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $receipt = $this->createUnattributedReceipt(42.00, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -42.00, 'Fournitures', $otherAccountId);

        $this->service()->matchReceipt($receipt);

        $filed = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($filed);
        $this->assertSame($otherAccountId, $filed->accountId);
    }

    /**
     * Filing the receipt is refused by the database (its file's owner
     * cannot be rewritten): nothing is associated, and the receipt stays
     * in the pile rather than on a movement of an account it is not on.
     *
     * The receipt is not attached — that is the right outcome — but the
     * refusal is no longer silent (#1227): a match was found and lost, and
     * one warning says so, naming the receipt and the account it was to be
     * filed under. The exception's own message is not copied into the
     * entry: a database failure can quote the values of the statement.
     */
    public function testAReceiptTheDatabaseRefusesToFileStaysInThePileUnmatched(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAccountId = (new AccountRepository($this->pdo, $encryption))
            ->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $receipt = $this->createUnattributedReceipt(42.00, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -42.00, 'Fournitures', $otherAccountId);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_file_owner BEFORE UPDATE OF owner_id ON files
             BEGIN SELECT RAISE(ABORT, 'refused by the test'); END"
        );

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $kept = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($kept, 'the receipt was deleted, not left in the pile');
        $this->assertNull($kept->accountId);
        $this->assertFalse($this->pdo->inTransaction());

        $entries = $this->pdo->query(
            "SELECT category, event_type, level, context FROM event_log ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $entries, 'one entry, and no « filed » or « associated » line for a match that was lost');
        $this->assertSame('finance', $entries[0]['category']);
        $this->assertSame('receipt_auto_filing_failed', $entries[0]['event_type']);
        $this->assertSame('warning', $entries[0]['level']);
        $context = json_decode((string) $entries[0]['context'], true);
        $this->assertSame($receipt->id, $context['attachment_id']);
        $this->assertSame($otherAccountId, $context['account_id']);
        $this->assertStringNotContainsString('refused by the test', (string) $entries[0]['context']);
    }

    /**
     * Nothing is loosened to look wider: the amount is still the hard
     * gate, and an ambiguous set still ends in silence — on the sorting
     * pile more than anywhere, since a wrong guess there invents an
     * account as well as an association.
     */
    public function testAnAmountFoundOnTwoAccountsLeavesTheReceiptInThePile(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAccountId = (new AccountRepository($this->pdo, $encryption))
            ->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $receipt = $this->createUnattributedReceipt(42.00, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -42.00, 'Fournitures', $otherAccountId);
        $this->createTransaction('2026-10-02', -42.00, 'Fournitures', $this->accountId);

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $filed = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($filed);
        $this->assertNull($filed->accountId);
    }

    /**
     * Without anything able to file the receipt, a match could only be
     * recorded by breaking the invariant that a receipt and its movements
     * share an account. Not matching is the honest outcome.
     */
    public function testWithoutAWayToFileItNothingIsMatchedForThePile(): void
    {
        $receipt = $this->createUnattributedReceipt(42.00, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -42.00, 'Fournitures');

        $this->serviceWithoutFiling()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
    }

    /**
     * An attributed receipt keeps looking at its own account alone: a
     * movement on another account is not its business, and matching it
     * there would move a receipt somebody had already filed.
     */
    public function testAnAttributedReceiptStillOnlySeesItsOwnAccount(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $otherAccountId = (new AccountRepository($this->pdo, $encryption))
            ->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');

        $receipt = $this->createReceipt(42.00, '2026-10-01', '2026-10-01 10:00:00');
        $this->createTransaction('2026-10-02', -42.00, 'Fournitures', $otherAccountId);

        $this->service()->matchReceipt($receipt);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($receipt->id));
        $filed = $this->attachmentRepository->findById($receipt->id);
        $this->assertNotNull($filed);
        $this->assertSame($this->accountId, $filed->accountId);
    }

}
