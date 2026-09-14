<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Protection\ProtectedCopier;
use Core\Storage\Location\Protection\ProtectionRepatriation;
use Core\Storage\Location\Protection\StorageInventoryStore;
use Core\Storage\Location\Protection\StorageProtectionRepository;
use Core\Storage\Location\StorageLocationRepository;

/**
 * Bringing a location's files back from its copy, in the background.
 *
 * **Asked for, never automatic**, which is the difference between this and
 * the nightly pass. Repatriation asks the source about every file the copy
 * holds — one request each on a bucket — and it writes to the source,
 * which is the one direction the nightly pass never goes. An
 * administrator asks for it after a restore, once.
 *
 * A chain of its own rather than a flag on the nightly one: it runs when
 * somebody presses the button and stops when it has finished, and folding
 * it into the recurring chain would mean a recurring task that sometimes
 * writes to the source, which is exactly the kind of « sometimes » that
 * nobody can reason about at two in the morning.
 */
class RepatriateFromCopyHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'repatriate_from_copy';

    public const TIME_BUDGET_SECONDS = 20;

    public function __construct(private readonly ?ProtectionRepatriation $repatriation = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $protectionId = (int) ($payload['protection_id'] ?? 0);
        if ($protectionId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $protections = new StorageProtectionRepository($pdo);
        $protection = $protections->findById($protectionId);
        if ($protection === null) {
            return;
        }

        $locations = new StorageLocationRepository($pdo, $context->encryption);
        $source = $locations->findById($protection->sourceLocationId);
        $destination = $locations->findById($protection->destinationLocationId);
        if ($source === null || $destination === null) {
            return;
        }

        $backends = new StorageBackendFactory($locations, $context->storagePath);
        $repatriation = $this->repatriation
            ?? new ProtectionRepatriation(new StorageInventoryStore(), new ProtectedCopier());

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;

        try {
            $result = $repatriation->run(
                $protection,
                $backends->create($source),
                $backends->create($destination),
                $source->label,
                static fn(): bool => microtime(true) < $deadline,
                is_string($payload['cursor'] ?? null) ? (string) $payload['cursor'] : null
            );
        } catch (\Throwable $e) {
            $context->journal->log(
                'core',
                'storage_repatriation_failed',
                'warning',
                sprintf(
                    'Le rapatriement de « %s » depuis « %s » n\'a pas abouti. Rien n\'a été supprimé.',
                    $source->label,
                    $destination->label
                )
            );

            return;
        }

        if (!$result->finished) {
            // Straight back, like the nightly pass: a gallery of a hundred
            // thousand files would otherwise advance twenty seconds at a
            // time with nothing scheduling the rest.
            (new SchedulerService(new SchedulerRepository($pdo)))->scheduleAfter(
                'core',
                self::TASK_KEY,
                0,
                ['protection_id' => $protectionId, 'cursor' => $result->cursor]
            );

            return;
        }

        // Counts, never keys — a journal entry is carried by a support
        // package that leaves the installation, and an object key is a
        // path somebody chose.
        $context->journal->log(
            'core',
            'storage_repatriation_done',
            'info',
            sprintf(
                'Rapatriement de « %s » depuis « %s » : %d fichier(s) remis en place.',
                $source->label,
                $destination->label,
                $result->restoredCount
            ),
            ['failures' => count($result->failures)]
        );
    }
}
