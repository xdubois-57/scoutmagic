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
 * FileRecord alike, whether or not anyone downloaded it — and so is any
 * stored archive file past that age that nothing references any more
 * (SupportPackageService::sweepExpiredFiles()), and any unencrypted
 * working copy a generation killed mid-run left in `storage/temp/`
 * (SupportPackageService::sweepAbandonedWorkingFiles()). Self-rescheduling,
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
            $service = SupportPackageFactory::service($context);

            try {
                $purged = $service->purgeExpired();

                if ($purged) {
                    $context->journal->log(
                        'core',
                        'support_package_purged',
                        'info',
                        'Paquet de support supprimé après ' . SupportPackageService::RETENTION_DAYS . ' jours'
                    );
                }
            } finally {
                // Runs even when the purge above failed on the database:
                // the sweep works from the directory, and bytes nothing
                // references any more are exactly what a failing database
                // leaves behind.
                $service->sweepExpiredFiles();
                $service->sweepAbandonedWorkingFiles();
            }
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
            $scheduler->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
