<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\File\FileRepository;
use Core\Maintenance\BackupIntegrity;
use Core\Maintenance\BackupIntegrityStatus;
use Core\Maintenance\BackupRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Re-reads stored backups and records whether they are still readable.
 *
 * **Its own task rather than a sixth check in the daily operational pass**
 * (`Core\Alert\Task\RunOperationalChecksHandler`), and the reason is cost.
 * That pass reads four cheap facts — a percentage, two timestamps, a count
 * — and its docblock justifies running daily on exactly that basis. This
 * one hashes gigabytes. Folding it in would make a pass that is currently
 * nearly free into the heaviest thing the cron does, and would tie two
 * cadences that have no reason to match.
 *
 * The alert stays in that pass, reading what this one wrote
 * (`Core\Alert\Check\BackupIntegrityCheck`) — the same split as everywhere
 * else here: the thing that measures and the thing that reports are not
 * the same thing, and the reporting side computes nothing.
 *
 * **A few per pass, least recently checked first.** Verifying everything
 * every night would re-hash the same untouched gigabytes for no new
 * information, on hosting that charges for I/O and shares a CPU. With the
 * retention of §8.100 an installation keeps a handful of backups, so
 * {@see BATCH_SIZE} at a time gets round all of them within a week — which
 * is the right timescale for a fact that changes when a disk fills, not
 * when a visitor clicks.
 *
 * Self-reschedules at the end of every run, like every recurring chain
 * here, since `Core\Scheduler` has no first-class recurring task.
 */
class VerifyBackupIntegrityHandler implements TaskHandlerInterface
{
    public const REFERENCE = 'daily';

    /**
     * How many backups one pass re-reads.
     *
     * Two, deliberately small. The point is a bound that holds on the
     * worst installation rather than a number tuned for a good one: two
     * gallery-inclusive archives is already several gigabytes of reading,
     * and a pass that runs long on shared hosting is a pass that gets
     * killed half-way and reports nothing at all.
     */
    public const BATCH_SIZE = 2;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $backups = new BackupRepository($pdo);
        $integrity = new BackupIntegrity($backups, new FileRepository($pdo), $context->storagePath);

        foreach ($backups->findLeastRecentlyVerified(self::BATCH_SIZE) as $backup) {
            $status = $integrity->verify($backup);
            $backups->recordIntegrity($backup->id, $status);

            // Journaled only when there is something to say. A pass that
            // finds everything intact every night for a year would
            // otherwise bury the one night it did not, in a journal
            // somebody has to read to be useful.
            if ($status->isFailure()) {
                $context->journal->log(
                    'core',
                    'backup_integrity_failed',
                    'warning',
                    $status === BackupIntegrityStatus::Missing
                        ? 'Le fichier d\'une sauvegarde a disparu du serveur'
                        : 'Une sauvegarde stockée n\'est plus lisible',
                    ['backup_id' => $backup->id, 'type' => $backup->type, 'status' => $status->value]
                );
            }
        }

        $this->scheduleNext($context);
    }

    /**
     * Tomorrow, at a jittered hour, and deliberately not the same hour as
     * the operational pass: the two would otherwise contend for the same
     * disk on the same shared host every night at four.
     *
     * Re-checks for an already-pending occurrence first, so a run that
     * overlaps one never leaves two due at once — the guard every
     * self-rescheduling handler here carries.
     */
    private function scheduleNext(TaskContext $context): void
    {
        $schedulerService = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));

        $existing = $schedulerService->find('core', 'backup_integrity', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $next = (new \DateTimeImmutable('tomorrow 02:00'))->modify('+' . random_int(0, 3600) . ' seconds');
        $schedulerService->rearm('core', 'backup_integrity', self::REFERENCE, $next);
    }
}
