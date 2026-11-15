<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Task\PurgeOldMovementsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The purge of an orphaned receipt, on the engine production runs.
 *
 * The SQLite suite runs without foreign keys; MariaDB enforces
 * `fk_fatt_file`, which has no ON DELETE clause. So the order the handler
 * takes — the file (blob, then `files` row) before the receipt row that
 * points at it — is refused at the `files` row, and lands in the catch
 * that journals the failure.
 *
 * Pins current behaviour: the blob is already unlinked when the refusal
 * comes, so the receipt row and its `files` row survive pointing at a
 * file that is gone from the disk.
 */
#[Group('database')]
final class PurgeOldMovementsHandlerOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private string $storagePath;

    protected function setUp(): void
    {
        // First: tearDown() reads it, and productionEngine() may skip the test.
        $this->storagePath = sys_get_temp_dir() . '/finance_purge_mysql_test_' . uniqid();
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storagePath);
    }

    public function testAnOrphanedReceiptTheForeignKeyProtectsIsJournaledAsAFailedPurge(): void
    {
        $this->assertContains(
            $this->pdo->query(
                "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_fatt_file'"
            )->fetchColumn(),
            ['RESTRICT', 'NO ACTION'],
            'the premise: this engine refuses to delete a file a receipt points at'
        );

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2014-2015', '2014-09-01', '2015-08-31')"
        );
        $fiscalYearId = (int) $this->pdo->lastInsertId();
        $accountId = (new AccountRepository($this->pdo, $this->encryption))
            ->create('Compte', Account::TYPE_BANK, null, null, null, 'intendant');
        $transactionId = (new TransactionRepository($this->pdo, $this->encryption))->create(
            $accountId, $fiscalYearId, 'ref-1', '2015-01-01', 'x', -10.0, null, null, Transaction::SOURCE_MANUAL, null
        );
        $files = new FileRepository($this->pdo);
        $fileId = (new EncryptedFileStorageService($files, $this->encryption, $this->storagePath))
            ->store('receipt content', 'application/pdf', 'r.pdf', 'finance/receipts', 'intendant');
        $diskPath = $this->storagePath . '/' . $files->findById($fileId)?->relativePath;
        $attachments = new AttachmentRepository($this->pdo, $this->encryption);
        $attachmentId = $attachments->create($accountId, $fileId, 'application/pdf', 'r.pdf', null, null, null, 1);
        (new TransactionAttachmentRepository($this->pdo))->associate($transactionId, $attachmentId);
        $this->assertFileExists($diskPath);

        (new PurgeOldMovementsHandler())->handle([], $this->taskContext());

        $movements = $this->pdo->prepare('SELECT COUNT(*) FROM finance_transactions WHERE id = ?');
        $movements->execute([$transactionId]);
        $this->assertSame(0, (int) $movements->fetchColumn(), 'the movements are purged all the same');
        $failure = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'receipt_purge_failed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $failure);
        $this->assertStringStartsWith('Suppression du reçu orphelin échouée : ', $failure[0]['description']);
        $this->assertStringContainsString('fk_fatt_file', $failure[0]['description']);
        $this->assertSame(['attachment_id' => $attachmentId], json_decode($failure[0]['context'], true));
        $this->assertStringContainsString(
            '0 reçu(s) orphelin(s) supprimé(s)',
            (string) $this->pdo->query("SELECT description FROM event_log WHERE event_type = 'movements_purged'")
                ->fetchColumn()
        );

        // The defect, pinned: both rows survive, the bytes do not.
        $this->assertNotNull($attachments->findById($attachmentId));
        $this->assertNotNull($files->findById($fileId));
        $this->assertFileDoesNotExist($diskPath);
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            $this->storagePath
        );
    }
}
