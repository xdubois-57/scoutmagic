<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Retention purge for whole groups, daily: a group closed longer ago than
 * groups_closed_purge_months is deleted with everything in it, its
 * delegated gallery album included.
 *
 * A group of the effective scout year or of a future one is never purged,
 * however long it has been closed — otherwise Task\
 * EnsureSectionGroupsHandler would recreate a section group the night
 * after this one deleted it, forever. That rule is also why this module
 * needs no tombstone table: the years that could loop are simply out of
 * reach of the purge (Service\GroupLifecycleService).
 */
class PurgeClosedGroupsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_closed_groups';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        GroupsTaskFactory::lifecycle($context)->purgeClosedGroups();

        GroupsTaskFactory::rescheduleDaily($context, self::TASK_KEY);
    }
}
