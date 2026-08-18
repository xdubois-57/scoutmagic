<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Modules\Gallery\Api\DelegatedAlbum;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

/**
 * The concrete implementation behind Api\DelegatedAlbumManager — a thin
 * wrapper so the public API surface never exposes Repository\AlbumRepository
 * (or PDO) directly to other modules, same precedent as
 * Service\GalleryMemberQueryService for Api\GalleryAlbumProvider.
 *
 * Every method performs no authorisation of its own (Api\
 * DelegatedAlbumManager's docblock) — it only ever refuses on a genuine
 * data problem: an unknown album, one that isn't actually delegated, a
 * media that doesn't belong to it, or (ensureAlbum() only) a storage
 * location that can't host a delegated album at all.
 */
class DelegatedAlbumService implements DelegatedAlbumManager
{
    public function __construct(
        private AlbumRepository $albumRepository,
        private MediaRepository $mediaRepository,
        private MediaService $mediaService,
        private StorageLocationRepository $storageLocationRepository,
        private StorageLocationService $storageLocationService,
        private StorageBackendFactory $storageBackendFactory,
        private ScoutYearService $scoutYearService
    ) {
    }

    public function ensureAlbum(
        string $ownerType,
        int $ownerId,
        string $title,
        string $albumDate,
        int $createdBy,
        ?int $storageLocationId = null
    ): DelegatedAlbum {
        $existing = $this->albumRepository->findByOwner($ownerType, $ownerId);
        if ($existing !== null) {
            return $this->toAlbumDto($existing);
        }

        // Same "never let the caller choose a nonexistent location"
        // self-healing as an ordinary local album (Service\AlbumService::
        // create()) — a fresh/upgraded install always ends up with at
        // least one location once this has run.
        $this->storageLocationService->ensureLegacyLocationBackfilled();
        $location = $storageLocationId !== null
            ? $this->storageLocationRepository->findById($storageLocationId)
            : $this->storageLocationRepository->findDefault();
        if ($location === null) {
            throw new GalleryException('Aucun emplacement de stockage disponible pour héberger cet album.');
        }
        if ($location->s3PublicUrl !== null && $location->s3PublicUrl !== '') {
            // The one storage restriction on a delegated album: a public-
            // prefix S3 location serves objects straight from that public
            // URL, so a presigned URL changes nothing — the media would be
            // world-readable by anyone with the link, defeating the whole
            // point of delegated access control.
            throw new GalleryException(
                "L'emplacement de stockage « {$location->label} » est configuré avec une URL publique. "
                . 'Un album délégué doit obligatoirement utiliser un emplacement privé (accès uniquement '
                . 'via une URL pré-signée à courte durée, ou un fichier local protégé).'
            );
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        try {
            $albumId = $this->albumRepository->create(
                Album::TYPE_LOCAL, $title, null, $albumDate, null, $scoutYearId, null, $location->id, $createdBy, $ownerType, $ownerId
            );
        } catch (\PDOException $e) {
            // Two callers racing to create the first album for the same
            // owner: the loser's INSERT fails on gallery_albums'
            // UNIQUE(owner_type, owner_id) index (schema.sql's comment on
            // that pair) rather than creating a second album. The find()
            // above already missed the winner's row (it wasn't committed
            // yet at that point) — it will see it now.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $winner = $this->albumRepository->findByOwner($ownerType, $ownerId);
            \assert($winner !== null);
            return $this->toAlbumDto($winner);
        }

        $created = $this->albumRepository->findById($albumId);
        \assert($created !== null);
        return $this->toAlbumDto($created);
    }

    public function addMedia(int $albumId, array $uploadedFile, ?int $accountId): DelegatedMedia
    {
        $album = $this->resolveDelegatedAlbum($albumId);
        $media = $this->mediaService->addWithoutAuthorisationCheck($album, $uploadedFile, $accountId);

        return $this->toMediaDto($media);
    }

    public function listMedia(int $albumId): array
    {
        $this->resolveDelegatedAlbum($albumId);

        return array_map(fn(Media $m) => $this->toMediaDto($m), $this->mediaRepository->findByAlbumId($albumId));
    }

    public function deleteMedia(int $albumId, int $mediaId): void
    {
        $album = $this->resolveDelegatedAlbum($albumId);

        $media = $this->mediaRepository->findById($mediaId);
        if ($media === null || $media->albumId !== $albumId) {
            throw new GalleryException('Ce média n\'appartient pas à cet album.');
        }

        $this->mediaService->deleteWithoutAuthorisationCheck($media, $album);
    }

    public function deleteAlbum(int $albumId): void
    {
        $album = $this->resolveDelegatedAlbum($albumId);

        // DB cascade removes gallery_media rows on its own (schema.sql's
        // ON DELETE CASCADE), but never touches the storage backend — that
        // cleanup is explicit here, same as Service\AlbumService::delete().
        $location = $this->storageLocationService->resolveLocationForAlbum($album);
        if ($location !== null) {
            $this->storageBackendFactory->create($location)->deletePrefix((string) $albumId);
        }

        $this->albumRepository->delete($albumId);
    }

    public function videoUploadAllowed(): bool
    {
        return $this->mediaService->videoUploadAllowed();
    }

    /**
     * @throws GalleryException when $albumId doesn't exist or isn't delegated
     */
    private function resolveDelegatedAlbum(int $albumId): Album
    {
        $album = $this->albumRepository->findById($albumId);
        if ($album === null || !$album->isDelegated()) {
            throw new GalleryException('Album délégué introuvable.');
        }

        return $album;
    }

    private function toAlbumDto(Album $album): DelegatedAlbum
    {
        return new DelegatedAlbum($album->id, $album->title, $album->albumDate);
    }

    private function toMediaDto(Media $media): DelegatedMedia
    {
        return new DelegatedMedia(
            $media->id,
            $media->mediaType,
            $media->processingStatus,
            $media->sortOrder,
            $media->originalFilename,
            $media->createdAt
        );
    }
}
