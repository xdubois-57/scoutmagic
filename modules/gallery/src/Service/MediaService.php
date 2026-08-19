<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Scheduler\SchedulerService;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

class MediaService
{
    /** @var string[] */
    private const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    /** @var string[] */
    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    /**
     * $storedFileCleaner is what lets delete() actually reclaim an original
     * upload's bytes; it is trailing and nullable so a caller that doesn't
     * wire it degrades to the old "leave the original behind" behaviour
     * instead of failing to construct.
     */
    public function __construct(
        private MediaRepository $mediaRepository,
        private AlbumRepository $albumRepository,
        private UploadHandler $uploadHandler,
        private SchedulerService $schedulerService,
        private SettingService $settingService,
        private GalleryAccessService $accessService,
        private StorageBackendFactory $storageBackendFactory,
        private StorageLocationService $storageLocationService,
        private FfmpegAvailability $ffmpegAvailability,
        private ?StoredFileCleaner $storedFileCleaner = null
    ) {
    }

    /**
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @throws GalleryException on an invalid file, a disabled type,
     *                           over-quota, a migration in flight, or a
     *                           permission failure
     */
    public function upload(Album $album, array $uploadedFile, Role $role, string $email, ?int $accountId): Media
    {
        if ($album->isDelegated()) {
            // Ordinary, access-checked uploads never touch a delegated
            // album — only addWithoutAuthorisationCheck() below does,
            // called exclusively by Service\DelegatedAlbumService.
            throw new GalleryException('Album introuvable.');
        }
        if (!$album->isLocal()) {
            throw new GalleryException('Cet album n\'accepte pas de médias (album externe).');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        // A media uploaded mid-migration is processed against the album's
        // CURRENT (source) location, which Task\MigrateAlbumStorageHandler
        // has already walked past — completeMigration() then flips the album
        // onto the target and deletes the source prefix, taking the new
        // media's renditions with it. Refuse the upload instead.
        $this->assertNotMigrating($album);

        return $this->store($album, $uploadedFile, $accountId);
    }

    /**
     * Adds a media to an already-resolved, already-authorised album — no
     * canManageAlbum() call, no isLocal() gate: only ever called by
     * Service\DelegatedAlbumService (Api\DelegatedAlbumManager's
     * implementation), which has already confirmed the album is its own
     * delegated one and that the caller may write to it — gallery's own
     * section-based authorisation simply does not apply to a delegated
     * album (Api\DelegatedAlbumManager's docblock). The quota, MIME/size
     * limits and processing scheduling below are resource management, not
     * authorisation, and stay identical either way.
     *
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @throws GalleryException on an invalid file, a disabled type, or over-quota
     */
    public function addWithoutAuthorisationCheck(Album $album, array $uploadedFile, ?int $accountId): Media
    {
        return $this->store($album, $uploadedFile, $accountId);
    }

    /**
     * @param array<string, mixed> $uploadedFile $_FILES entry
     * @throws GalleryException on an invalid file, a disabled type, or over-quota
     */
    private function store(Album $album, array $uploadedFile, ?int $accountId): Media
    {
        $maxMedia = (int) $this->settingService->get('gallery_max_media_per_album', 'gallery', 200);
        if ($maxMedia < 1) {
            throw new GalleryException('La limite de médias par album est mal configurée — prévenez un administrateur.');
        }
        if ($this->mediaRepository->countByAlbumId($album->id) >= $maxMedia) {
            throw new GalleryException("Cet album a atteint la limite de {$maxMedia} médias.");
        }

        $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
        $mimeType = $tmpName !== '' && is_file($tmpName) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName) : '';
        $isVideo = in_array($mimeType, self::VIDEO_MIMES, true);
        $isPhoto = in_array($mimeType, self::PHOTO_MIMES, true);

        if (!$isPhoto && !$isVideo) {
            throw new GalleryException('Type de fichier non autorisé.');
        }
        if ($isVideo && !$this->videoUploadAllowed()) {
            throw new GalleryException('L\'envoi de vidéos est désactivé.');
        }

        $maxBytes = $isVideo
            ? (int) $this->settingService->get('gallery_max_video_upload_mb', 'gallery', 2048) * 1024 * 1024
            : (int) $this->settingService->get('gallery_max_photo_upload_mb', 'gallery', 30) * 1024 * 1024;
        $allowedMimes = $isVideo ? self::VIDEO_MIMES : self::PHOTO_MIMES;

        try {
            // A delegated album's original is scoped by the same
            // owner_type/owner_id as the album itself (null/null for an
            // ordinary album, unchanged) — so /files/{id} is governed by
            // the exact same ownership check as /gallery/media/{id}/{size},
            // instead of stopping at the flat 'identified' floor below.
            $fileId = $this->uploadHandler->handle(
                $uploadedFile, "gallery/{$album->id}/orig", $allowedMimes, $maxBytes, 'identified', 'gallery', $accountId,
                $album->ownerType, $album->ownerId
            );
        } catch (UploadException $e) {
            throw new GalleryException($e->getMessage());
        }

        $sortOrder = $this->mediaRepository->nextSortOrder($album->id);
        $originalFilename = isset($uploadedFile['name']) ? (string) $uploadedFile['name'] : null;
        $mediaId = $this->mediaRepository->create(
            $album->id, $isVideo ? Media::TYPE_VIDEO : Media::TYPE_PHOTO, $fileId, $sortOrder, $originalFilename
        );

        // First upload becomes the album cover automatically (module spec:
        // "cover selection") — the chief can still change it later. A
        // delegated album is never rendered through gallery's own views
        // (excluded from every listing), so this is harmless bookkeeping
        // for that case, not a UI feature it actually gets.
        if ($album->coverMediaId === null) {
            $this->albumRepository->setCoverMediaId($album->id, $mediaId);
        }

        $taskKey = $isVideo ? 'process_video' : 'process_photo';
        $this->schedulerService->scheduleAfter('gallery', $taskKey, 0, ['media_id' => $mediaId]);

        $created = $this->mediaRepository->findById($mediaId);
        \assert($created !== null);

        return $created;
    }

