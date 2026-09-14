<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection\Task;

use Core\Exception\UserFacingMessage;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Protection\ProtectedCopier;
use Core\Storage\Location\Protection\ProtectionPass;
use Core\Storage\Location\Protection\StorageInventoryStore;
use Core\Storage\Location\Protection\StorageProtection;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\StorageLocationRepository;

/**
 * The nightly pass over every declared protection.
 *
 * **One task for all of them, not one per relation.** A unit has two or
 * three protections at most, and a chain per relation would be three
 * chains to arm, three to re-arm, and three ways for one to stop ticking
 * without anybody noticing. The cadence stays per relation —
 * {@see StorageProtection::isDue()} is what decides — and this handler
 * only decides how often to come and ask.
 *
 * **It re-arms with a delay of zero whenever work is left**, which is the
 * shape {@see \Core\Notification\Task\SendNotificationsHandler} uses: a
 * pass that stopped on its time budget must be picked up on the next tick
 * rather than tomorrow night, or a large gallery would advance by twenty
 * seconds a day and never finish its first copy.
 */
class RunStorageProtectionsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'run_storage_protections';
    public const REFERENCE = 'auto';

    /**
     * Twenty seconds, the same figure the notification fan-out and the
     * off-site send work to. Not invented here: a second number for the
     * same constraint would be a second number to keep right.
     */
    public const TIME_BUDGET_SECONDS = 20;

    /**
     * How long to wait before asking again when nothing was due.
     *
     * An hour, not a day: the cadence is per relation and can be shorter
     * than a day, and a chain that only woke once a day could not honour
     * one that asks for six hours.
     */
    public const IDLE_INTERVAL_SECONDS = 3600;

    public function __construct(
        private readonly ?ProtectionPass $pass = null,
        private readonly ?\Closure $now = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $protections = new StorageProtectionRepository($pdo);
        $locations = new StorageLocationRepository($pdo, $context->encryption);
        $backends = new StorageBackendFactory($locations, $context->storagePath);
        $pass = $this->pass ?? new ProtectionPass(new StorageInventoryStore(), new ProtectedCopier());

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $hasTimeLeft = static fn(): bool => microtime(true) < $deadline;
        $now = $this->now();

        $workLeft = false;

        foreach ($protections->findAll() as $protection) {
            if (!$protection->isDue($now)) {
                continue;
            }
            if (!$hasTimeLeft()) {
                // Due, but this run is out of time before it even
                // started: come straight back rather than waiting out the
                // idle interval with work pending.
                $workLeft = true;
                break;
            }

            $workLeft = $this->runOne($protection, $protections, $locations, $backends, $pass, $hasTimeLeft, $context)
                || $workLeft;
        }

        $this->scheduleNext($context, $payload, $workLeft);
    }

    /**
     * @return bool whether this protection has work left for the next run
     */
    private function runOne(
        StorageProtection $protection,
        StorageProtectionRepository $protections,
        StorageLocationRepository $locations,
        StorageBackendFactory $backends,
        ProtectionPass $pass,
        callable $hasTimeLeft,
        TaskContext $context
    ): bool {
        $source = $locations->findById($protection->sourceLocationId);
        $destination = $locations->findById($protection->destinationLocationId);
        if ($source === null || $destination === null) {
            // The relation outlived one of its ends. Nothing to do and
            // nothing to repair here: the row goes when the location does,
            // and a source deleted between two runs is exactly that.
            return false;
        }

        try {
            $result = $pass->run(
                $protection,
                $backends->create($source),
                $backends->create($destination),
                $source->label,
                $hasTimeLeft
            );
        } catch (\Throwable $e) {
            // **The working state is cleared by recordPassFailed(), which
            // is what D15 needs**: a pass that died mid-listing holds a
            // cursor into a listing it can no longer trust, and resuming
            // from it would carry that blindness into the next run.
            // **The message is gated at the WRITE site**, because this is
            // the pattern AGENTS.md names explicitly: a value written now
            // and rendered much later by a template (« last_error » on the
            // Stockage screen) has no display site left to gate it at. The
            // exceptions that actually reach here — a backend's
            // \RuntimeException naming a raw storage key, an AwsException
            // naming a bucket and a host — are not UserFacingException and
            // must not be quoted to an administrator.
            $protections->recordPassFailed($protection->id, UserFacingMessage::from(
                $e,
                "La copie de secours n'a pas pu être poursuivie. Vérifiez que les deux emplacements "
                    . 'répondent, puis relancez un test depuis la page Stockage.'
            ));
            $this->journalFailure($context, $source->label, $destination->label);

            return false;
        }

        if (!$result->finished) {
            $protections->recordPassProgress(
                $protection->id,
                $result->phase,
                $result->cursor,
                $result->pageLastKey,
                $result->seenCount,
                // The stamp the RUN used on its entries, not one computed
                // at persist time — see recordPassProgress()'s docblock.
                $result->passStartedAt
            );

            return true;
        }

        $protections->recordPassCompleted($protection->id);
        $this->journalCompletion($context, $source->label, $destination->label, $result);

        return false;
    }

    private function journalCompletion(
        TaskContext $context,
        string $sourceLabel,
        string $destinationLabel,
        \Core\Storage\Location\Protection\ProtectionPassResult $result
    ): void {
        // **Counts, never keys.** A journal entry is carried by a support
        // package that leaves the installation, and an object key is a
        // path — which on a local location is a path somebody chose and
        // may well carry a person's name. What an administrator needs
        // from this line is how much moved, not which file.
        $context->journal->log(
            'core',
            'storage_protection_pass',
            $result->refusedMassDisappearance ? 'warning' : 'info',
            $result->refusedMassDisappearance
                ? sprintf(
                    'Copie de secours « %s » vers « %s » : trop de fichiers ont disparu de la source en une '
                    . 'seule passe, rien n\'a été marqué. Vérifiez que la source répond correctement.',
                    $sourceLabel,
                    $destinationLabel
                )
                : sprintf(
                    'Copie de secours « %s » vers « %s » : %d fichier(s) copié(s), %d marqué(s) absent(s), '
                    . '%d supprimé(s) de la copie.',
                    $sourceLabel,
                    $destinationLabel,
                    $result->copiedCount,
                    $result->markedAbsentCount,
                    $result->deletedCount
                ),
            ['failures' => count($result->failures)]
        );
    }

    private function journalFailure(TaskContext $context, string $sourceLabel, string $destinationLabel): void
    {
        $context->journal->log(
            'core',
            'storage_protection_failed',
            'warning',
            sprintf(
                'La copie de secours « %s » vers « %s » n\'a pas abouti. Le détail est sur la fiche de '
                . 'l\'emplacement ; rien n\'a été supprimé de la copie.',
                $sourceLabel,
                $destinationLabel
            )
        );
    }

    /**
     * **`rearmAfter()`, never `scheduleAfter()`.** The guarded twin is
     * what keeps one chain rather than one per run: an unguarded schedule
     * under a fixed reference leaves a second row behind every time, and
     * a recurring task then multiplies quietly until it is running
     * dozens of times a night.
     *
     * @param array<string, mixed> $payload
     */
    private function scheduleNext(TaskContext $context, array $payload, bool $workLeft): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $scheduler->rearmAfter(
            'core',
            self::TASK_KEY,
            self::REFERENCE,
            $workLeft ? 0 : self::IDLE_INTERVAL_SECONDS,
            $payload
        );
    }

    private function now(): \DateTimeImmutable
    {
        $now = $this->now !== null ? ($this->now)() : new \DateTimeImmutable();

        return $now instanceof \DateTimeImmutable ? $now : new \DateTimeImmutable();
    }
}
