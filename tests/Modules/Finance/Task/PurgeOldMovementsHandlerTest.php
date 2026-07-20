<?php

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
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Task\PurgeOldMovementsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class PurgeOldMovementsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AccountRepository $accountRepository;
    private FiscalYearRepository $fiscalYearRepository;
    private TransactionRepository $transactionRepository;
    private TransactionAttachmentRepository $transactionAttachmentRepository;
    private AttachmentRepository $attachmentRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private BalanceService $balanceService;
    private EncryptedFileStorageService $fileStorage;
    private string $storagePath;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->fiscalYearRepository = new FiscalYearRepository($this->pdo);
        $this->transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $this->transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $this->attachmentRepository = new AttachmentRepository($this->pdo);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $this->balanceService = new BalanceService($this->checkpointRepository, $this->transactionRepository);
        $this->storagePath = sys_get_temp_dir() . '/finance_purge_test_' . uniqid();
        $this->fileStorage = new EncryptedFileStorageService(new FileRepository($this->pdo), $this->encryption, $this->storagePath);

        $this->accountId = $this->accountRepository->create('Compte', Account::TYPE_BANK, null, 'BE00000000000001', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$this->accountId]);
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

    private function createTaskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );
    }

    private function createTransaction(int $fiscalYearId, string $date, float $amount, ?string $ref = null): int
    {
        return $this->transactionRepository->create(
            $this->accountId, $fiscalYearId, $ref ?? 'ref-' . uniqid(), $date, 'x', $amount, null, null, Transaction::SOURCE_MANUAL, null
        );
    }

    public function testHandleIsNoOpWhenNoFiscalYearIsOldEnough(): void
    {
        $fiscalYearId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $this->createTransaction($fiscalYearId, '2026-10-01', -20.0);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->accountId));
    }

    public function testHandlePurgesOldestQualifyingFiscalYearOnly(): void
    {
        $oldest = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $middle = $this->fiscalYearRepository->create('2015-2016', '2015-09-01', '2016-08-31');
        $this->createTransaction($oldest, '2015-01-01', -10.0);
        $this->createTransaction($middle, '2016-01-01', -20.0);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $remaining = $this->transactionRepository->findByAccountId($this->accountId);
        $this->assertCount(1, $remaining);
        $this->assertSame($middle, $remaining[0]->fiscalYearId);
    }

    public function testHandleDeletesTransactionsInPurgedFiscalYear(): void
    {
        $fiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $this->createTransaction($fiscalYearId, '2015-01-01', -10.0);
        $this->createTransaction($fiscalYearId, '2015-02-01', -20.0);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertCount(0, $this->transactionRepository->findByAccountId($this->accountId));
    }

    public function testHandlePhysicallyDeletesOrphanedAttachment(): void
    {
        $fiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $transactionId = $this->createTransaction($fiscalYearId, '2015-01-01', -10.0);

        $fileId = $this->fileStorage->store('receipt content', 'application/pdf', 'r.pdf', 'finance/receipts', 'intendant');
        $attachmentId = $this->attachmentRepository->create(null, $fileId, 'application/pdf', 'r.pdf', null, null, null, 1);
        $this->transactionAttachmentRepository->associate($transactionId, $attachmentId);

        $file = $this->attachmentRepository->findById($attachmentId);
        $diskPath = $this->storagePath . '/' . (new FileRepository($this->pdo))->findById($file->fileId)->relativePath;
        $this->assertFileExists($diskPath);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertNull($this->attachmentRepository->findById($attachmentId));
        $this->assertFileDoesNotExist($diskPath);
    }

    public function testHandleKeepsAttachmentStillAssociatedWithSurvivingTransaction(): void
    {
        $oldFiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $recentFiscalYearId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $oldTransactionId = $this->createTransaction($oldFiscalYearId, '2015-01-01', -10.0);
        $recentTransactionId = $this->createTransaction($recentFiscalYearId, '2026-10-01', -5.0);

        $fileId = $this->fileStorage->store('receipt content', 'application/pdf', 'r.pdf', 'finance/receipts', 'intendant');
        $attachmentId = $this->attachmentRepository->create(null, $fileId, 'application/pdf', 'r.pdf', null, null, null, 1);
        $this->transactionAttachmentRepository->associate($oldTransactionId, $attachmentId);
        $this->transactionAttachmentRepository->associate($recentTransactionId, $attachmentId);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertNotNull($this->attachmentRepository->findById($attachmentId));
        $this->assertSame([$recentTransactionId], $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId));
    }

    public function testHandleKeepsArchivedOrphanedAttachmentEligibleForDeletion(): void
    {
        // An archived (not active) attachment with zero remaining
        // associations is still purged — "actifs ou archivés" per spec.
        $fiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $transactionId = $this->createTransaction($fiscalYearId, '2015-01-01', -10.0);

        $fileId = $this->fileStorage->store('receipt content', 'application/pdf', 'r.pdf', 'finance/receipts', 'intendant');
        $attachmentId = $this->attachmentRepository->create(null, $fileId, 'application/pdf', 'r.pdf', null, null, null, 1);
        $this->transactionAttachmentRepository->associate($transactionId, $attachmentId);
        $this->attachmentRepository->archive($attachmentId);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertNull($this->attachmentRepository->findById($attachmentId));
    }

    public function testHandleCreatesConsolidatedCheckpointAndRemovesOldOnes(): void
    {
        $fiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $this->checkpointRepository->create($this->accountId, '2014-09-01', 1000.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->createTransaction($fiscalYearId, '2015-01-01', -100.0);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $checkpoints = $this->checkpointRepository->findByAccountId($this->accountId);
        $this->assertCount(1, $checkpoints);
        $this->assertSame('2015-08-31', $checkpoints[0]->checkpointDate);
        $this->assertSame(900.0, $checkpoints[0]->balance);
    }

    public function testHandlePreservesBalanceContinuityAfterPurge(): void
    {
        $oldFiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $recentFiscalYearId = $this->fiscalYearRepository->create('2026-2027', '2026-09-01', '2027-08-31');
        $this->checkpointRepository->create($this->accountId, '2014-09-01', 1000.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->createTransaction($oldFiscalYearId, '2015-01-01', -100.0);
        $this->createTransaction($recentFiscalYearId, '2026-10-01', -50.0);

        $account = $this->accountRepository->findById($this->accountId);
        $balanceBefore = $this->balanceService->getBalanceAt($account, new \DateTimeImmutable('2026-10-01'));

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $balanceAfter = $this->balanceService->getBalanceAt($account, new \DateTimeImmutable('2026-10-01'));

        $this->assertSame($balanceBefore, $balanceAfter);
        $this->assertSame(850.0, $balanceAfter);
    }

    public function testHandleJournalsThePurge(): void
    {
        $fiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $this->createTransaction($fiscalYearId, '2015-01-01', -10.0);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $stmt = $this->pdo->prepare("SELECT * FROM event_log WHERE category = 'finance' AND event_type = 'movements_purged'");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertStringNotContainsString('BE00000000000001', $row['description'] ?? '');
    }

    public function testHandleSchedulesNextMonthlyRun(): void
    {
        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $scheduled = $schedulerService->find('finance', 'purge_old_movements', 'monthly');

        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
    }

    public function testHandleDoesNotDuplicateScheduledRunOnRepeatedCalls(): void
    {
        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());
        $handler->handle([], $this->createTaskContext());

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'finance' AND task_key = 'purge_old_movements'");
        $stmt->execute();
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * End-to-end: several years of data older than the retention period,
     * across two accounts, purged in one run (module spec §4 test list).
     */
    public function testEndToEndPurgeOfDataOlderThanFiveYears(): void
    {
        $secondAccountId = $this->accountRepository->create('Deuxième compte', Account::TYPE_BANK, null, 'BE00000000000002', 'Titulaire', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$secondAccountId]);

        $oldFiscalYearId = $this->fiscalYearRepository->create('2014-2015', '2014-09-01', '2015-08-31');
        $this->checkpointRepository->create($this->accountId, '2014-09-01', 500.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->checkpointRepository->create($secondAccountId, '2014-09-01', 200.0, BalanceCheckpoint::SOURCE_MANUAL);
        $this->createTransaction($oldFiscalYearId, '2015-01-01', -50.0);
        $this->transactionRepository->create($secondAccountId, $oldFiscalYearId, 'ref-x', '2015-02-01', 'x', -30.0, null, null, Transaction::SOURCE_MANUAL, null);

        $handler = new PurgeOldMovementsHandler();
        $handler->handle([], $this->createTaskContext());

        $this->assertCount(0, $this->transactionRepository->findByAccountId($this->accountId));
        $this->assertCount(0, $this->transactionRepository->findByAccountId($secondAccountId));

        $accountA = $this->accountRepository->findById($this->accountId);
        $accountB = $this->accountRepository->findById($secondAccountId);
        $this->assertSame(450.0, $this->balanceService->getBalanceAt($accountA, new \DateTimeImmutable('2020-01-01')));
        $this->assertSame(170.0, $this->balanceService->getBalanceAt($accountB, new \DateTimeImmutable('2020-01-01')));
    }
}
