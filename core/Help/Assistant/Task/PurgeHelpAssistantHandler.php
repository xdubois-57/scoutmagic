<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant\Task;

use Core\Help\Assistant\AssistantCacheRepository;
use Core\Help\Assistant\AssistantRateLimitRepository;
use Core\Help\Assistant\AssistantService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Drops what the help assistant no longer needs: rate-limit rows past the
 * quota window, and cached answers old enough that no running version can
 * still reach them.
 *
 * Self-reschedules at the end of every run rather than being a
 * first-class recurring task — the same precedent as
 * Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler and
 * Core\Notification\Task\PurgeNotificationsHandler, since Core\Scheduler
 * has no recurring-task concept of its own.
 *
 * Registered once in Core\Scheduler\CoreTaskHandlers, which
 * public/scheduler-bootstrap.php applies identically for BOTH entry
 * points. That shared registration is what makes the trap ARCHITECTURE.md
 * §8.17/§8.20 documents — a handler registered in public/index.php and
 * not in public/cron.php, so every run under a real crontab failed with
 * "No handler registered" and nothing said so — unreachable now.
 */
class PurgeHelpAssistantHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_help_assistant';
    public const REFERENCE = 'daily';

    private const INTERVAL_SECONDS = 86400;

    /**
     * A cached answer outlives its usefulness the moment the application
     * version changes, because the version is part of its key — so this
     * is housekeeping, not correctness. Thirty days keeps a busy month of
     * one release's questions and drops the rest.
     */
    private const CACHE_RETENTION_DAYS = 30;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        // The rate-limit window is a constant of the service rather than a
        // setting: unlike the human check, whose window an administrator
        // tunes against real spam, this one bounds what the unit pays its
        // AI provider and has no reason to differ per installation.
        $rateCutoff = (new \DateTimeImmutable('-' . AssistantService::QUOTA_WINDOW_MINUTES . ' minutes'))
            ->format('Y-m-d H:i:s');
        (new AssistantRateLimitRepository($pdo))->deleteOlderThan($rateCutoff);

        $cacheCutoff = (new \DateTimeImmutable('-' . self::CACHE_RETENTION_DAYS . ' days'))
            ->format('Y-m-d H:i:s');
        (new AssistantCacheRepository($pdo))->deleteOlderThan($cacheCutoff);

        $schedulerService = new SchedulerService(new SchedulerRepository($pdo));
        $schedulerService->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
