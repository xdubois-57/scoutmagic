<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Task;

use Core\File\DataMigrationDeposits;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Hourly: deletes what the data migration module left in
 * `storage/temp/data_migration/` once nothing has touched it for 24 hours
 * (`core`/`purge_data_migration_deposits`, issue #1259 —
 * {@see DataMigrationDeposits}).
 *
 * A core task rather than the module's, because the module's own purge
 * stops having a handler the moment the module is disabled, and disabling
 * it once the migration is over is exactly what it is for. Seeded
 * unconditionally from `public/index.php` and re-armed in a `finally`,
 * like every recurring chain.
 */
final class PurgeDataMigrationDepositsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_data_migration_deposits';
    public const REFERENCE = 'hourly';
    public const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $deleted = (new DataMigrationDeposits($context->storagePath))->sweep(time());
            if ($deleted > 0) {
                // A count only: a file name can be a person's.
                $context->journal->log(
                    'core',
                    'data_migration_deposits_purged',
                    'info',
                    'Fichiers de migration de données supprimés au terme de leur conservation',
                    ['entries' => $deleted]
                );
            }
        } finally {
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
