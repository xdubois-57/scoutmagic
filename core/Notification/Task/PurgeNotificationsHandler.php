<?php

declare(strict_types=1);

namespace Core\Notification\Task;

use Core\Notification\NotificationRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Daily retention purge (Configuration > Notifications, "Durée de
 * conservation", SettingService key 'notifications_retention_days',
 * default 90) — deletes only READ notifications older than the setting;
 * an unread one is never purged regardless of age, per module spec.
 * Self-reschedules at the end of every run rather than being a first-
 * class recurring task, same precedent as Core\Maintenance\Task\
 * AutoBackupHandler (Core\Scheduler has no first-class recurring-task
 * concept) — re-reading the setting each time so a change takes effect
 * on the next run.
 */
class PurgeNotificationsHandler implements TaskHandlerInterface
{
    public const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        $repository = new NotificationRepository($context->connection->getPdo(), $context->encryption);
        $retentionDays = (int) ($context->settings->get('notifications_retention_days') ?: 90);
        $cutoff = (new \DateTimeImmutable())->modify("-{$retentionDays} days");
        $repository->deleteReadOlderThan($cutoff);

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('core', 'purge_notifications', self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
