<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\File\StoredFileReader;
use Core\Security\Role;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Modules\Gallery\Api\AlbumShareSourceInterface;
use Modules\Gallery\Api\SharedAlbum;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\MediaRepository;

/**
 * The gallery's answer to « what would you publish of this album? ».
 *
 * The cover is read the way the gallery serves it — through the album's
 * storage location, whatever backend that is — and never through a URL:
 * the consumer composes an image from the bytes, it does not link to them.
 * A local album's cover is its chosen media (the large rendition of a
 * photo, the poster of a video); an external album's is the preview image
 * the gallery keeps as a file.
 */
final class AlbumShareSourceService implements AlbumShareSourceInterface
{
    public function __construct(
        private readonly AlbumService $albums,
        private readonly MediaRepository $media,
        private readonly GalleryAccessService $access,
        private readonly GalleryLocationService $locations,
        private readonly StorageBackendFactory $backends,
        private readonly StoredFileReader $files
    ) {
    }

    public function describe(int $albumId, string $role, string $email): ?SharedAlbum
    {
        $album = $this->albums->findById($albumId);
        if ($album === null || $album->isDelegated() || $album->isMigrating()) {
            return null;
        }
        if (!$this->access->canManageAlbum(Role::fromString($role), $album->sectionId, $email)) {
            return null;
        }

        return new SharedAlbum(
            $album->id,
            $album->displayTitle(),
            $this->cover($album),
            '/gallery/' . $album->id
        );
    }

    private function cover(Album $album): ?string
    {
        if (!$album->isLocal()) {
            return $album->ogImageFileId !== null ? $this->files->read($album->ogImageFileId) : null;
        }
        if ($album->coverMediaId === null) {
            return null;
        }

        $media = $this->media->findById($album->coverMediaId);
        if ($media === null || $media->albumId !== $album->id) {
            return null;
        }

        $path = $media->isVideo()
            ? $media->thumbPath
            : ($media->largePath ?? $media->mediumPath ?? $media->originalPath);
        $location = $path === null ? null : $this->locations->resolveLocationForAlbum($album);
        if ($path === null || $location === null) {
            return null;
        }

        try {
            return $this->backends->create($location)->get($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
