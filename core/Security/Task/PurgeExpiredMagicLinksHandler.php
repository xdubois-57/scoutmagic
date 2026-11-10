<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\MagicLinkRepository;

/**
 * Deletes the magic links that can no longer serve anyone (issue #1099):
 * expired, and older than the hour the per-address rate limit counts
 * (MagicLinkRepository::deleteExpired()). Before this task the purge
 * existed and nothing called it, so every link ever requested stayed in
 * the table.
 *
 * Self-reschedules daily, like the other purges (Core\Security\HumanCheck\
 * Task\PurgeHumanCheckRateLimitsHandler); seeded by public/index.php.
 */
class PurgeExpiredMagicLinksHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_expired_magic_links';
    public const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        (new MagicLinkRepository($pdo))->deleteExpired();

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
