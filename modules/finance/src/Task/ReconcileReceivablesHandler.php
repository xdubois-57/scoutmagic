<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\TreasurerScope;

/**
 * The nightly safety net under Service\ReceivableAllocationService.
 *
 * Allocations are normally written the moment they can be: after a bank
 * import, when a receivable is created, when its amount moves. This task
 * exists for what those three cannot reach — chiefly an installation that
 * had receivables and movements before allocations existed at all, whose
 * whole history has to be matched once, and any allocation a failure left
 * half-written.
 *
 * It is deliberately idempotent and cheap on a settled installation: the
 * pass writes only when a computed amount differs from the stored one, so
 * a night with nothing to do writes nothing.
 *
 * No visitor is acting here, so the account partition
 * (Service\TreasurerScope) is not narrowed against anybody — the same
 * "system caller" stance the reference-dataset builder takes. That is
 * safe because this task performs no human gesture: it only recomputes
 * automatic allocations, and never touches a row a treasurer wrote.
 */
class ReconcileReceivablesHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'nightly';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $accountRepository = new AccountRepository($pdo, $context->encryption);
        $service = new ReceivableAllocationService(
            new ExpectedReceivableRepository($pdo, $context->encryption),
            new ReceivableAllocationRepository($pdo),
            new TransactionRepository($pdo, $context->encryption),
            $accountRepository,
            new AccountVisibility(TreasurerScope::systemCaller())
        );

        $changed = 0;
        $accounts = 0;
        foreach ($accountRepository->findAllOrdered() as $account) {
            $written = $service->reconcileAccount($account->id);
            if ($written > 0) {
                $changed += $written;
                $accounts++;
            }
        }

        // Only when it changed something. A settled installation writes
        // nothing on this pass by design, and a nightly line saying so
        // would be the flood rather than the answer — `scheduler_task_done`
        // already records that the task ran. But an imputation that
        // appeared overnight, with nobody having touched anything, is
        // exactly the thing a treasurer comes asking about.
        if ($changed > 0) {
            $context->journal->log(
                'finance',
                'receivable_allocations_reconciled',
                'info',
                sprintf(
                    '%d imputation(s) recalculée(s) sur %d compte(s) par le rapprochement nocturne.',
                    $changed,
                    $accounts
                ),
                ['allocations' => $changed, 'accounts' => $accounts]
            );
        }

        $this->scheduleNextRun($context);
    }

    private function scheduleNextRun(TaskContext $context): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));

        $existing = $schedulerService->find('finance', 'reconcile_receivables', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->rearm(
            'finance',
            'reconcile_receivables',
            self::REFERENCE,
            new \DateTimeImmutable('tomorrow 04:00'),
        );
    }
}
