<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\SupportDashboard\Repository\SupportMonthlyAggregateRepository;

/**
 * Closes every calendar month that has ended and is not yet finalised
 * (`support_dashboard`/`finalize_monthly_aggregate`, daily,
 * self-rescheduling — ARCHITECTURE.md §8.50).
 *
 * It runs daily and finalises **every** eligible month rather than "last
 * month", which is what makes catch-up free: a receiver left idle for a
 * quarter closes all three months on its next run, in order, with no
 * special path and nothing to trigger by hand.
 *
 * Finalisation writes the count and **deletes that month's contribution
 * rows**, so a finalised aggregate holds no installation id and no URL.
 * Once written it is immutable: this handler never updates an existing row,
 * and there is deliberately no user-facing recompute anywhere.
 */
class FinalizeMonthlyAggregateHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'finalize_monthly_aggregate';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $repository = new SupportMonthlyAggregateRepository($context->connection->getPdo());

            // Strictly earlier than the current month: a month still in
            // progress can still gain contributors, and an aggregate is
            // immutable once written — finalising early would freeze a
            // half-month as if it were the whole one.
            $currentMonth = (new \DateTimeImmutable())->format('Y-m');

            $finalized = [];
            foreach ($repository->findUnfinalizedMonthsBefore($currentMonth) as $month) {
                if ($repository->finalize($month)) {
                    $finalized[] = $month;
                }
            }

            if ($finalized !== []) {
                $context->journal->log(
                    'support_dashboard',
                    'support_monthly_aggregate_finalized',
                    'info',
                    'Agrégat mensuel finalisé pour : ' . implode(', ', $finalized) . '.',
                    ['months' => $finalized]
                );
            }
        } finally {
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->scheduleAfter('support_dashboard', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
        }
    }
}
