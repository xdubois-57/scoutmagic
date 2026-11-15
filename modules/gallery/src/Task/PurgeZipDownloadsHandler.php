<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Task;

use Core\Config\AppClock;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Gallery\Repository\ZipDownloadRepository;

/**
 * Deletes gallery_zip_downloads rows the ZIP limit no longer needs.
 * Self-reschedules daily, exactly like Modules\Groups\Task\
 * PurgeRateLimitHandler; seeded once from public/index.php.
 */
class PurgeZipDownloadsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_zip_downloads';

    private const REFERENCE = 'daily';

    /**
     * Longer than Service\ZipDownloadLimiter::WINDOW (one hour), so a row
     * is never purged while it still counts — and short, because a row says
     * which account downloaded when, and is useless after the hour.
     */
    private const RETENTION = '-2 hours';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        (new ZipDownloadRepository($pdo))->deleteOlderThan(
            AppClock::now()->modify(self::RETENTION)->format('Y-m-d H:i:s')
        );

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $existing = $schedulerService->find('gallery', self::TASK_KEY, self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->rearm('gallery', self::TASK_KEY, self::REFERENCE, new \DateTimeImmutable('+1 day'));
    }
}
