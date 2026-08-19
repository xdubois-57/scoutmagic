<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Groups\Repository\RateLimitRepository;

/**
 * Deletes discussion_group_rate_limits rows older than the rate-limit
 * window. Self-reschedules daily, exactly like
 * Modules\Retro\Task\PurgeRateLimitHandler (and, before it,
 * Core\Maintenance\Task\AutoBackupHandler), rather than being a
 * first-class recurring task.
 */
class PurgeRateLimitHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'daily';

    /**
     * Comfortably longer than Service\RateLimitService::WINDOW_MINUTES —
     * the rows are useless well before this, and keeping the retention
     * loose means a missed run never silently widens someone's budget.
     */
    private const RETENTION_HOURS = 6;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $repository = new RateLimitRepository($pdo);

        $cutoff = (new \DateTimeImmutable('-' . self::RETENTION_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $repository->deleteOlderThan($cutoff);

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $existing = $schedulerService->find('groups', 'purge_rate_limits', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->schedule('groups', 'purge_rate_limits', new \DateTimeImmutable('+1 day'), [], self::REFERENCE);
    }
}
