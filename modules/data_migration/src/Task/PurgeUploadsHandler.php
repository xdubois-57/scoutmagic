<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\UploadPurger;
use Modules\DataMigration\Service\UploadStorage;

/**
 * Hourly: deletes the deposited migration files nobody has touched for
 * 24 hours ({@see UploadPurger}). A ZIP of the unit's history holds
 * personal data, and « au plus tard 24 heures après » is what the page and
 * the RGPD page promise.
 *
 * Seeded from the module's block in public/index.php and re-armed in a
 * `finally`, through `rearmAfter()`, like every recurring chain.
 */
final class PurgeUploadsHandler implements TaskHandlerInterface
{
    public const MODULE_ID = 'data_migration';
    public const TASK_KEY = 'purge_uploads';
    public const REFERENCE = 'hourly';
    public const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        try {
            $result = (new UploadPurger(
                new RunRepository($pdo, $context->encryption),
                new UploadStorage($context->storagePath)
            ))->purge();

            if ($result['files'] > 0 || $result['orphans'] > 0) {
                // Counts only: a file name can be a person's.
                $context->journal->log(
                    self::MODULE_ID,
                    'uploads_purged',
                    'info',
                    'Fichiers de migration de données supprimés au terme de leur conservation',
                    $result
                );
            }
        } finally {
            (new SchedulerService(new SchedulerRepository($pdo)))
                ->rearmAfter(self::MODULE_ID, self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
