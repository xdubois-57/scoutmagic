<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Security\Role;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Modules\Gallery\Api\PhotoPickerInterface;
use Modules\Gallery\Api\PickablePhoto;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;

/**
 * The photo picker (Api\PhotoPickerInterface): thirty photos without
 * navigation, local albums only, photos only.
 *
 * **Who may see an album is the gallery's rule, reused as it stands.** An
 * ordinary album is visible to a chief whatever its section
 * (Controller\GalleryController::isVisible()); a delegated one is visible
 * when its owning module's checker says so
 * (Service\DelegatedAlbumAccessRegistry), exactly as when its media are
 * served. A second implementation of « what this chief may see » would end
 * up diverging, and it is always that one which lets through what it
 * should not.
 */
final class PhotoPickerService implements PhotoPickerInterface
{
    public const LIMIT = 30;
    public const COVER_ALBUMS = 15;

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly MediaRepository $media,
        private readonly MediaService $mediaService,
        private readonly GalleryLocationService $locations,
        private readonly StorageBackendFactory $backends,
        /**
         * The registry of delegated-album checkers, built on first use: the
         * modules that contribute a checker (groups, camps) are composed
         * after the gallery, so a registry built with this service would
         * miss them. The composition root hands a closure over the list it
         * appends to.
         *
         * @var \Closure(): DelegatedAlbumAccessRegistry
         */
        private readonly \Closure $delegatedAccess
    ) {
    }

    public function pickablePhotos(string $role, array $linkedMemberIds): array
    {
        $albums = $this->visibleAlbums(Role::fromString($role), $linkedMemberIds);

        $picked = [];
        foreach (array_slice($albums, 0, self::COVER_ALBUMS) as $album) {
            $cover = $album->coverMediaId !== null ? $this->media->findById($album->coverMediaId) : null;
            if ($cover !== null && $cover->albumId === $album->id && self::isPickable($cover)) {
                $picked[$cover->id] = [$cover, $album, true];
            }
        }

        $byId = [];
        foreach ($albums as $album) {
            $byId[$album->id] = $album;
        }
        foreach ($this->media->findRecentPhotos(
            array_keys($byId),
            array_keys($picked),
            self::LIMIT - count($picked)
        ) as $photo) {
            $picked[$photo->id] = [$photo, $byId[$photo->albumId], false];
        }

        usort(
            $picked,
            static fn (array $a, array $b): int => [$b[0]->createdAt, $b[0]->id] <=> [$a[0]->createdAt, $a[0]->id]
        );

        $photos = [];
        foreach ($picked as [$photo, $album, $isCover]) {
            $photos[] = new PickablePhoto(
                $photo->id,
                $album->displayTitle(),
                $this->mediaService->resolveUrl($photo, $album, 'thumb')
                    ?? '/gallery/media/' . $photo->id . '/thumb',
                $isCover
            );
        }

        return $photos;
    }

    public function photoContents(int $mediaId, string $role, array $linkedMemberIds): ?string
    {
        $photo = $this->media->findById($mediaId);
        if ($photo === null || !self::isPickable($photo)) {
            return null;
        }
        $album = $this->albums->findById($photo->albumId);
        if ($album === null || !$album->isLocal() || $album->isMigrating()
            || !$this->mayView($album, Role::fromString($role), $linkedMemberIds)) {
            return null;
        }

        $path = $photo->largePath ?? $photo->mediumPath ?? $photo->originalPath;
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

    /**
     * @param array<int, int> $linkedMemberIds
     * @return list<Album>
     */
    private function visibleAlbums(Role $role, array $linkedMemberIds): array
    {
        return array_values(array_filter(
            $this->albums->findPickable(),
            fn (Album $album): bool => $this->mayView($album, $role, $linkedMemberIds)
        ));
    }

    /**
     * @param array<int, int> $linkedMemberIds
     */
    private function mayView(Album $album, Role $role, array $linkedMemberIds): bool
    {
        if ($album->isDelegated()) {
            return ($this->delegatedAccess)()->isAllowed(
                (string) $album->ownerType,
                (int) $album->ownerId,
                $role,
                $linkedMemberIds
            );
        }

        // The gallery's own rule for a chief: every ordinary album.
        return $role->hasAccess(Role::CHIEF);
    }

    private static function isPickable(Media $media): bool
    {
        return $media->mediaType === Media::TYPE_PHOTO && $media->processingStatus === Media::STATUS_DONE;
    }
}
