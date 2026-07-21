<?php

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\FiscalYear;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;

/**
 * Runs monthly (module spec "itération 5"): purges one complete fiscal
 * year at a time — the oldest one whose end_date is already past the
 * finance_retention_years cutoff — rather than a day-by-day cutoff, per
 * account. For each account with movements in that fiscal year:
 *
 * 1. Computes the balance as of the fiscal year's end date (before
 *    deleting anything) — this becomes a single consolidated checkpoint
 *    that preserves balance continuity after the purge.
 * 2. Deletes the finance_transaction_attachments joins, then the
 *    finance_transactions themselves.
 * 3. Deletes every balance checkpoint at or before the fiscal year's end
 *    date (now redundant) and inserts the consolidated one.
 * 4. Any attachment left with zero remaining movement associations
 *    (active or archived) is physically deleted — file on disk and row
 *    — the only place in the module where a receipt is ever truly
 *    removed rather than archived.
 */
class PurgeOldMovementsHandler implements TaskHandlerInterface
{
    private const SETTING_KEY = 'finance_retention_years';
    private const REFERENCE = 'monthly';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $fiscalYearRepository = new FiscalYearRepository($pdo, new \Core\Config\ScoutYearService($pdo));
        $accountRepository = new AccountRepository($pdo, $context->encryption);
        $transactionRepository = new TransactionRepository($pdo, $context->encryption);
        $transactionAttachmentRepository = new TransactionAttachmentRepository($pdo);
        $attachmentRepository = new AttachmentRepository($pdo);
        $checkpointRepository = new BalanceCheckpointRepository($pdo);
        $balanceService = new BalanceService($checkpointRepository, $transactionRepository);
        $fileStorage = new EncryptedFileStorageService(new FileRepository($pdo), $context->encryption, $context->storagePath);

        $retentionYears = (int) $context->settings->get(self::SETTING_KEY, 'finance', '5');
        $cutoffDate = (new \DateTimeImmutable())->modify("-{$retentionYears} years")->format('Y-m-d');

        $fiscalYear = $fiscalYearRepository->findOldestEndingBefore($cutoffDate);
        if ($fiscalYear !== null) {
            foreach ($accountRepository->findAllOrdered() as $account) {
                $this->purgeAccountFiscalYear(
                    $account,
                    $fiscalYear,
                    $pdo,
                    $transactionRepository,
                    $transactionAttachmentRepository,
                    $attachmentRepository,
                    $checkpointRepository,
                    $balanceService,
                    $fileStorage,
                    $context
                );
            }
        }

        $this->scheduleNextRun($context);
    }

    private function purgeAccountFiscalYear(
        Account $account,
        FiscalYear $fiscalYear,
        \PDO $pdo,
        TransactionRepository $transactionRepository,
        TransactionAttachmentRepository $transactionAttachmentRepository,
        AttachmentRepository $attachmentRepository,
        BalanceCheckpointRepository $checkpointRepository,
        BalanceService $balanceService,
        EncryptedFileStorageService $fileStorage,
        TaskContext $context
    ): void {
        $transactionIds = $transactionRepository->findIdsByAccountAndFiscalYear($account->id, $fiscalYear->id);
        if ($transactionIds === []) {
            return;
        }

        $affectedAttachmentIds = [];

        $pdo->beginTransaction();
        try {
            // Computed before any deletion — the ledger's own opinion of
            // the balance at the fiscal year's end, seeding continuity.
            $consolidatedBalance = $balanceService->getBalanceAt($account, new \DateTimeImmutable($fiscalYear->endDate));

            foreach ($transactionIds as $transactionId) {
                foreach ($transactionAttachmentRepository->findAttachmentIdsForTransaction($transactionId) as $attachmentId) {
                    $affectedAttachmentIds[$attachmentId] = true;
                }
                $transactionAttachmentRepository->deleteAllForTransaction($transactionId);
            }

            $deletedTransactions = $transactionRepository->deleteByAccountAndFiscalYear($account->id, $fiscalYear->id);

            $checkpointRepository->deleteBeforeOrAt($account->id, $fiscalYear->endDate);
            if ($consolidatedBalance !== null) {
                $checkpointRepository->create($account->id, $fiscalYear->endDate, $consolidatedBalance, BalanceCheckpoint::SOURCE_MANUAL);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // File deletion happens after the DB commit — never remove a
        // real file for a database change that could still be rolled back.
        $deletedAttachments = 0;
        foreach (array_keys($affectedAttachmentIds) as $attachmentId) {
            if ($transactionAttachmentRepository->findTransactionIdsForAttachment($attachmentId) !== []) {
                continue;
            }

            $attachment = $attachmentRepository->findById($attachmentId);
            if ($attachment === null) {
                continue;
            }

            try {
                $fileStorage->delete($attachment->fileId);
                $attachmentRepository->delete($attachmentId);
                $deletedAttachments++;
            } catch (\Throwable $e) {
                $context->journal->log(
                    'finance',
                    'receipt_purge_failed',
                    'info',
                    "Suppression du reçu orphelin échouée : {$e->getMessage()}",
                    ['attachment_id' => $attachmentId],
                    null
                );
            }
        }

        $context->journal->log(
            'finance',
            'movements_purged',
            'info',
            "Purge automatique : exercice « {$fiscalYear->label} », compte « {$account->name} » — "
            . "{$deletedTransactions} mouvement(s) et {$deletedAttachments} reçu(s) orphelin(s) supprimé(s)",
            [
                'fiscal_year_id' => $fiscalYear->id,
                'account_id' => $account->id,
                'deleted_transactions' => $deletedTransactions,
                'deleted_attachments' => $deletedAttachments,
                'date_range' => [$fiscalYear->startDate, $fiscalYear->endDate],
            ],
            null
        );
    }

    private function scheduleNextRun(TaskContext $context): void
    {
        $schedulerRepository = new SchedulerRepository($context->connection->getPdo());
        $schedulerService = new SchedulerService($schedulerRepository);

        $existing = $schedulerService->find('finance', 'purge_old_movements', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $nextRun = new \DateTimeImmutable('+1 month');
        $schedulerService->schedule('finance', 'purge_old_movements', $nextRun, [], self::REFERENCE);
    }
}
