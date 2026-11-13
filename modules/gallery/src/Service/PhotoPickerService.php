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
        $viewer = Role::fromString($role);
        $albums = $this->visibleAlbums($viewer, $linkedMemberIds);

        // Every photo looked at, offered or withheld: a photo its album's
        // owner withholds (issue #1119) is asked once, and never takes the
        // place of one the caller may see.
        $seen = [];
        $picked = [];
        foreach (array_slice($albums, 0, self::COVER_ALBUMS) as $album) {
            $cover = $album->coverMediaId !== null ? $this->media->findById($album->coverMediaId) : null;
            if ($cover === null || $cover->albumId !== $album->id || !self::isPickable($cover)) {
                continue;
            }
            $seen[] = $cover->id;
            if ($this->mayViewMedia($album, $cover, $viewer, $linkedMemberIds)) {
                $picked[$cover->id] = [$cover, $album, true];
            }
        }

        $byId = [];
        foreach ($albums as $album) {
            $byId[$album->id] = $album;
        }
        // Asked again while the selection is short: the query's LIMIT
        // counts withheld photos too, so a single pass could leave the
        // picker short — or empty — with older photos it may offer behind
        // them. Each pass excludes everything already looked at, so it
        // ends when the selection is full or nothing is left to look at.
        while (count($picked) < self::LIMIT) {
            $batch = $this->media->findRecentPhotos(array_keys($byId), $seen, self::LIMIT - count($picked));
            if ($batch === []) {
                break;
            }
            foreach ($batch as $photo) {
                $seen[] = $photo->id;
                $album = $byId[$photo->albumId];
                if ($this->mayViewMedia($album, $photo, $viewer, $linkedMemberIds)) {
                    $picked[$photo->id] = [$photo, $album, false];
                }
            }
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
            || !$this->mayViewMedia($album, $photo, Role::fromString($role), $linkedMemberIds)) {
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

    /**
     * mayView() for one photo: a delegated album's owner can withhold a
     * single media of an album the reader may otherwise open — a group,
     * the images of a post moderation hid (issue #1119). No login is
     * passed, so a rule keyed on one — a group moderator, who moderates
     * through the login the flag was granted to — does not let this
     * caller through; a rule decided by role alone still does: a site
     * administrator, who moderates every group, is offered such an image
     * like every other one.
     *
     * @param array<int, int> $linkedMemberIds
     */
    private function mayViewMedia(Album $album, Media $photo, Role $role, array $linkedMemberIds): bool
    {
        if (!$album->isDelegated()) {
            return $this->mayView($album, $role, $linkedMemberIds);
        }

        return ($this->delegatedAccess)()->isMediaAllowed(
            (string) $album->ownerType,
            (int) $album->ownerId,
            $photo->id,
            $role,
            $linkedMemberIds,
            null
        );
    }

    private static function isPickable(Media $media): bool
    {
        return $media->mediaType === Media::TYPE_PHOTO && $media->processingStatus === Media::STATUS_DONE;
    }
}
