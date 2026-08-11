<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Task;

use Core\File\FileRepository;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\ImageProcessingService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

/**
 * Background photo resize (module spec) — scheduled by Service\
 * MediaService::upload() right after the original is stored, runs on the
 * next scheduler tick (real cron or the poor-man's-cron in public/index.php).
 */
class ProcessPhotoHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $mediaId = (int) ($payload['media_id'] ?? 0);
        if ($mediaId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $mediaRepository = new MediaRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        $media = $mediaRepository->findById($mediaId);
        if ($media === null || $media->processingStatus === 'done') {
            return;
        }

        $mediaRepository->markProcessing($mediaId);

        try {
            $file = $fileRepository->findById($media->fileId);
            if ($file === null) {
                throw new GalleryException('Fichier original introuvable.');
            }

            $sourcePath = $context->storagePath . '/' . $file->relativePath;
            $contents = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
            if ($contents === false) {
                throw new GalleryException('Fichier original illisible.');
            }

            $maxDimension = (int) $context->settings->get('gallery_photo_max_dimension', 'gallery', 3000);
            $result = (new ImageProcessingService())->process($contents, $file->mimeType, $maxDimension);

            $storageLocationRepository = new StorageLocationRepository($context->connection->getPdo(), $context->encryption);
            $album = (new AlbumRepository($context->connection->getPdo()))->findById($media->albumId);
            $location = $album?->storageLocationId !== null ? $storageLocationRepository->findById($album->storageLocationId) : null;
            if ($location === null) {
                throw new GalleryException('Emplacement de stockage introuvable pour cet album.');
            }
            $storage = (new StorageBackendFactory($storageLocationRepository, $context->storagePath))->create($location);
            $thumbKey = "{$media->albumId}/thumb_{$mediaId}.jpg";
            $mediumKey = "{$media->albumId}/med_{$mediaId}.jpg";
            $largeKey = "{$media->albumId}/lg_{$mediaId}.jpg";

            $storage->put($thumbKey, $result['thumb'], 'image/jpeg');
            $storage->put($mediumKey, $result['medium'], 'image/jpeg');
            $storage->put($largeKey, $result['large'], 'image/jpeg');

            $mediaRepository->markPhotoDone($mediaId, $thumbKey, $mediumKey, $largeKey, $result['width'], $result['height']);

            // Disk savings (module spec) — the derived sizes above are the
            // only ones ever served for a photo; the files-table metadata
            // row for the original stays (audit trail), only its bytes go.
            @unlink($sourcePath);
        } catch (\Throwable $e) {
            $mediaRepository->markFailed($mediaId);
            $context->journal->log(
                'gallery', 'photo_processing_failed', 'info', 'Échec du traitement d\'une photo',
                ['media_id' => $mediaId, 'album_id' => $media->albumId, 'error' => $e->getMessage()]
            );
        }
    }

}
