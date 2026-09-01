<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security\HumanCheck\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;

/**
 * Deletes human_check_rate_limits rows past the current rate-limit window
 * — a row older than the window can never affect a future countSince()
 * call again. Self-reschedules at the end of every run rather than being
 * a first-class recurring task, same precedent as Core\Maintenance\Task\
 * AutoBackupHandler / Core\Notification\Task\PurgeNotificationsHandler
 * (Core\Scheduler has no first-class recurring-task concept) — re-reading
 * the window setting each time so a change takes effect on the next run.
 *
 * Registered directly in public/index.php AND public/cron.php (both
 * entry points — ARCHITECTURE.md §8.17/§8.20 document real production
 * bugs from registering a handler in only one of the two).
 */
class PurgeHumanCheckRateLimitsHandler implements TaskHandlerInterface
{
    public const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $repository = new HumanCheckRateLimitRepository($pdo);

        $windowMinutes = (int) ($context->settings->get('human_check_rate_limit_window_minutes') ?: 10);
        $cutoff = (new \DateTimeImmutable('-' . $windowMinutes . ' minutes'))->format('Y-m-d H:i:s');
        $repository->deleteOlderThan($cutoff);

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('core', 'purge_human_check_rate_limits', self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
