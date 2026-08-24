<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\Finance\File\FinanceAccountOwnershipChecker;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ReceiptService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The two halves of "a receipt file knows its account": stamping the owner
 * pair on a NEW receipt, and catching up every receipt stored before the
 * rule existed.
 *
 * The backfill is the half that is easy to ship broken, because forgetting
 * it is invisible: new receipts are guarded, the feature demos correctly,
 * and every receipt the unit already had stays reachable by its direct
 * link — which is the entire hole the iteration set out to close.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceiptFileOwnershipTest extends TestCase
{
    private \PDO $pdo;
    private ReceiptService $service;
    private AttachmentRepository $attachmentRepository;
    private FileRepository $fileRepository;
    private SettingService $settingService;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->fileRepository = new FileRepository($this->pdo);
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->storagePath = sys_get_temp_dir() . '/receipt_ownership_' . uniqid();

        $this->service = new ReceiptService(
            $this->attachmentRepository,
            new AccountRepository($this->pdo, $encryption),
            new TransactionAttachmentRepository($this->pdo),
            new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath),
            new TransactionRepository($this->pdo, $encryption),
            $this->settingService
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            foreach (glob($this->storagePath . '/finance/receipts/*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function testUploadStampsTheAccountOnTheFile(): void
    {
        $accountId = $this->createAccount();

        $attachment = $this->service->upload('%PDF-1.4', 'application/pdf', 'recu.pdf', $accountId, null, null, null);

        $file = $this->fileRepository->findById($attachment->fileId);
        $this->assertNotNull($file);
        $this->assertSame(FinanceAccountOwnershipChecker::OWNER_TYPE, $file->ownerType);
        $this->assertSame($accountId, $file->ownerId);
        // role_min is the floor and keeps following role_min_view; the
        // owner pair is a different question and does not replace it.
        $this->assertSame('intendant', $file->roleMin);
        // A receipt belongs to an account, never to whoever uploaded it.
        $this->assertNull($file->ownerMemberId);
    }

    public function testReplaceStampsTheAccountOnTheNewFileToo(): void
    {
        $accountId = $this->createAccount();
        $original = $this->service->upload('%PDF-1.4', 'application/pdf', 'recu.pdf', $accountId, null, null, null);

        $replacement = $this->service->replace($original->id, '%PDF-1.5', 'application/pdf', 'recu-v2.pdf', null);

        $file = $this->fileRepository->findById($replacement->fileId);
        $this->assertNotNull($file);
        $this->assertSame(FinanceAccountOwnershipChecker::OWNER_TYPE, $file->ownerType);
        $this->assertSame($accountId, $file->ownerId);
    }

    public function testTheBackfillGivesAnOldReceiptItsAccount(): void
    {
        $accountId = $this->createAccount();
        $fileId = $this->createLegacyReceipt($accountId);

        $this->assertNull($this->fileRepository->findById($fileId)?->ownerType, 'precondition: stored before the rule existed');

        $this->service->ensureReceiptFileOwnership();

        $file = $this->fileRepository->findById($fileId);
        $this->assertNotNull($file);
        $this->assertSame(FinanceAccountOwnershipChecker::OWNER_TYPE, $file->ownerType);
        $this->assertSame($accountId, $file->ownerId);
    }

    public function testTheBackfillReachesEveryAccountsReceiptsInOneGo(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();
        $a = $this->createLegacyReceipt($first);
        $b = $this->createLegacyReceipt($first);
        $c = $this->createLegacyReceipt($second);

        $this->service->ensureReceiptFileOwnership();

        $this->assertSame($first, $this->fileRepository->findById($a)?->ownerId);
        $this->assertSame($first, $this->fileRepository->findById($b)?->ownerId);
        $this->assertSame($second, $this->fileRepository->findById($c)?->ownerId);
    }

    public function testAReceiptWithNoAccountIsStampedAsOwnedByNothing(): void
    {
        $fileId = $this->createLegacyReceipt(null);

        $this->service->ensureReceiptFileOwnership();

        $file = $this->fileRepository->findById($fileId);
        $this->assertNotNull($file);
        // Not left ownerless: an ownerless file falls back to role_min
        // alone, which is exactly the reading this iteration removes.
        // owner_id 0 resolves to no account and the checker denies it.
        $this->assertSame(FinanceAccountOwnershipChecker::OWNER_TYPE, $file->ownerType);
        $this->assertSame(0, $file->ownerId);
    }

    public function testTheBackfillNeverTouchesAFileThatAlreadyHasAnOwner(): void
    {
        $fileId = $this->fileRepository->create(
            'other/thing.enc', 'x.pdf', 'application/pdf', 10, 'identified', 'groups', null, true,
            null, 'groups_post_media', 42
        );
        $this->pdo->prepare('INSERT INTO finance_attachments (account_id, file_id, mime_type, original_filename) VALUES (?, ?, ?, ?)')
            ->execute([$this->createAccount(), $fileId, 'application/pdf', 'x.pdf']);

        $this->service->ensureReceiptFileOwnership();

        $file = $this->fileRepository->findById($fileId);
        $this->assertSame('groups_post_media', $file?->ownerType, 'a file owned by another module is none of finance\'s business');
        $this->assertSame(42, $file?->ownerId);
    }

    public function testTheBackfillRunsOnceAndIsThenAFreeNoOp(): void
    {
        $accountId = $this->createAccount();
        $this->createLegacyReceipt($accountId);

        $this->service->ensureReceiptFileOwnership();
        $this->assertSame('1', $this->settingService->get('receipt_file_ownership_backfilled', 'finance', '0'));

        // A receipt that somehow arrives ownerless AFTER the flag is set
        // stays untouched, which is the point of the flag: the second call
        // must not re-scan every file on every page load for ever.
        $late = $this->createLegacyReceipt($accountId);
        $this->service->ensureReceiptFileOwnership();

        $this->assertNull($this->fileRepository->findById($late)?->ownerType);
    }

    public function testWithoutASettingServiceTheBackfillIsSimplyNotOffered(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountId = $this->createAccount();
        $fileId = $this->createLegacyReceipt($accountId);

        // The session-less fixture shape (Tests\Fixtures\ReferenceDataset)
        // builds this service without settings; it must degrade to "does
        // nothing", never to a crash.
        $withoutSettings = new ReceiptService(
            $this->attachmentRepository,
            new AccountRepository($this->pdo, $encryption),
            new TransactionAttachmentRepository($this->pdo),
            new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath),
            new TransactionRepository($this->pdo, $encryption)
        );
        $withoutSettings->ensureReceiptFileOwnership();

        $this->assertNull($this->fileRepository->findById($fileId)?->ownerType);
    }

    // --- fixtures ---

    private function createAccount(): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO finance_accounts (name, account_type, section_id, role_min_view, status) VALUES ('Compte', 'bank', NULL, 'intendant', 'active')"
        );
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** A receipt as it was stored before the owner pair existed. */
    private function createLegacyReceipt(?int $accountId): int
    {
        $fileId = $this->fileRepository->create(
            'finance/receipts/' . uniqid() . '.enc', 'recu.pdf', 'application/pdf', 10, 'intendant', 'finance', null, true
        );
        $this->pdo->prepare('INSERT INTO finance_attachments (account_id, file_id, mime_type, original_filename) VALUES (?, ?, ?, ?)')
            ->execute([$accountId, $fileId, 'application/pdf', 'recu.pdf']);

        return $fileId;
    }
}