    /**
     * @throws GalleryException when the current chief doesn't manage this
     *                           album, or a migration is in flight
     */
    public function delete(Media $media, Album $album, Role $role, string $email): void
    {
        if ($album->isDelegated()) {
            throw new GalleryException('Album introuvable.');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        // Task\MigrateAlbumStorageHandler is copying these very files; a
        // deletion underneath it aborts the migration for no good reason.
        $this->assertNotMigrating($album);

        $this->remove($media, $album);
    }

    /**
     * Removes a media from an already-resolved, already-authorised
     * delegated album — same "no gallery authorisation" contract as
     * addWithoutAuthorisationCheck() above. Only ever called by
     * Service\DelegatedAlbumService.
     */
    public function deleteWithoutAuthorisationCheck(Media $media, Album $album): void
    {
        $this->remove($media, $album);
    }

    private function remove(Media $media, Album $album): void
    {
        if ($album->isLocal()) {
            $location = $this->storageLocationService->resolveLocationForAlbum($album);
            if ($location !== null) {
                $backend = $this->storageBackendFactory->create($location);
                foreach ([$media->thumbPath, $media->mediumPath, $media->largePath, $media->originalPath] as $path) {
                    if ($path !== null) {
                        $backend->delete($path);
                    }
                }
            }
        }

        if ($album->coverMediaId === $media->id) {
            $this->albumRepository->setCoverMediaId($album->id, null);
        }

        // gallery_media first: files(id) is referenced by
        // gallery_media.file_id with no ON DELETE clause, so the child row
        // has to be gone before the parent can be.
        $this->mediaRepository->delete($media->id);
        $this->storedFileCleaner?->delete($media->fileId);
    }

    /**
     * @param int[] $orderedMediaIds
     * @throws GalleryException when the list doesn't match the album's
     *                           media exactly, a migration is in flight, or
     *                           on a permission failure
     */
    public function reorder(Album $album, array $orderedMediaIds, Role $role, string $email): void
    {
        if ($album->isDelegated()) {
            throw new GalleryException('Album introuvable.');
        }
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            throw new GalleryException('Vous ne gérez pas cette section.');
        }
        $this->assertNotMigrating($album);

        $existingIds = array_map(fn(Media $m) => $m->id, $this->mediaRepository->findByAlbumId($album->id));
        // array_unique BEFORE intersecting: array_intersect keeps duplicates
        // from its first argument, so a payload of [7, 7, 7] against an album
        // of three media used to pass the count check below and then rewrite
        // media #7's rank three times, leaving the other two untouched.
        $submittedIds = array_values(array_unique($orderedMediaIds));
        $validIds = array_values(array_intersect($submittedIds, $existingIds));
        if (count($validIds) !== count($existingIds)) {
            throw new GalleryException('Liste de médias invalide.');
        }

        $this->mediaRepository->reorder($album->id, $validIds);
    }

