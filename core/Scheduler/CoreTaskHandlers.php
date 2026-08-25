<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

/**
 * The single declaration of every **core** scheduled task handler.
 *
 * Module handlers come from each `module.json`'s `scheduled_tasks`; core
 * handlers had no equivalent and were instead hand-registered, separately,
 * in `public/index.php` and `public/cron.php`. That duplication has already
 * caused a real production failure (ARCHITECTURE.md §8.17: `create_backup`
 * was missing from `cron.php`, so every background backup under a real
 * crontab failed with "No handler registered" and nothing said so). Both
 * entry points now iterate this list instead, so a new core task can only
 * ever be added in one place.
 *
 * It doubles as the authoritative enumeration for the support package's
 * `scheduled-tasks.xlsx` (ARCHITECTURE.md §8.48), which has to list every
 * *declared* handler — including ones that have never produced a row in
 * `scheduled_actions`.
 */
final class CoreTaskHandlers
{
    /**
     * @return array<string, class-string<TaskHandlerInterface>> task key => handler class
     */
    public static function all(): array
    {
        return [
            'create_backup' => \Core\Maintenance\Task\CreateBackupHandler::class,
            'install_update' => \Core\Maintenance\Task\InstallUpdateHandler::class,
            'reset_settings' => \Core\Maintenance\Task\ResetSettingsHandler::class,
            'full_reset' => \Core\Maintenance\Task\FullResetHandler::class,
            'restore_backup' => \Core\Maintenance\Task\RestoreBackupHandler::class,
            'auto_backup' => \Core\Maintenance\Task\AutoBackupHandler::class,
            'check_stable_update' => \Core\Maintenance\Task\CheckStableUpdateHandler::class,
            'compress_section_document' => \Core\Member\Task\CompressSectionDocumentHandler::class,
            'send_notifications' => \Core\Notification\Task\SendNotificationsHandler::class,
            'send_notification_emails' => \Core\Notification\Task\SendNotificationEmailsHandler::class,
            'purge_notifications' => \Core\Notification\Task\PurgeNotificationsHandler::class,
            'purge_human_check_rate_limits' => \Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler::class,
            \Core\Statistics\Task\SendStatisticsHandler::TASK_KEY => \Core\Statistics\Task\SendStatisticsHandler::class,
            \Core\Support\Task\GenerateSupportPackageHandler::TASK_KEY => \Core\Support\Task\GenerateSupportPackageHandler::class,
            \Core\Support\Task\PurgeSupportPackagesHandler::TASK_KEY => \Core\Support\Task\PurgeSupportPackagesHandler::class,
            \Core\Import\Task\PurgeImportsHandler::TASK_KEY => \Core\Import\Task\PurgeImportsHandler::class,
        ];
    }

    /**
     * Register every core handler on a runner. Called identically from
     * `public/index.php` and `public/cron.php`.
     */
    public static function registerAll(SchedulerRunner $runner): void
    {
        foreach (self::all() as $taskKey => $handlerClass) {
            $runner->registerHandler('core', $taskKey, new $handlerClass());
        }
    }
}
