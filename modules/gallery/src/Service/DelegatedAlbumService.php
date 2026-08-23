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
use Modules\Gallery\Service\Storage\StorageBackendInterface;

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

    public function moveMedia(string $ownerType, int $fromAlbumId, int $toAlbumId): int
    {
        if ($fromAlbumId === $toAlbumId) {
            throw new GalleryException('Un album ne peut pas être fusionné avec lui-même.');
        }

        $from = $this->resolveOwnedAlbum($ownerType, $fromAlbumId);
        $to = $this->resolveOwnedAlbum($ownerType, $toAlbumId);

        // copy() lives on ONE backend, and Service\StorageLocationService::
        // resolveLocationForAlbum() reads the location off the album — so a
        // media landing in an album on another location would look for its
        // bytes on a backend that never held them. Refused rather than
        // silently half-done.
        $fromLocation = $this->storageLocationService->resolveLocationForAlbum($from);
        $toLocation = $this->storageLocationService->resolveLocationForAlbum($to);
        if ($fromLocation === null || $toLocation === null || $fromLocation->id !== $toLocation->id) {
            throw new GalleryException(
                'Ces deux albums ne sont pas hébergés sur le même emplacement de stockage : '
                . 'leurs médias ne peuvent pas être regroupés sans être ré-envoyés.'
            );
        }

        $media = $this->mediaRepository->findByAlbumId($fromAlbumId);
        if ($media === []) {
            return 0;
        }

        $backend = $this->storageBackendFactory->create($fromLocation);
        $sortOrder = $this->mediaRepository->nextSortOrder($toAlbumId);
        $movedKeys = [];

        // findByAlbumId() already returns the album's own order, so walking
        // it while incrementing one rank appends the whole group after the
        // target's existing media without disturbing their relative order.
        foreach ($media as $item) {
            $paths = [];
            foreach (['thumbPath', 'mediumPath', 'largePath', 'originalPath'] as $property) {
                $paths[$property] = $this->moveObject($backend, $item->$property, $fromAlbumId, $toAlbumId, $movedKeys);
            }

            // The row is rewritten only once every one of its renditions is
            // readable at its new key. An interruption before this point
            // leaves copies nothing points at (harmless, and cleaned up with
            // the source album); an interruption after it leaves source
            // objects nothing points at — never a row pointing at bytes that
            // are gone.
            $this->mediaRepository->moveToAlbum(
                $item->id,
                $toAlbumId,
                $paths['thumbPath'],
                $paths['mediumPath'],
                $paths['largePath'],
                $paths['originalPath'],
                $sortOrder++
            );
        }

        foreach ($movedKeys as $sourceKey) {
            $backend->delete($sourceKey);
        }

        return count($media);
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
     * A delegated album that additionally belongs to $ownerType — the fence
     * that stops one module merging another module's albums by guessing an
     * id.
     *
     * @throws GalleryException when it doesn't exist, isn't delegated, or
     *         belongs to a different owner type
     */
    private function resolveOwnedAlbum(string $ownerType, int $albumId): Album
    {
        $album = $this->resolveDelegatedAlbum($albumId);
        if ($album->ownerType !== $ownerType) {
            throw new GalleryException('Album délégué introuvable.');
        }

        return $album;
    }

    /**
     * Copies one rendition under the target album's prefix and returns its
     * new key, recording the source key in $movedKeys for deletion once the
     * whole album has moved.
     *
     * A null path (a video with no kept original, a still-processing photo)
     * stays null. So does a path that does not actually sit under the source
     * album's prefix: those exist only from before renditions were keyed by
     * album, and re-keying one on a guess would lose it outright.
     *
     * @param array<int, string> $movedKeys
     */
    private function moveObject(
        StorageBackendInterface $backend,
        ?string $path,
        int $fromAlbumId,
        int $toAlbumId,
        array &$movedKeys
    ): ?string {
        $prefix = $fromAlbumId . '/';
        if ($path === null || $path === '' || !str_starts_with($path, $prefix)) {
            return $path;
        }

        $newPath = $toAlbumId . '/' . substr($path, strlen($prefix));
        $backend->copy($path, $newPath);
        $movedKeys[] = $path;

        return $newPath;
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
