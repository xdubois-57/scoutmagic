<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;

/**
 * Keeps photo/video processing out of the way of a storage migration.
 *
 * Task\MigrateAlbumStorageHandler copies the album's renditions one media at
 * a time and, once every file has been verified, flips the album onto the
 * target and deletes the source prefix. A processing task that ran during
 * that window would write its renditions to the album's CURRENT (source)
 * location — already walked past by the copy loop — and the final cleanup
 * would then delete them, leaving a permanently broken media row.
 * Service\MediaService refuses new uploads while a migration is in flight,
 * but a media uploaded just BEFORE it started can still have its task come
 * up mid-migration, which is what this closes.
 *
 * The media is left 'pending' and the task re-queued, so it resumes on its
 * own once the migration ends (successfully or not — a 'failed' migration
 * leaves the album readable at its untouched source).
 */
class MediaProcessingGate
{
    private const DEFER_SECONDS = 60;

    /**
     * Bounded so a migration wedged on 'in_progress' (its handler killed
     * mid-run, say) can't re-queue the same task forever.
     */
    private const MAX_DEFERRALS = 10;

    /**
     * @param array<string, mixed> $payload the payload the task was invoked with
     * @return bool true when the task has been re-queued for later; false when
     *              the retry budget is spent and the caller should mark the
     *              media failed
     */
    public function deferWhileMigrating(
        string $taskKey,
        int $mediaId,
        int $albumId,
        array $payload,
        TaskContext $context
    ): bool
    {
        $deferrals = (int) ($payload['migration_deferrals'] ?? 0);
        if ($deferrals >= self::MAX_DEFERRALS) {
            return false;
        }

        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $scheduler->scheduleAfter('gallery', $taskKey, self::DEFER_SECONDS, [
            'media_id' => $mediaId,
            'migration_deferrals' => $deferrals + 1,
        ]);

        $context->journal->log(
            'gallery',
            'media_processing_deferred',
            'info',
            'Traitement d\'un média reporté — migration de stockage en cours',
            ['media_id' => $mediaId, 'album_id' => $albumId, 'deferrals' => $deferrals + 1]
        );

        return true;
    }
}
