<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\ReceiptService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class ReceiptServiceTest extends TestCase
{
    private \PDO $pdo;
    private ReceiptService $service;
    private AccountRepository $accountRepository;
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private string $storagePath;
    private ?int $sharedFiscalYearId = null;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accountRepository = new AccountRepository($this->pdo, $encryption);
        $this->attachmentRepository = new AttachmentRepository($this->pdo);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/finance_receipt_service_test_' . uniqid();
        $fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, $this->storagePath);

        $this->service = new ReceiptService($this->attachmentRepository, $this->accountRepository, $this->transactionAttachmentRepository, $fileStorage);

        $this->accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTransaction(): int
    {
        if ($this->sharedFiscalYearId === null) {
            $this->sharedFiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        }

        $stmt = $this->pdo->prepare('INSERT INTO finance_transactions (account_id, fiscal_year_id, transaction_date, label, amount, source) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$this->accountId, $this->sharedFiscalYearId, '2026-10-01', 'x', -1.0, 'manual']);
        return (int) $this->pdo->lastInsertId();
    }

    public function testUploadRejectsUnsupportedMimeType(): void
    {
        $this->expectException(FinanceException::class);
        $this->service->upload('content', 'application/zip', 'a.zip', $this->accountId, null, null, 1);
    }

    public function testUploadRejectsUnknownAccount(): void
    {
        $this->expectException(FinanceException::class);
        $this->service->upload('content', 'application/pdf', 'a.pdf', 9999, null, null, 1);
    }

    public function testUploadCreatesActiveAttachment(): void
    {
        $attachment = $this->service->upload('%PDF content', 'application/pdf', 'facture.pdf', $this->accountId, 12.5, '2026-10-01', 7);

        $this->assertSame('facture.pdf', $attachment->originalFilename);
        $this->assertSame(Attachment::STATUS_ACTIVE, $attachment->status);
        $this->assertSame($this->accountId, $attachment->accountId);
        $this->assertSame(12.5, $attachment->suggestedAmount);
        $this->assertSame(Attachment::SUGGESTED_SOURCE_MANUAL, $attachment->suggestedSource);
        $this->assertSame(7, $attachment->uploadedBy);
    }

    public function testUploadStoresFileWithAccountRoleMinView(): void
    {
        $adminAccountId = $this->accountRepository->create('Compte admin', Account::TYPE_BANK, null, null, null, 'admin');

        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $adminAccountId, null, null, 1);

        $file = (new FileRepository($this->pdo))->findById($attachment->fileId);
        $this->assertSame('admin', $file->roleMin);
    }

    public function testUploadWithoutManualDataHasNullSuggestedSource(): void
    {
        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);

        $this->assertNull($attachment->suggestedSource);
    }

    public function testReplaceArchivesOldAndCreatesChainedNewAttachment(): void
    {
        $original = $this->service->upload('content v1', 'application/pdf', 'v1.pdf', $this->accountId, null, null, 1);

        $replacement = $this->service->replace($original->id, 'content v2', 'application/pdf', 'v2.pdf', 1);

        $this->assertSame(Attachment::STATUS_ARCHIVED, $this->attachmentRepository->findById($original->id)->status);
        $this->assertSame($original->id, $replacement->parentAttachmentId);
        $this->assertSame(Attachment::STATUS_ACTIVE, $replacement->status);
        $this->assertSame($this->accountId, $replacement->accountId);
    }

    public function testReplaceTransfersMovementAssociations(): void
    {
        $original = $this->service->upload('content', 'application/pdf', 'v1.pdf', $this->accountId, null, null, 1);
        $transactionId = $this->createTransaction();
        $this->transactionAttachmentRepository->associate($transactionId, $original->id);

        $replacement = $this->service->replace($original->id, 'content v2', 'application/pdf', 'v2.pdf', 1);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($original->id));
        $this->assertSame([$transactionId], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($replacement->id));
    }

    public function testReplaceThrowsForUnknownAttachment(): void
    {
        $this->expectException(FinanceException::class);
        $this->service->replace(9999, 'content', 'application/pdf', 'a.pdf', 1);
    }

    public function testDeleteArchivesButNeverPhysicallyDeletes(): void
    {
        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);

        $this->service->delete($attachment->id);

        $stillThere = $this->attachmentRepository->findById($attachment->id);
        $this->assertNotNull($stillThere);
        $this->assertSame(Attachment::STATUS_ARCHIVED, $stillThere->status);
    }

    public function testDeleteRemovesMovementAssociations(): void
    {
        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);
        $transactionId = $this->createTransaction();
        $this->transactionAttachmentRepository->associate($transactionId, $attachment->id);

        $this->service->delete($attachment->id);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachment->id));
    }

    public function testDeleteThrowsForUnknownAttachment(): void
    {
        $this->expectException(FinanceException::class);
        $this->service->delete(9999);
    }

    public function testAssociateLinksAttachmentToMovements(): void
    {
        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);
        $t1 = $this->createTransaction();
        $t2 = $this->createTransaction();

        $this->service->associate($attachment->id, [$t1, $t2]);

        $this->assertCount(2, $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachment->id));
    }

    public function testAssociateThrowsForUnknownAttachment(): void
    {
        $this->expectException(FinanceException::class);
        $this->service->associate(9999, [1]);
    }

    public function testDissociateRemovesOneLink(): void
    {
        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);
        $transactionId = $this->createTransaction();
        $this->service->associate($attachment->id, [$transactionId]);

        $this->service->dissociate($attachment->id, $transactionId);

        $this->assertSame([], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachment->id));
    }

    public function testListPendingExcludesAssociatedAttachments(): void
    {
        $pending = $this->service->upload('content', 'application/pdf', 'pending.pdf', $this->accountId, null, null, 1);
        $associated = $this->service->upload('content', 'application/pdf', 'associated.pdf', $this->accountId, null, null, 1);
        $this->service->associate($associated->id, [$this->createTransaction()]);

        $result = $this->service->listPending();

        $ids = array_map(fn(Attachment $a) => $a->id, $result);
        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($associated->id, $ids);
    }

    public function testListPendingExcludesArchivedAttachments(): void
    {
        $attachment = $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);
        $this->service->delete($attachment->id);

        $result = $this->service->listPending();

        $this->assertSame([], $result);
    }

    public function testListPendingScopedToAccount(): void
    {
        $otherAccountId = $this->accountRepository->create('Autre compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->service->upload('content', 'application/pdf', 'a.pdf', $this->accountId, null, null, 1);
        $this->service->upload('content', 'application/pdf', 'b.pdf', $otherAccountId, null, null, 1);

        $result = $this->service->listPending($this->accountId);

        $this->assertCount(1, $result);
        $this->assertSame($this->accountId, $result[0]->accountId);
    }
}
