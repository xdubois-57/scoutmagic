<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Service\DateInput;
use Core\Scheduler\SchedulerRepository;

/**
 * Closes update_history rows left at 'pending' with nothing left that
 * could ever start them.
 *
 * 'pending' is the one non-terminal status with no safety net of its own,
 * and that is deliberate for the two queries that skip it:
 * `UpdateHistoryRepository::findInProgress()` must not gate visitors
 * behind an install that has not touched the site yet, and
 * `markOtherInProgressAsFailed()` must not fail a release install
 * legitimately waiting days for its weekly slot. The consequence is that a
 * row whose scheduled action went away — cancelled, or lost — reads "En
 * cours (pending)" in the update history forever.
 *
 * `GitHubWebhookService::supersedeQueuedInstall()` now closes the rows it
 * supersedes, which is where these came from. This is the net underneath
 * it, for rows that predate that fix or are orphaned some other way.
 *
 * The predicate is "no queued scheduled action points at this row", not
 * age — an install waiting for next Monday at 03:00 is legitimately
 * pending for days, and killing it on age would silently disable the
 * weekly auto-update. Age only enters as a floor, to make the window
 * between `create()` and `schedule()` — two separate statements — unable
 * to be swept by a cron pass landing between them.
 */
final class AbandonedInstallSweeper
{
    /**
     * Wide enough that no create()/schedule() pair can straddle it, and
     * short enough that an orphan does not sit misreported for long.
     */
    private const MIN_AGE_MINUTES = 15;

    /**
     * @return int the number of rows closed
     */
    public static function sweep(
        UpdateHistoryRepository $updateHistoryRepository,
        SchedulerRepository $schedulerRepository,
        ?\DateTimeImmutable $now = null
    ): int {
        $pending = $updateHistoryRepository->findPending();
        if ($pending === []) {
            return 0;
        }

        $claimed = self::historyIdsWithAQueuedTask($schedulerRepository);
        $cutoff = ($now ?? new \DateTimeImmutable())
            ->sub(new \DateInterval('PT' . self::MIN_AGE_MINUTES . 'M'));

        $closed = 0;
        foreach ($pending as $history) {
            if (isset($claimed[$history->id])) {
                continue;
            }
            // Same reader UpdateHistoryRepository::isStale() uses: started_at
            // is stored naive, and parsing it any other way compares two
            // different clocks.
            $started = DateInput::fromStorage($history->startedAt);
            if ($started === null || $started > $cutoff) {
                continue;
            }

            $updateHistoryRepository->markFailed(
                $history->id,
                'Installation abandonnée : elle n\'a jamais démarré et plus aucune tâche planifiée ne l\'attend.'
            );
            $closed++;
        }

        return $closed;
    }

    /**
     * The history ids that a scheduled action still not finished points at.
     * 'processing' counts as much as 'pending': a task already claimed is
     * about to move its row out of 'pending' itself.
     *
     * @return array<int, true>
     */
    private static function historyIdsWithAQueuedTask(SchedulerRepository $schedulerRepository): array
    {
        $claimed = [];
        foreach ($schedulerRepository->findByModuleAndTaskKey('core', 'install_update') as $row) {
            if (!in_array((string) ($row['status'] ?? ''), ['pending', 'processing'], true)) {
                continue;
            }
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $historyId = is_array($payload) ? (int) ($payload['history_id'] ?? 0) : 0;
            if ($historyId > 0) {
                $claimed[$historyId] = true;
            }
        }

        return $claimed;
    }
}
