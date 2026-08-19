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
 * Closes groups nobody has used for groups_inactivity_close_months,
 * daily. A closed group is read-only and stays fully visible to its
 * members — this is not a deletion and not a hiding, and the purge that
 * eventually follows is a separate task months later.
 */
class CloseInactiveGroupsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'close_inactive_groups';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        GroupsTaskFactory::lifecycle($context)->closeInactive();

        GroupsTaskFactory::rescheduleDaily($context, self::TASK_KEY);
    }
}
