<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Support\SupportPackageFactory;
use Core\Support\SupportPackageService;

/**
 * Daily retention purge for the support package
 * (`core`/`purge_support_packages`, reference `daily`) — ARCHITECTURE.md
 * §8.48.
 *
 * The archive is the most sensitive artefact this codebase produces on
 * demand (PHP configuration, server logs, filesystem diagnostics), so it
 * does not linger: seven days after generation it is deleted, file and
 * FileRecord alike, whether or not anyone downloaded it. Self-rescheduling,
 * same precedent as Core\Notification\Task\PurgeNotificationsHandler.
 */
class PurgeSupportPackagesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_support_packages';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $purged = SupportPackageFactory::service($context)->purgeExpired();

            if ($purged) {
                $context->journal->log(
                    'core',
                    'support_package_purged',
                    'info',
                    'Paquet de support supprimé après ' . SupportPackageService::RETENTION_DAYS . ' jours'
                );
            }
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
            $scheduler->scheduleAfter('core', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
        }
    }
}
