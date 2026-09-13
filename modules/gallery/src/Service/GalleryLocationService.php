<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\Storage\Location\Config\LocalLocationConfig;
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
    public function __construct(
        private StorageLocationService $locations,
        private AlbumRepository $albumRepository,
        private SettingService $settingService,
        private string $storagePath
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
     * How much room is left for a LOCAL location, for the gallery's own
     * configuration page — null for any other kind (its capacity is the
     * provider's business) and null whenever the host will not answer at
     * all (`open_basedir`, a disabled `disk_free_space()`).
     *
     * Read at render time rather than cached alongside the health check:
     * free space is the one property of a location that is stale the
     * moment it is written down, and it costs one `statvfs` on a page a
     * superadmin opens by hand — the gallery's read path never calls this.
     *
     * **This is a stopgap and it is the wrong shape**, deliberately kept
     * for one iteration so the page it feeds keeps working: free space is
     * a property of a VOLUME, not of a folder, and three locations on one
     * disk each reporting « 3,2 To libres » would have an administrator
     * believe they had 9,6. The measurement that groups paths by
     * filesystem and the screen that states it live in the next iteration
     * (docs/chantiers/emplacements-de-stockage.md, IT-02); this method
     * goes with them.
     */
    public function diskSpaceFor(StorageLocation $location): ?DiskSpace
    {
        $config = $location->config;
        if (!$config instanceof LocalLocationConfig) {
            return null;
        }

        $dir = $this->measurableDirectory($config);
        if ($dir === null) {
            return null;
        }

        $free = @disk_free_space($dir);
        if (!is_float($free) || $free < 0) {
            return null;
        }

        $total = @disk_total_space($dir);

        return new DiskSpace(
            (int) $free,
            is_float($total) && $total > 0 ? (int) $total : 0,
            $this->largestAllowedUploadBytes()
        );
    }

    /**
     * The directory to measure: the location's own, or the storage root
     * when that directory does not exist yet.
     *
     * A location created a minute ago has no directory until the first
     * upload or the first health check — and for a relative path the
     * answer would be the same anyway, since a subdirectory of `storage/`
     * is on the volume `storage/` is on. Reporting « inconnu » there would
     * hide the number on exactly the freshly-configured location whose
     * administrator is most likely to be asking.
     */
    private function measurableDirectory(LocalLocationConfig $config): ?string
    {
        $dir = $config->isAbsolute()
            ? rtrim($config->path, '/')
            : rtrim($this->storagePath, '/') . '/' . trim($config->path, '/');

        if (is_dir($dir)) {
            return $dir;
        }

        return is_dir($this->storagePath) ? $this->storagePath : null;
    }

    /**
     * The biggest single file the gallery would accept right now — the
     * threshold under which the page calls the remaining space low. Video
     * counts only while video uploads are actually enabled: warning about
     * a 2 Go limit on an installation that refuses every video would be
     * warning about something that cannot happen.
     */
    private function largestAllowedUploadBytes(): int
    {
        $photoMb = (int) $this->settingService->get('gallery_max_photo_upload_mb', 'gallery', 30);
        $videoMb = (bool) $this->settingService->get('gallery_allow_video', 'gallery', true)
            ? (int) $this->settingService->get('gallery_max_video_upload_mb', 'gallery', 2048)
            : 0;

        return max(0, max($photoMb, $videoMb)) * 1024 * 1024;
    }
}
