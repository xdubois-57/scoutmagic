<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Task;

use Core\Config\ScoutYearService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Creates the missing section groups, daily.
 *
 * The work itself is Service\SectionGroupSyncService's and is idempotent,
 * so this handler is a schedule and nothing more — the same service runs
 * on the module's own page load, and neither caller needs to know about
 * the other.
 *
 * Runs for the current PUBLIC year and for every later one already
 * imported. That second part is what lets a chief prepare a new year's
 * groups before the public switch: the staff-year mechanism
 * (ARCHITECTURE.md §8.26) already shows a chief the next year's content
 * while everyone else still sees the current one, so the group only has
 * to exist — there is nothing to build for the visibility half of it.
 */
class EnsureSectionGroupsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'ensure_section_groups';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $scoutYearService = new ScoutYearService($pdo);
        $sync = GroupsTaskFactory::sectionGroupSync($context);

        // The CONFIGURED public year, the same one the purge protects
        // (Service\GroupLifecycleService) — the two must agree, or one
        // would recreate what the other just deleted.
        $current = GroupsTaskFactory::scoutYearResolver($context)->getCurrentPublicYear();
        $currentStart = (string) $current['start_date'];

        // The current year and every future one. Never a past year: its
        // groups either already exist or were deliberately purged, and
        // recreating them would undo the retention purge every night.
        foreach ($scoutYearService->getAll() as $year) {
            if ((string) $year['start_date'] < $currentStart) {
                continue;
            }

            $created = $sync->sync((int) $year['id']);
            if ($created > 0) {
                $context->journal->log(
                    'groups',
                    'section_groups_created',
                    'info',
                    'Groupes de section créés automatiquement',
                    ['scout_year_id' => (int) $year['id'], 'created' => $created],
                    null
                );
            }
        }

        GroupsTaskFactory::rescheduleDaily($context, self::TASK_KEY);
    }
}
