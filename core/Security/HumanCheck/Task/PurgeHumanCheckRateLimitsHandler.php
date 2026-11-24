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
use Core\Security\HumanCheck\HumanCheckService;

/**
 * Deletes human_check_rate_limits rows past the longest rate-limit window in
 * use — the larger of the setting and HumanCheckService::LONGEST_WINDOW_MINUTES —
 * a row older than that can never affect a future countSince() call again. Self-reschedules at the end of every run rather than being
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

        // Never shorter than the longest window a form may count over: the
        // magic-link form counts a whole hour (issue #1267), and a row taken
        // after ten minutes would hand its source a fresh allowance.
        $windowMinutes = max(
            (int) ($context->settings->get('human_check_rate_limit_window_minutes') ?: 10),
            HumanCheckService::LONGEST_WINDOW_MINUTES
        );
        $cutoff = (new \DateTimeImmutable('-' . $windowMinutes . ' minutes'))->format('Y-m-d H:i:s');
        $repository->deleteOlderThan($cutoff);

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('core', 'purge_human_check_rate_limits', self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
