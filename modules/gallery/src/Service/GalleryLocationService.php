<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationService;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;

/**
 * The gallery's half of the storage question: WHICH declared location an
 * album's files are in.
 *
 * Everything generic — creating a location, checking it is reachable,
 * refusing to delete one something stands on — belongs to
 * `Core\Storage\Location\StorageLocationService` and is not repeated here.
 * What is left is the part that is genuinely about albums, and it is
 * small on purpose: an album is pinned to a location at creation and
 * stays there, so the only interesting case is an album that has none
 * yet.
 */
class GalleryLocationService
{
    /**
     * Which declared location new albums are created on — the gallery's
     * own choice, kept in the gallery's own setting.
     *
     * D4 in one constant: locations are declared centrally and CHOSEN
     * locally, with no join table in the middle. The storage page reads
     * this back through `Modules\Gallery\Service\GalleryStorageConsumer`
     * to say « Galeries photo → Nextcloud de l'unité », and never writes
     * it: the assignment belongs to whoever made it.
     */
    public const NEW_ALBUM_LOCATION_SETTING = 'gallery_new_album_location_id';

    public function __construct(
        private StorageLocationService $locations,
        private AlbumRepository $albumRepository,
        private SettingService $settingService
    ) {
    }

    /**
     * The location a local album's files live in, self-healing an album
     * that has none.
     *
     * An album created after this model existed always has one. A null
     * means either an album created in the window before the site had any
     * location at all, or one whose location was redeclared rather than
     * migrated — so the answer is the same in both cases: put it on the
     * default, which is where a site with no storage configuration writes
     * anyway, and record that on the row so the next read is a plain
     * lookup.
     *
     * Null comes back only for an external album (nothing is hosted) or on
     * an installation where even the default could not be created.
     */
    public function resolveLocationForAlbum(Album $album): ?StorageLocation
    {
        if ($album->locationId !== null) {
            return $this->locations->findById($album->locationId);
        }

        if (!$album->isLocal()) {
            return null;
        }

        $default = $this->locations->ensureDefaultExists();
        if ($default === null) {
            return null;
        }

        $this->albumRepository->setLocationId($album->id, $default->id);

        return $default;
    }

    /**
     * The location an album's files are ACTUALLY on, without writing
     * anything down.
     *
     * The read-only half of {@see resolveLocationForAlbum()}, and the
     * distinction is the point. Resolving pins the album to the default
     * and is right where something is about to touch its files; a screen
     * that merely LISTS albums must not write a row per line on a GET —
     * and must not show « Non défini » for an album that is plainly
     * sitting on the default either, which is what reading the raw column
     * gave.
     *
     * Null only for an album that hosts nothing (external), or on an
     * installation with no location at all.
     */
    public function effectiveLocationId(Album $album): ?int
    {
        if ($album->locationId !== null) {
            return $album->locationId;
        }

        if (!$album->isLocal()) {
            return null;
        }

        return $this->locations->findDefault()?->id;
    }

    /**
     * Where a NEW album's files go.
     *
     * **« Emplacement des nouveaux albums », and the label is the whole
     * point.** An album is pinned to a location when it is created and
     * stays there; changing this setting moves nothing and is not supposed
     * to. An administrator who reads it as « where the gallery lives » and
     * expects yesterday's albums to follow has been misled by the wording,
     * not by the code — which is why the screen says « nouveaux » and says
     * underneath that existing albums do not move.
     *
     * Falls back on the site's default location in the two cases where the
     * setting cannot be honoured: nothing chosen, and a location that has
     * since been deleted. Both are « we do not know where you wanted it »,
     * and the default is where a site with no storage configuration at all
     * writes — so the fallback is the behaviour that existed before this
     * setting did, rather than a refusal to create an album.
     */
    public function locationForNewAlbums(): ?StorageLocation
    {
        $configured = (int) $this->settingService->get(self::NEW_ALBUM_LOCATION_SETTING, 'gallery', 0);

        if ($configured > 0) {
            $chosen = $this->locations->findById($configured);
            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $this->locations->ensureDefaultExists();
    }

    /**
     * Records the administrator's choice of « emplacement des nouveaux
     * albums », and answers what changed.
     *
     * **Here rather than in `GalleryConfigController`**, and that is the
     * mandatory Controller → Service boundary rather than a preference.
     * Four steps make this one decision — read what was there, check the
     * identifier still names a location, write it, work out whether it
     * moved — and a controller that owns them owns the rule: the next
     * caller (a future API route, an import) would have to reproduce all
     * four to behave the same way, and the one that forgets the third
     * writes an identifier naming nothing.
     *
     * `0` is « the site's default » and is always accepted — it is what
     * the setting holds on a site that never chose, and what
     * {@see locationForNewAlbums()} falls back on.
     *
     * The journal entry stays with the caller: this service has no idea
     * who is signed in, and « qui a décidé » is exactly what that entry
     * is for.
     *
     * @throws GalleryLocationException when the identifier names no
     *         location — deleted between the page being rendered and the
     *         form being sent, which is a sentence for the administrator
     *         rather than a silent fallback onto the default.
     */
    public function chooseForNewAlbums(int $locationId): NewAlbumLocationChoice
    {
        $previousId = (int) $this->settingService->get(self::NEW_ALBUM_LOCATION_SETTING, 'gallery', 0);

        $selected = null;
        if ($locationId > 0) {
            $selected = $this->locations->findById($locationId);
            if ($selected === null) {
                throw new GalleryLocationException(
                    "L'emplacement choisi pour les nouveaux albums n'existe plus. Rechargez la page et "
                        . 'choisissez-en un autre.'
                );
            }
        }

        $this->settingService->set(self::NEW_ALBUM_LOCATION_SETTING, (string) max(0, $locationId), 'gallery');

        return new NewAlbumLocationChoice($previousId, max(0, $locationId), $selected);
    }
}