    /**
     * Public URL for a given rendition — routed through this app's own
     * access-controlled endpoint for local storage, or straight to the
     * object storage (public prefix or a pre-signed URL) for S3, so a
     * configured CDN/public bucket isn't needlessly proxied through PHP.
     *
     * Never called for a delegated album: its media are addressed only
     * through Controller\GalleryController::serveMedia(), which consults
     * Service\DelegatedAlbumAccessRegistry before revealing anything — an
     * S3 URL minted here would bypass that check entirely, handing out
     * either a public-bucket link or an hour-long presigned one with no
     * ownership check at all. Every caller (Service\
     * GalleryMemberQueryService, Controller\GalleryController/
     * GalleryChiefController) only ever reaches an album via
     * AlbumRepository::findAll()/findVisible() or a controller's own
     * isDelegated() guard, both of which already exclude delegated
     * albums — this null return is the defense-in-depth backstop for that
     * invariant, not the primary enforcement.
     */
    public function resolveUrl(Media $media, Album $album, string $size): ?string
    {
        if ($album->isDelegated()) {
            return null;
        }

        $path = self::pathForSize($media, $size);
        if ($path === null) {
            return null;
        }

        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location === null) {
            return null;
        }

        if ($location->isS3()) {
            return $this->storageBackendFactory->create($location)->url($path);
        }

        return '/gallery/media/' . $media->id . '/' . $size;
    }

    /**
     * The storage key backing one named rendition, or null when that
     * rendition doesn't exist (not processed yet, or a video original the
     * admin chose not to keep). Static and shared so resolveUrl() and
     * Controller\GalleryController::serveMedia() can never drift apart on
     * which sizes exist.
     */
    public static function pathForSize(Media $media, string $size): ?string
    {
        return match ($size) {
            'thumb' => $media->thumbPath,
            'medium' => $media->mediumPath,
            'large' => $media->largePath,
            'original' => $media->originalPath,
            default => null,
        };
    }

    /**
     * Video uploads are only actually possible when both the setting is
     * on AND ffmpeg/ffprobe are present on the server (module spec:
     * "gallery_allow_video is effectively forced to false regardless of
     * DB setting" when FFmpeg is missing).
     */
    public function videoUploadAllowed(): bool
    {
        return (bool) $this->settingService->get('gallery_allow_video', 'gallery', true) && $this->ffmpegAvailability->check();
    }

    /**
     * @throws GalleryException when a storage migration is in flight for $album
     */
    private function assertNotMigrating(Album $album): void
    {
        if ($album->isMigrating()) {
            throw new GalleryException('Une migration de stockage est en cours pour cet album — réessayez une fois celle-ci terminée.');
        }
    }
}
