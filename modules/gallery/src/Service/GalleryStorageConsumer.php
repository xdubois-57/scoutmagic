<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Storage\Location\StorageLocationConsumer;
use Core\Storage\Location\StorageLocationRepository;
use Modules\Gallery\Repository\AlbumRepository;

/**
 * The gallery answering « am I still standing on this location? ».
 *
 * Most albums that hold files — ordinary and delegated alike — pin the
 * location their media are in, so most of the answer is simply the set of
 * those identifiers. A delegated album counts exactly like any other:
 * nobody sees it in the gallery's own listings, but its photos occupy real
 * space on a real destination, and a location deleted out from under one
 * loses them just as thoroughly.
 *
 * **The interesting case is the album that pins nothing.** A null
 * `location_id` is not « this album uses no storage »; it is « nobody has
 * written down which one yet », and Service\GalleryLocationService
 * resolves it to the site's DEFAULT the next time the album is touched.
 * Counting only the non-null values therefore reported the default as
 * unused while every such album was standing on it — and a default
 * reported unused is a default the storage page offers to delete. The
 * administrator gets a clean deletion, no constraint violation and no
 * warning; the albums then resolve onto whatever default replaced it, and
 * their files are not there.
 */
class GalleryStorageConsumer implements StorageLocationConsumer
{
    public function __construct(
        private AlbumRepository $albumRepository,
        private StorageLocationRepository $locations
    ) {
    }

    public function usageLabel(): string
    {
        return 'Galeries photo';
    }

    /**
     * @return list<int>
     */
    public function locationIdsInUse(): array
    {
        $ids = $this->albumRepository->distinctLocationIds();

        if ($this->albumRepository->hasAlbumsWithoutLocation()) {
            $default = $this->locations->findDefault();
            if ($default !== null && !in_array($default->id, $ids, true)) {
                $ids[] = $default->id;
            }
        }

        return $ids;
    }
}
