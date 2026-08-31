<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Statistics\StatisticsServiceFactory;

/**
 * "Start a scheduler pass now, in a process of its own."
 *
 * Used by the two handlers that replace the files under a running process
 * — `Task\InstallUpdateHandler` and `Task\RestoreBackupHandler` — after
 * they have deliberately NOT migrated the schema themselves. Scheduling
 * the migration is what guarantees a different process runs it
 * (`SchedulerRunner::processOverdue()` claims its task list once, at the
 * start of a pass, so a task created during a pass is never run by it).
 * This is what decides *when* that process starts: now, over the same
 * fire-and-forget self-request the scheduler uses to continue a long
 * chain, rather than at the next cron tick or the next visitor's page
 * load — which would just be "migrate on somebody's request" wearing a
 * different hat.
 *
 * Opportunistic, exactly like a hop, and for the same reason: no
 * `base_url`, no secret, no loopback, a refused socket — any of these
 * leaves the queue to drain the way it always did. By the time this runs
 * the operation has already succeeded and the migration is queued, so a
 * failed kick is a slower migration and never a failed update. Hence the
 * catch-all and the boolean rather than an exception.
 *
 * There are two ways in because there are two callers, at opposite ends of
 * the application. A scheduled task has a `TaskContext` and uses now();
 * public/index.php's pending-migration block has nothing but a PDO — it
 * runs before the composition root exists and exits before reaching it —
 * and uses fromPdo(). Both end on the same kick().
 */
final class SchedulerKick
{
    public static function now(TaskContext $context): bool
    {
        try {
            $pdo = $context->connection->getPdo();
            $secrets = StatisticsServiceFactory::secretManager($context)->readSecrets();
            $secret = (string) ($secrets['scheduler_continuation_secret'] ?? '');
            if ($secret === '') {
                return false;
            }

            $repository = new SchedulerRepository($pdo);

            return (new SchedulerContinuation(
                new SchedulerRunner($repository, $context->journal),
                $repository,
                $context->settings,
                $context->journal,
                $pdo,
                $secret
            ))->kick();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The same kick from a caller that has no TaskContext.
     *
     * Its one user is public/index.php's pending-migration block, on the
     * slice that finished the migration. That block runs before the
     * application's services exist, so it can offer a PDO, the journal it
     * built for MigrationRunner, and the secret it already read — nothing
     * else. Everything the kick needs beyond that (the settings holding
     * `base_url`, the hop counter and the ceiling) is read from that PDO
     * here, the same way Database\MigrationChain builds its own
     * SettingService for the same reason.
     *
     * Why the block needs this at all: the migration is only half of what
     * an update has left to do. The other half — writing VERSION, marking
     * update_history completed, purging old backups, notifying — lives in
     * the `install_update` resume task, which is queued and waiting. The
     * chain that just finished the schema has no reason of its own to run
     * it, and stopping there means the update sits at `migrating` until
     * some unrelated request arrives, which is the fifteen-minute watchdog
     * losing the race. So the last migration slice hands back to the
     * scheduler.
     */
    public static function fromPdo(\PDO $pdo, JournalService $journal, string $secret): bool
    {
        try {
            if ($secret === '') {
                return false;
            }

            $settings = new SettingService(new SettingRepository($pdo));
            $repository = new SchedulerRepository($pdo);

            return (new SchedulerContinuation(
                new SchedulerRunner($repository, $journal),
                $repository,
                $settings,
                $journal,
                $pdo,
                $secret
            ))->kick();
        } catch (\Throwable) {
            return false;
        }
    }
}
