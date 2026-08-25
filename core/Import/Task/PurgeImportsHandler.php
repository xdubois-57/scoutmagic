<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import\Task;

use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportRetentionService;
use Core\Import\RosterSnapshotRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Daily retention purge for Desk imports (`core`/`purge_desk_imports`,
 * reference `daily`) — SECURITY.md §13, ARCHITECTURE.md §8.1.
 *
 * **It has to run even if nobody imports any more.** A retention hung off
 * the moment of the next import would keep its RGPD promise only while the
 * unit keeps importing — and a unit that stops importing is exactly the
 * one whose personal data should stop being kept. So this is a task on a
 * clock, not a step of the import.
 *
 * Self-rescheduling in a `finally`, the same shape as
 * `Core\Support\Task\PurgeSupportPackagesHandler` and
 * `Core\Notification\Task\PurgeNotificationsHandler`: `Core\Scheduler` has
 * no first-class recurring task, so a run that fails must still put the
 * next one on the clock, or the purge stops for good on its first bad day.
 */
class PurgeImportsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_desk_imports';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        try {
            $service = new ImportRetentionService(
                $pdo,
                new ImportJournalRepository($pdo),
                new RosterSnapshotRepository($pdo),
                new FileRepository($pdo),
                new ScoutYearService($pdo),
                $context->settings,
                $context->journal,
                $context->storagePath
            );

            $service->purge();
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($pdo));
            $scheduler->scheduleAfter('core', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
        }
    }
}
