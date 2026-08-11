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
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\VideoProcessingService;

/**
 * Background video transcode (module spec) — requires ffmpeg/ffprobe
 * (Service\FfmpegAvailability, checked before scheduling by Service\
 * MediaService::upload(); if the binaries later disappear from the server
 * between upload and processing, this fails gracefully like any other
 * processing error).
 */
class ProcessVideoHandler implements TaskHandlerInterface
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

        $tempDir = sys_get_temp_dir() . '/gallery_video_' . $mediaId . '_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0755, true);
        $sourceTempPath = $tempDir . '/source';

        try {
            $file = $fileRepository->findById($media->fileId);
            if ($file === null) {
                throw new GalleryException('Fichier original introuvable.');
            }

            $sourcePath = $context->storagePath . '/' . $file->relativePath;
            if (!is_file($sourcePath)) {
                throw new GalleryException('Fichier original illisible.');
            }
            copy($sourcePath, $sourceTempPath);

            $video = new VideoProcessingService();
            $probe = $video->probe($sourceTempPath);

            $maxDuration = (int) $context->settings->get('gallery_max_video_duration_sec', 'gallery', 1800);
            if ($probe['durationSeconds'] > $maxDuration) {
                throw new GalleryException('Cette vidéo dépasse la durée maximale autorisée.');
            }

            $posterTempPath = $tempDir . '/poster.jpg';
            $video->extractPoster($sourceTempPath, $posterTempPath);

            $mediumTempPath = $tempDir . '/med.mp4';
            $video->transcode($sourceTempPath, $mediumTempPath, 720);

            $largeTempPath = null;
            if ($probe['height'] > 720) {
                $largeTempPath = $tempDir . '/lg.mp4';
                $video->transcode($sourceTempPath, $largeTempPath, 1080);
            }

            $storageLocationRepository = new StorageLocationRepository($context->connection->getPdo(), $context->encryption);
            $album = (new AlbumRepository($context->connection->getPdo()))->findById($media->albumId);
            $location = $album?->storageLocationId !== null ? $storageLocationRepository->findById($album->storageLocationId) : null;
            if ($location === null) {
                throw new GalleryException('Emplacement de stockage introuvable pour cet album.');
            }
            $storage = (new StorageBackendFactory($storageLocationRepository, $context->storagePath))->create($location);
            $thumbKey = "{$media->albumId}/thumb_{$mediaId}.jpg";
            $mediumKey = "{$media->albumId}/med_{$mediaId}.mp4";
            $storage->put($thumbKey, (string) file_get_contents($posterTempPath), 'image/jpeg');
            $storage->put($mediumKey, (string) file_get_contents($mediumTempPath), 'video/mp4');

            $largeKey = null;
            if ($largeTempPath !== null) {
                $largeKey = "{$media->albumId}/lg_{$mediaId}.mp4";
                $storage->put($largeKey, (string) file_get_contents($largeTempPath), 'video/mp4');
            }

            $keepOriginal = (bool) $context->settings->get('gallery_keep_original_video', 'gallery', false);
            $originalKey = null;
            if ($keepOriginal) {
                $originalKey = "{$media->albumId}/orig_{$mediaId}." . pathinfo($file->relativePath, PATHINFO_EXTENSION);
                $storage->put($originalKey, (string) file_get_contents($sourceTempPath), $file->mimeType);
            }

            $mediaRepository->markVideoDone(
                $mediaId, $thumbKey, $mediumKey, $largeKey, $originalKey, $probe['width'], $probe['height'], $probe['durationSeconds']
            );

            // Module spec: drop the staging original once processed,
            // unless the admin opted to keep a served copy (above).
            @unlink($sourcePath);
        } catch (\Throwable $e) {
            $mediaRepository->markFailed($mediaId);
            $context->journal->log(
                'gallery', 'video_processing_failed', 'info', 'Échec du traitement d\'une vidéo',
                ['media_id' => $mediaId, 'album_id' => $media->albumId, 'error' => $e->getMessage()]
            );
        } finally {
            $this->removeDirectory($tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
