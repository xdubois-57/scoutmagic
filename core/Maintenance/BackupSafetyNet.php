<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Scheduler\SchedulerService;

/**
 * Is this backup the net an operation still in flight would fall into?
 *
 * `InstallUpdateHandler` takes an `auto_update` backup before it touches
 * anything, and that backup is the ONLY thing its automatic rollback can
 * start from; `RestoreBackupHandler` does the same with its own
 * `auto_reset` safety copy. Deleting one while its operation is queued or
 * running removes the net at the exact moment something is falling — and
 * the person clicking « Supprimer » on a list of dates has no way of
 * knowing that.
 *
 * So the deletion is refused, and **the refusal says why on screen**
 * rather than greying a button out. A disabled control with no
 * explanation reads as a bug, gets reported as one, and teaches nobody
 * that the backup will be deletable again in two minutes.
 *
 * Two sources, because a running operation records itself in two places:
 * the scheduled task's payload while it is queued or claimed, and
 * `update_history.backup_id` for the length of an install. Either is
 * enough to refuse.
 *
 * **A task that creates its own net declares it mid-run**, and it has to:
 * a payload is written when the task is scheduled, and
 * `RestoreBackupHandler` takes its `auto_reset` copy long after that. Until
 * the copy is named in the running row — `SchedulerRepository::
 * rememberInPayload()`, called in the same breath as marking it complete —
 * it is a `completed` row like any other, listed with a working
 * « Supprimer » button, for the minutes it takes to build a
 * gallery-inclusive archive. That is the worst copy to lose in the worst
 * window to lose it in, and nothing here could see it.
 */
final class BackupSafetyNet
{
    /**
     * Payload keys under which a task names a backup it depends on.
     *
     * `backup_id` is both "the row I am writing" (`core/create_backup`)
     * and "the archive I am restoring from" (`core/restore_backup`);
     * `safety_backup_id` is the copy a restore takes before overwriting
     * anything. All three are reasons to refuse, and the first is not the
     * weakest of them: deleting a row while its handler is still writing
     * to it is a race with a half-written archive at the end.
     *
     * @var string[]
     */
    private const REFERENCE_KEYS = ['backup_id', 'safety_backup_id'];

    public function __construct(
        private readonly SchedulerService $tasks,
        private readonly UpdateHistoryRepository $updates
    ) {
    }

    /**
     * For the background handlers, which hold a connection rather than the
     * two services — one line at each of their wiring sites instead of
     * three, and one place to change if either dependency moves.
     */
    public static function forPdo(\PDO $pdo): self
    {
        return new self(
            new SchedulerService(new \Core\Scheduler\SchedulerRepository($pdo)),
            new UpdateHistoryRepository($pdo)
        );
    }

    /**
     * Every backup id an operation still in flight depends on.
     *
     * The bulk form of {@see reasonToKeep()}, and the two exist for
     * genuinely different callers rather than by accident: a person
     * clicking « Supprimer » needs the ONE sentence that says which
     * operation is holding this backup, while the automatic purge needs
     * the whole set at once and has nobody to explain itself to. Reading
     * the two sources once per purge rather than once per candidate row
     * is the other half of that.
     *
     * @return int[]
     */
    public function protectedIds(): array
    {
        $ids = $this->updates->liveBackupIds();

        foreach ($this->tasks->findLivePayloads() as $task) {
            foreach (self::REFERENCE_KEYS as $key) {
                $referenced = $task['payload'][$key] ?? null;
                if (is_scalar($referenced) && (int) $referenced > 0) {
                    $ids[] = (int) $referenced;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A French sentence saying why this backup cannot be deleted right
     * now, or null when nothing holds it.
     *
     * A sentence rather than a boolean because the caller has nothing to
     * add: what it puts on screen is exactly this, and a controller
     * re-deriving the wording from a flag is how two surfaces end up
     * explaining the same refusal differently.
     */
    public function reasonToKeep(int $backupId): ?string
    {
        if (in_array($backupId, $this->updates->liveBackupIds(), true)) {
            return 'Cette sauvegarde est le filet de sécurité d\'une mise à jour en cours : tant qu\'elle n\'est '
                . 'pas terminée, c\'est la seule chose depuis laquelle le site peut revenir en arrière. '
                . 'Réessayez une fois la mise à jour finie.';
        }

        foreach ($this->tasks->findLivePayloads() as $task) {
            foreach (self::REFERENCE_KEYS as $key) {
                $referenced = $task['payload'][$key] ?? null;
                if (is_scalar($referenced) && (int) $referenced === $backupId) {
                    return 'Une opération planifiée utilise cette sauvegarde en ce moment '
                        . '(« ' . $task['module_id'] . '/' . $task['task_key'] . ' »). '
                        . 'Réessayez une fois qu\'elle sera terminée.';
                }
            }
        }

        return null;
    }
}
