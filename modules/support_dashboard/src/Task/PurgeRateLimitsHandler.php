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
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
            $scheduler->scheduleAfter('support_dashboard', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
        }
    }
}
