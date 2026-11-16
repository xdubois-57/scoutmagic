<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\AlbumReadme;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\Backend\StorageBackendFactory;

/**
 * Background storage migration (module spec: "the gallery is not available
 * [...] and the photos at the source location are deleted only when the
 * migration is finished") — triggered from Configuration > Galerie
 * (superadmin) via Controller\GalleryConfigController::migrateAlbumStorage().
 * Copies every
 * rendition of every media row from the album's current storage location to
 * migration_target_id, verifying each file immediately after
 * writing it (one at a time — never holding every file's bytes in memory
 * simultaneously, some renditions are full-size videos).
 *
 * Safety invariant: the source is only ever touched (deleted) after EVERY
 * file has been copied AND verified — any failure anywhere aborts
 * immediately, leaves location_id completely untouched (still
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
        $storageBackendFactory = $this->storageBackendFactory ?? new StorageBackendFactory(
            $storageLocationRepository,
            $context->storagePath
        );

        $album = $albumRepository->findById($albumId);
        if (
            $album === null
            || $album->migrationStatus !== Album::MIGRATION_IN_PROGRESS
            || $album->migrationTargetId === null
        ) {
            return;
        }

        $targetLocation = $storageLocationRepository->findById($album->migrationTargetId);
        if ($targetLocation === null) {
            // Deleted out from under an in-progress migration — nothing
            // safe to do; leave the album as-is for an admin to pick a new
            // target and retry.
            return;
        }
        $sourceLocation = $album->locationId !== null
            ? $storageLocationRepository->findById($album->locationId)
            : null;
        if ($sourceLocation === null) {
            // Marked failed, NOT returned from silently. A bare return
            // here left `migration_status` at 'in_progress' for ever: the
            // album stayed unavailable, and every retry was refused by the
            // « une migration est déjà en cours » guard that reads the
            // same column. A migration that cannot start has to say so, or
            // it is indistinguishable from one still running.
            $albumRepository->failMigration(
                $albumId,
                "L'emplacement d'origine de cet album est introuvable — la migration n'a pas pu commencer."
            );

            return;
        }

        $sourceBackend = $storageBackendFactory->create($sourceLocation);
        $targetBackend = $storageBackendFactory->create($targetLocation);

        foreach ($mediaRepository->findByAlbumId($albumId) as $media) {
            foreach ($this->pathsOf($media) as $path) {
                try {
                    $sourceBytes = $sourceBackend->get($path);
                } catch (\Throwable $e) {
                    $this->failOnAFile(
                        $context,
                        $albumRepository,
                        $albumId,
                        $media,
                        $path,
                        'source',
                        "Un fichier de l'album n'a pas pu être lu sur l'emplacement source.",
                        $e
                    );
                    return;
                }

                try {
                    $targetBackend->put($path, $sourceBytes, $this->guessMimeType($path));
                    $verifyBytes = $targetBackend->get($path);
                } catch (\Throwable $e) {
                    $this->failOnAFile(
                        $context,
                        $albumRepository,
                        $albumId,
                        $media,
                        $path,
                        'target',
                        "Un fichier de l'album n'a pas pu être écrit sur l'emplacement cible.",
                        $e
                    );
                    return;
                }

                if ($verifyBytes !== $sourceBytes) {
                    $albumRepository->failMigration(
                        $albumId,
                        "Vérification échouée pour le fichier « {$path} » (média #{$media->id}) : contenu différent "
                            . "après copie."
                    );
                    return;
                }

                unset($sourceBytes, $verifyBytes);
            }
        }

        // **The album folder's LISEZMOI follows it** (#474). Written at
        // the destination before the album is switched over, like the
        // media; best effort, since the album works without it — and the
        // one at the source goes with the cleanup below. A delegated album
        // never had one.
        if (!$album->isDelegated()) {
            (new AlbumReadme($context->settings, $context->journal))->write($album, $targetBackend);
        }

        $albumRepository->completeMigration($albumId, $targetLocation->id);

        $context->journal->log(
            'gallery',
            'album_storage_migrated',
            'info',
            "Album #{$albumId} migré de « {$sourceLocation->label} » vers « {$targetLocation->label} »",
            ['album_id' => $albumId, 'from_location_id' => $sourceLocation->id, 'to_location_id' => $targetLocation->id]
        );

        // Best-effort cleanup only — the album is already fully functional
        // at the new location by this point; a failure here is harmless
        // orphaned data at the old location, never re-surfaced as a
        // migration error (module spec: the migration itself already
        // succeeded, this is just tidying up after it).
        //
        // **Swallowed, but no longer silent** (#484). This is the most
        // destructive `deletePrefix()` call in the application — it
        // removes an album from a location that still holds OTHER albums
        // — and it was the one call whose outcome nothing recorded. The
        // migration stays successful either way; what changes is that an
        // operator asking « why is the old location still full » has an
        // entry to find.
        try {
            $sourceBackend->deletePrefix((string) $albumId);
        } catch (\Throwable $e) {
            $context->journal->log(
                'gallery',
                'album_storage_cleanup_failed',
                'warning',
                "Album #{$albumId} migré, mais son ancienne copie n'a pas pu être supprimée de "
                . "« {$sourceLocation->label} »",
                [
                    'album_id' => $albumId,
                    'from_location_id' => $sourceLocation->id,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }

    /**
     * Fails the migration on a file that could not be read or written.
     *
     * **What the badge shows is a French sentence and nothing else** (#1280).
     * `migration_error` is rendered in a tooltip for the staff, and the
     * message of an exception from the storage library is English, names an
     * internal path and, depending on the backend, a bucket, an account or
     * the provider's own answer — a screen never shows a library's text. The
     * cause is not lost: it is journaled beside, at `warning`, with the
     * media, the path and the exception's class, and never its message.
     */
    private function failOnAFile(
        TaskContext $context,
        AlbumRepository $albumRepository,
        int $albumId,
        Media $media,
        string $path,
        string $side,
        string $sentence,
        \Throwable $e
    ): void {
        $albumRepository->failMigration($albumId, $sentence);

        $context->journal->log(
            'gallery',
            'album_storage_migration_failed',
            'warning',
            "Migration de l'album #{$albumId} interrompue : un fichier n'a pas pu être "
                . ($side === 'source' ? 'lu' : 'écrit') . '.',
            [
                'album_id' => $albumId,
                'media_id' => $media->id,
                'path' => $path,
                'side' => $side,
                'error' => $e::class,
            ]
        );
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
