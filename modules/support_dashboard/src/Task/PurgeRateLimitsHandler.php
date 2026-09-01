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
use Modules\SupportDashboard\Repository\SupportMailProbeRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;

/**
 * Clears rate-limit rows past their window
 * (`support_dashboard`/`purge_rate_limits`, daily, self-rescheduling —
 * same precedent as `Core\Security\HumanCheck\Task\
 * PurgeHumanCheckRateLimitsHandler` and `Modules\Retro\Task\
 * PurgeRateLimitHandler`).
 *
 * Without it the table grows forever: every accepted report writes a row,
 * and the counter only ever reads the last hour.
 *
 * It also drops the diagnostic mail probes nobody ever claimed, past
 * their expiry (roadmap IT-27). Same job, same cadence, same reason — a
 * row nothing will ever read again — so it is done here rather than in a
 * fourth daily task whose only difference would be the table it names.
 */
class PurgeRateLimitsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_rate_limits';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $cutoff = (new \DateTimeImmutable('-' . StatisticsIntakeService::RATE_LIMIT_WINDOW_MINUTES . ' minutes'))
                ->format('Y-m-d H:i:s');

            (new SupportReportRateLimitRepository($context->connection->getPdo()))->deleteOlderThan($cutoff);

            // A probe whose key has expired is a message that is not
            // coming: the consumer already stopped recognising it, and
            // the row is the last thing left of it.
            (new SupportMailProbeRepository($context->connection->getPdo(), $context->encryption))
                ->deleteExpired(new \DateTimeImmutable());
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
            $scheduler->rearmAfter('support_dashboard', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
