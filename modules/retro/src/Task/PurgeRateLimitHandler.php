<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Retro\Repository\RateLimitRepository;

/**
 * Deletes retro_rate_limits rows older than the rate-limit window — module
 * spec: "a small dedicated table with short-lived rows... purged by a
 * scheduled task". Self-reschedules daily (same pattern as Core\
 * Maintenance\Task\AutoBackupHandler) rather than being a first-class
 * recurring task.
 */
class PurgeRateLimitHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'daily';
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
        $existing = $schedulerService->find('retro', 'purge_rate_limits', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->rearm('retro', 'purge_rate_limits', self::REFERENCE, new \DateTimeImmutable('+1 day'));
    }
}
