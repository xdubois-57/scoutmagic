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
 * The daily automatic archiving (issue #1350), in two passes:
 *
 * - groups whose scout year is over (ended before the public year
 *   started) get their archive date — Service\GroupLifecycleService::
 *   archiveEndedYears();
 * - groups nobody has used for groups_inactivity_close_months are
 *   archived — archiveInactive().
 *
 * An archived group is read-only and stays fully visible to its members —
 * this is not a deletion and not a hiding, and the purge that eventually
 * follows is a separate task months later, counted from the date stamped
 * here. Neither pass touches a group that already has a date.
 *
 * The class and its task key keep their historical names on purpose:
 * the key is what each install's scheduler queue holds a pending run
 * under, and renaming it would leave that run pointing at nothing.
 */
class CloseInactiveGroupsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'close_inactive_groups';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $lifecycle = GroupsTaskFactory::lifecycle($context);
        $lifecycle->archiveEndedYears();
        $lifecycle->archiveInactive();

        GroupsTaskFactory::rescheduleDaily($context, self::TASK_KEY);
    }
}
