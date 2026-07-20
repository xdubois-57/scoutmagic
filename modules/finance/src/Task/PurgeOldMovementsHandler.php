<?php

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Purges movements older than finance_retention_years, then reschedules
 * itself a day later — same self-rescheduling pattern as
 * Modules\LlmConnector\Task\RefreshModelsHandler, using a fixed reference
 * ('daily') so re-runs never stack up duplicate pending tasks.
 */
class PurgeOldMovementsHandler implements TaskHandlerInterface
{
    private const SETTING_KEY = 'finance_retention_years';
    private const REFERENCE = 'daily';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $transactionRepository = new TransactionRepository($pdo, $context->encryption);

        $retentionYears = (int) $context->settings->get(self::SETTING_KEY, 'finance', '5');
        $cutoffDate = (new \DateTimeImmutable())->modify("-{$retentionYears} years")->format('Y-m-d');

        $deleted = $transactionRepository->deleteOlderThan($cutoffDate);

        $context->journal->log(
            'finance',
            'movements_purged',
            'info',
            "Purge automatique : {$deleted} mouvement(s) supprimé(s) (antérieurs au {$cutoffDate})",
            ['deleted' => $deleted, 'cutoff_date' => $cutoffDate],
            null
        );

        $this->scheduleNextRun($context);
    }

    private function scheduleNextRun(TaskContext $context): void
    {
        $schedulerRepository = new SchedulerRepository($context->connection->getPdo());
        $schedulerService = new SchedulerService($schedulerRepository);

        $existing = $schedulerService->find('finance', 'purge_old_movements', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $nextRun = new \DateTimeImmutable('+1 day');
        $schedulerService->schedule('finance', 'purge_old_movements', $nextRun, [], self::REFERENCE);
    }
}
