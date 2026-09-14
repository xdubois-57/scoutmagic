<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\StorageLocation;
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

    /**
     * The gallery's one objection: a delegated album may not end up on a
     * location that serves publicly, for ever, to whoever holds a URL.
     *
     * A delegated album is owned and access-controlled by another module —
     * a discussion group's photos — and « private album » and « readable
     * by anyone with the link » cannot both be true of it. The refusal
     * already exists at creation (Service\DelegatedAlbumService) and again
     * when the bytes are served (Controller\GalleryController::
     * serveDelegatedMedia). This is the third moment, and the one nothing
     * covered: the album is created on a private location, and the
     * LOCATION is later edited to carry a public URL, or promoted to
     * default. Nothing is exposed — the serve-time guard holds — but every
     * media of every delegated album there becomes a permanent 404 with
     * nothing anywhere explaining it.
     *
     * $wouldBeDefault is not decoration. An album that pins no location is
     * not on « no » location: it is on the DEFAULT, and it is pinned there
     * the next time anything touches it. So promoting a publicly-serving
     * location is a second door into exactly the same breakage, and the
     * question has to be asked about the location that is ABOUT to be the
     * default rather than the one that is.
     */
    public function objectionTo(
        StorageLocation $location,
        LocationConfig $proposedConfig,
        bool $wouldBeDefault
    ): ?string {
        if (!$proposedConfig->servesPubliclyWithoutExpiry()) {
            return null;
        }
        if (!$this->albumRepository->hasDelegatedAlbumsOn($location->id, $wouldBeDefault)) {
            return null;
        }

        return 'Des albums délégués sont hébergés sur cet emplacement, et une URL publique les rendrait '
            . 'lisibles par toute personne connaissant le lien — le site refuserait alors de les servir. '
            . 'Déplacez-les vers un autre emplacement avant de configurer une URL publique ici.';
    }
}
