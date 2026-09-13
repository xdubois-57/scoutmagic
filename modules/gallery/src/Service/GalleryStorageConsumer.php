<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Storage\Location\StorageLocationConsumer;
use Modules\Gallery\Repository\AlbumRepository;

/**
 * The gallery answering « am I still standing on this location? ».
 *
 * Every album that holds files — ordinary and delegated alike — pins the
 * location its media are in, so the answer is simply the set of those
 * identifiers. A delegated album counts exactly like any other: nobody
 * sees it in the gallery's own listings, but its photos occupy real space
 * on a real destination, and a location deleted out from under one loses
 * them just as thoroughly.
 */
class GalleryStorageConsumer implements StorageLocationConsumer
{
    public function __construct(
        private AlbumRepository $albumRepository
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
        return $this->albumRepository->distinctLocationIds();
    }
}
