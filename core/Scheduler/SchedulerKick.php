<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

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
}
