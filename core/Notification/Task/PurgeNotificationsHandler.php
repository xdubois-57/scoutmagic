<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
 *
 * That promise is about TIDINESS and it is the only rule this task
 * applies. It is not the only way a notification can disappear: a module
 * that stored a value it has undertaken to erase deletes its own rows
 * through NotificationRepository::deleteOfTypeWithUrls(), read or not,
 * naming them one by one (ARCHITECTURE.md §8.61, issue #292). Nothing
 * here decides that, and nothing there widens this.
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
        $deleted = $repository->deleteReadOlderThan($cutoff);

        // A count and a retention, never a recipient or a title (§8.6).
        // Silence here meant « mes notifications ont disparu » had no
        // answer at all — not even « la conservation les a prises, elle
        // est réglée à 90 jours ».
        if ($deleted > 0) {
            $context->journal->log(
                'core',
                'notifications_purged',
                'info',
                sprintf('%d notification(s) lue(s) supprimée(s) après %d jours.', $deleted, $retentionDays),
                ['deleted' => $deleted, 'retention_days' => $retentionDays]
            );
        }

        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $schedulerService->rearmAfter('core', 'purge_notifications', self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
