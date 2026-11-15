<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Config\AppClock;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Registration\Repository\ConfirmationSendRepository;

/**
 * Deletes registration_confirmation_sends rows the hourly limit no longer
 * needs. Self-reschedules daily, exactly like Modules\Gallery\Task\
 * PurgeZipDownloadsHandler; seeded once from public/index.php.
 */
class PurgeConfirmationSendsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_confirmation_sends';

    private const REFERENCE = 'daily';

    /**
     * Longer than Service\ConfirmationSendLimiter::WINDOW (one hour), so a
     * row is never purged while it still counts — and short, because it is
     * useless after the hour.
     */
    private const RETENTION = '-2 hours';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        (new ConfirmationSendRepository($pdo))->deleteOlderThan(
            AppClock::now()->modify(self::RETENTION)->format('Y-m-d H:i:s')
        );

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $existing = $schedulerService->find('registration', self::TASK_KEY, self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->rearm('registration', self::TASK_KEY, self::REFERENCE, new \DateTimeImmutable('+1 day'));
    }
}
