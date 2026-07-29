<?php

declare(strict_types=1);

namespace Modules\Gallery\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

/**
 * Background storage migration (module spec: "the gallery is not available
 * [...] and the photos at the source location are deleted only when the
 * migration is finished") — triggered from the album edit page via
 * Controller\GalleryChiefController::migrateStorage(). Copies every
 * rendition of every media row from the album's current storage location to
 * migration_target_location_id, verifying each file immediately after
 * writing it (one at a time — never holding every file's bytes in memory
 * simultaneously, some renditions are full-size videos).
 *
 * Safety invariant: the source is only ever touched (deleted) after EVERY
 * file has been copied AND verified — any failure anywhere aborts
 * immediately, leaves storage_location_id completely untouched (still
 * pointing at the fully intact source), and never deletes anything from
 * either side, so a retry can simply resume (the destination's partial
 * copy is harmless orphaned data, safely overwritten by the retry).
 */
class MigrateAlbumStorageHandler implements TaskHandlerInterface
{
    /**
     * $storageBackendFactory is normally left null (SchedulerRunner
     * instantiates every handler with `new $handlerClass()`, matching every
     * other handler in this module) and built fresh from $context on each
     * call — injectable only so tests can substitute a mock destination
     * backend to exercise the mid-migration failure paths, which a real
     * LocalStorageBackend can't be made to fail on demand for a single
     * specific file.
     */
    public function __construct(private ?StorageBackendFactory $storageBackendFactory = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $albumId = (int) ($payload['album_id'] ?? 0);
        if ($albumId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $albumRepository = new AlbumRepository($pdo);
        $mediaRepository = new MediaRepository($pdo);
        $storageLocationRepository = new StorageLocationRepository($pdo, $context->encryption);
        $storageBackendFactory = $this->storageBackendFactory ?? new StorageBackendFactory($storageLocationRepository, $context->storagePath);

        $album = $albumRepository->findById($albumId);
        if ($album === null || $album->migrationStatus !== Album::MIGRATION_IN_PROGRESS || $album->migrationTargetLocationId === null) {
            return;
        }

        $targetLocation = $storageLocationRepository->findById($album->migrationTargetLocationId);
        if ($targetLocation === null) {
            // Deleted out from under an in-progress migration — nothing
            // safe to do; leave the album as-is for an admin to pick a new
            // target and retry.
            return;
        }
        $sourceLocation = $album->storageLocationId !== null ? $storageLocationRepository->findById($album->storageLocationId) : null;
        if ($sourceLocation === null) {
            return;
        }

        $sourceBackend = $storageBackendFactory->create($sourceLocation);
        $targetBackend = $storageBackendFactory->create($targetLocation);

        foreach ($mediaRepository->findByAlbumId($albumId) as $media) {
            foreach ($this->pathsOf($media) as $path) {
                try {
                    $sourceBytes = $sourceBackend->get($path);
                } catch (\Throwable $e) {
                    $albumRepository->failMigration(
                        $albumId,
                        "Lecture impossible du fichier « {$path} » (média #{$media->id}) sur l'emplacement source : " . $e->getMessage()
                    );
                    return;
                }

                try {
                    $targetBackend->put($path, $sourceBytes, $this->guessMimeType($path));
                    $verifyBytes = $targetBackend->get($path);
                } catch (\Throwable $e) {
                    $albumRepository->failMigration(
                        $albumId,
                        "Écriture impossible du fichier « {$path} » (média #{$media->id}) sur l'emplacement cible : " . $e->getMessage()
                    );
                    return;
                }

                if ($verifyBytes !== $sourceBytes) {
                    $albumRepository->failMigration(
                        $albumId,
                        "Vérification échouée pour le fichier « {$path} » (média #{$media->id}) : contenu différent après copie."
                    );
                    return;
                }

                unset($sourceBytes, $verifyBytes);
            }
        }

        $albumRepository->completeMigration($albumId, $targetLocation->id);

        $context->journal->log(
            'gallery', 'album_storage_migrated', 'info',
            "Album #{$albumId} migré de « {$sourceLocation->label} » vers « {$targetLocation->label} »",
            ['album_id' => $albumId, 'from_location_id' => $sourceLocation->id, 'to_location_id' => $targetLocation->id]
        );

        // Best-effort cleanup only — the album is already fully functional
        // at the new location by this point; a failure here is harmless
        // orphaned data at the old location, never re-surfaced as a
        // migration error (module spec: the migration itself already
        // succeeded, this is just tidying up after it).
        try {
            $sourceBackend->deletePrefix((string) $albumId);
        } catch (\Throwable) {
        }
    }

    /**
     * @return string[]
     */
    private function pathsOf(Media $media): array
    {
        return array_values(array_filter(
            [$media->thumbPath, $media->mediumPath, $media->largePath, $media->originalPath],
            fn(?string $p) => $p !== null
        ));
    }

    private function guessMimeType(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
    }
}
