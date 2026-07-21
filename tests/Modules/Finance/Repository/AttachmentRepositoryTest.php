<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class AttachmentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private AttachmentRepository $repository;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->repository = new AttachmentRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('a.pdf', 'a.pdf', 'application/pdf', 100)"
        );
        $stmt->execute();
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', 42.5, '2026-10-01', null, 7);

        $attachment = $this->repository->findById($id);
        $this->assertNotNull($attachment);
        $this->assertSame('facture.pdf', $attachment->originalFilename);
        $this->assertSame(42.5, $attachment->suggestedAmount);
        $this->assertSame(Attachment::STATUS_ACTIVE, $attachment->status);
    }

    public function testUpdateSuggestedLabelSetsMerchantName(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->assertNull($this->repository->findById($id)->suggestedLabel);

        $this->repository->updateSuggestedLabel($id, 'Delhaize');

        $this->assertSame('Delhaize', $this->repository->findById($id)->suggestedLabel);
    }

    public function testUpdateSuggestedDescriptionSetsOneSentenceSummary(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->assertNull($this->repository->findById($id)->suggestedDescription);

        $this->repository->updateSuggestedDescription($id, 'Achat de fournitures de bureau');

        $this->assertSame('Achat de fournitures de bureau', $this->repository->findById($id)->suggestedDescription);
    }

    public function testSuggestedLabelIsStoredEncryptedNotInPlaintext(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->repository->updateSuggestedLabel($id, 'LA CADRERIE');

        $stmt = $this->pdo->prepare('SELECT suggested_label FROM finance_attachments WHERE id = ?');
        $stmt->execute([$id]);
        $rawLabel = $stmt->fetchColumn();

        $this->assertStringNotContainsString('LA CADRERIE', (string) $rawLabel);
    }

    public function testSuggestedDescriptionIsStoredEncryptedNotInPlaintext(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->repository->updateSuggestedDescription($id, 'Achat confidentiel pour un membre');

        $stmt = $this->pdo->prepare('SELECT suggested_description FROM finance_attachments WHERE id = ?');
        $stmt->execute([$id]);
        $rawDescription = $stmt->fetchColumn();

        $this->assertStringNotContainsString('Achat confidentiel pour un membre', (string) $rawDescription);
    }

    public function testDecryptOrLegacyPlaintextFallsBackForPreEncryptionSuggestedFields(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);
        $this->pdo->prepare('UPDATE finance_attachments SET suggested_label = ?, suggested_description = ? WHERE id = ?')
            ->execute(['Fantasia', 'Achat en clair (legacy)', $id]);

        $attachment = $this->repository->findById($id);

        $this->assertSame('Fantasia', $attachment->suggestedLabel);
        $this->assertSame('Achat en clair (legacy)', $attachment->suggestedDescription);
    }

    public function testFindActiveOrderedExcludesArchived(): void
    {
        $id1 = $this->repository->create(null, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);
        $id2 = $this->repository->create(null, $this->fileId, 'application/pdf', 'b.pdf', null, null, null, null);

        $this->repository->archive($id1);

        $active = $this->repository->findActiveOrdered();
        $this->assertCount(1, $active);
        $this->assertSame($id2, $active[0]->id);
    }

    public function testArchiveNeverDeletesTheRow(): void
    {
        $id = $this->repository->create(null, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);

        $this->repository->archive($id);

        $attachment = $this->repository->findById($id);
        $this->assertNotNull($attachment);
        $this->assertSame(Attachment::STATUS_ARCHIVED, $attachment->status);
    }

    public function testArchiveAllReturnsCountAndOnlyTouchesActive(): void
    {
        $id1 = $this->repository->create(null, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);
        $id2 = $this->repository->create(null, $this->fileId, 'application/pdf', 'b.pdf', null, null, null, null);
        $this->repository->archive($id1);

        $archived = $this->repository->archiveAll();

        $this->assertSame(1, $archived);
        $this->assertSame(Attachment::STATUS_ARCHIVED, $this->repository->findById($id2)->status);
    }

    public function testParentAttachmentIdChainsVersions(): void
    {
        $originalId = $this->repository->create(null, $this->fileId, 'application/pdf', 'v1.pdf', null, null, null, null);
        $replacementId = $this->repository->create(null, $this->fileId, 'application/pdf', 'v2.pdf', null, null, $originalId, null);

        $this->assertSame($originalId, $this->repository->findById($replacementId)->parentAttachmentId);
    }

    // --- findFilteredForAccount() / countFilteredForAccount() ---

    private function accountId(): int
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountRepository = new AccountRepository($this->pdo, $encryption);
        return $accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
    }

    public function testFindFilteredForAccountComputesMovementCount(): void
    {
        $accountId = $this->accountId();
        $id = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'facture.pdf', null, null, null, null);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $transactionId = $transactionRepository->create($accountId, $fiscalYearId, null, '2026-10-01', 'x', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $transactionAttachmentRepository->associate($transactionId, $id);

        $results = $this->repository->findFilteredForAccount($accountId, false, null, 30, 0);

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['movement_count']);
    }

    public function testFindFilteredForAccountPendingOnlyExcludesAssociated(): void
    {
        $accountId = $this->accountId();
        $pendingId = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'pending.pdf', null, null, null, null);
        $associatedId = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'associated.pdf', null, null, null, null);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $transactionId = $transactionRepository->create($accountId, $fiscalYearId, null, '2026-10-01', 'x', -1.0, null, null, Transaction::SOURCE_MANUAL, null);
        $transactionAttachmentRepository->associate($transactionId, $associatedId);

        $results = $this->repository->findFilteredForAccount($accountId, true, null, 30, 0);

        $this->assertCount(1, $results);
        $this->assertSame($pendingId, $results[0]['attachment']->id);
    }

    public function testFindFilteredForAccountSearchesFilenameLabelAndDescription(): void
    {
        $accountId = $this->accountId();
        $byFilename = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'delhaize-facture.pdf', null, null, null, null);
        $byLabel = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'x.pdf', null, null, null, null);
        $this->repository->updateSuggestedLabel($byLabel, 'Colruyt Ottignies');
        $byDescription = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'y.pdf', null, null, null, null);
        $this->repository->updateSuggestedDescription($byDescription, 'Achat de matériel de camp');
        $this->repository->create($accountId, $this->fileId, 'application/pdf', 'unrelated.pdf', null, null, null, null);

        $this->assertCount(1, $this->repository->findFilteredForAccount($accountId, false, 'delhaize', 30, 0));
        $this->assertCount(1, $this->repository->findFilteredForAccount($accountId, false, 'colruyt', 30, 0));
        $this->assertCount(1, $this->repository->findFilteredForAccount($accountId, false, 'matériel', 30, 0));
        $this->assertCount(0, $this->repository->findFilteredForAccount($accountId, false, 'introuvable', 30, 0));
    }

    public function testFindFilteredForAccountIgnoresOtherAccounts(): void
    {
        $accountId = $this->accountId();
        $otherAccountId = $this->accountId();
        $this->repository->create($otherAccountId, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);

        $this->assertSame([], $this->repository->findFilteredForAccount($accountId, false, null, 30, 0));
        $this->assertSame(0, $this->repository->countFilteredForAccount($accountId, false, null));
    }

    public function testCountActiveByAccountIdOnlyCountsActiveOwnAccountReceipts(): void
    {
        $accountId = $this->accountId();
        $otherAccountId = $this->accountId();
        $this->repository->create($accountId, $this->fileId, 'application/pdf', 'a.pdf', null, null, null, null);
        $archivedId = $this->repository->create($accountId, $this->fileId, 'application/pdf', 'b.pdf', null, null, null, null);
        $this->repository->archive($archivedId);
        $this->repository->create($otherAccountId, $this->fileId, 'application/pdf', 'c.pdf', null, null, null, null);

        $this->assertSame(1, $this->repository->countActiveByAccountId($accountId));
    }

    public function testCountFilteredForAccountMatchesResultCountAcrossPages(): void
    {
        $accountId = $this->accountId();
        for ($i = 0; $i < 5; $i++) {
            $this->repository->create($accountId, $this->fileId, 'application/pdf', "facture-{$i}.pdf", null, null, null, null);
        }

        $this->assertSame(5, $this->repository->countFilteredForAccount($accountId, false, null));
        $this->assertCount(3, $this->repository->findFilteredForAccount($accountId, false, null, 3, 0));
        $this->assertCount(2, $this->repository->findFilteredForAccount($accountId, false, null, 3, 3));
    }
}
