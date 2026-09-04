<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\S3StorageBackend;
use Modules\Gallery\Service\Storage\StorageBackendFactory;

class StorageLocationService
{
    /**
     * How long a cached health-check result is trusted before a page view
     * that needs it triggers a fresh, synchronous check — keeps normal
     * gallery browsing from ever hitting S3/the filesystem, while still
     * self-healing without needing a scheduled task.
     */
    private const HEALTH_CHECK_TTL_SECONDS = 900;

    public function __construct(
        private StorageLocationRepository $storageLocationRepository,
        private AlbumRepository $albumRepository,
        private StorageBackendFactory $storageBackendFactory,
        private SettingService $settingService,
        private S3SecretRepository $legacyS3SecretRepository,
        private string $storagePath
    ) {
    }

    /**
     * Self-healing, idempotent "ensure at least one location exists"
     * bootstrap — ModuleManager has no generic post-migration-hook
     * mechanism (only the schema.sql diff itself runs on a version bump),
     * so this is called on demand instead (Controller\GalleryConfigController
     * and Controller\GalleryStorageLocationController::index(), and
     * defensively from resolveLocationForAlbum() below). On an upgrade from
     * the single-location config it carries over the previous settings/
     * secret into one new row and backfills every pre-existing local album
     * onto it; on a fresh install it just creates a sensible local default.
     * Only runs once — a no-op as soon as any location row exists.
     */
    public function ensureLegacyLocationBackfilled(): void
    {
        if ($this->storageLocationRepository->findAll() !== []) {
            return;
        }

        $legacyBackend = (string) $this->settingService->get('gallery_storage_backend', 'gallery', 'local');

        try {
            $locationId = $this->createBackfillLocation($legacyBackend);
        } catch (\PDOException) {
            // Two concurrent first-ever requests both saw an empty table; the
            // UNIQUE index on label rejected the loser's insert. Adopt
            // whatever the winner created rather than failing the request.
            $existing = $this->storageLocationRepository->findDefault();
            if ($existing === null) {
                return;
            }
            $locationId = $existing->id;
        }

        foreach ($this->albumRepository->findAll() as $album) {
            if ($album->isLocal() && $album->storageLocationId === null) {
                $this->albumRepository->setStorageLocationId($album->id, $locationId);
            }
        }
    }

    /**
     * The one row ensureLegacyLocationBackfilled() creates: either carried
     * over from the retired single-location settings, or a plain local
     * default on a fresh install.
     */
    private function createBackfillLocation(string $legacyBackend): int
    {
        if ($legacyBackend === 's3') {
            $publicUrl = (string) $this->settingService->get('gallery_s3_public_url', 'gallery', '');
            $provider = (string) $this->settingService->get('gallery_s3_provider', 'gallery', '');
            return $this->storageLocationRepository->create(
                StorageLocation::TYPE_S3,
                'Configuration existante',
                null,
                $provider !== '' ? $provider : null,
                (string) $this->settingService->get('gallery_s3_endpoint', 'gallery', ''),
                (string) $this->settingService->get('gallery_s3_region', 'gallery', ''),
                (string) $this->settingService->get('gallery_s3_bucket', 'gallery', ''),
                (string) $this->settingService->get('gallery_s3_access_key', 'gallery', ''),
                $publicUrl !== '' ? $publicUrl : null,
                $this->legacyS3SecretRepository->get()
            );
        }

        $subdir = (string) $this->settingService->get('gallery_storage_local_subdir', 'gallery', 'gallery');

        return $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL,
            'Stockage local',
            $subdir !== '' ? $subdir : 'gallery',
            null,
            null,
            null,
            null,
            null,
            null,
            null
        );
    }

    /**
     * Resolves the StorageLocation a local album's files live in, self-
     * healing a still-null storage_location_id (an album created after
     * multi-location support was added should always have one already —
     * this only matters for the upgrade window right before the backfill
     * above has had a chance to run once).
     */
    public function resolveLocationForAlbum(Album $album): ?StorageLocation
    {
        if ($album->storageLocationId !== null) {
            return $this->findByIdCached($album->storageLocationId);
        }

        $this->ensureLegacyLocationBackfilled();
        $refreshed = $this->albumRepository->findById($album->id);
        return $refreshed?->storageLocationId !== null
            ? $this->findByIdCached($refreshed->storageLocationId)
            : null;
    }

    /**
     * Locations already read, for the lifetime of this instance — an
     * album view resolves the same location once per media per size
     * (Service\MediaService::resolveUrl()), which was one query each for
     * a row that cannot change mid-request (the configuration page saves
     * and redirects).
     *
     * @var array<int, StorageLocation|null>
     */
    private array $locationsById = [];

    private function findByIdCached(int $locationId): ?StorageLocation
    {
        if (!array_key_exists($locationId, $this->locationsById)) {
            $this->locationsById[$locationId] = $this->storageLocationRepository->findById($locationId);
        }

        return $this->locationsById[$locationId];
    }

    /**
     * Runs an actual connectivity check (filesystem stat for local,
     * S3StorageBackend::testConnection() for s3) and persists the result —
     * only called from checkFresh() below (TTL-gated) or an admin's
     * explicit "Tester" click, never on a plain page view.
     */
    public function checkNow(StorageLocation $location): void
    {
        if (!$location->isS3()) {
            $subdir = $location->subdir !== null && $location->subdir !== '' ? $location->subdir : 'gallery';
            $dir = $this->localStoragePath($subdir);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $this->storageLocationRepository->recordCheckResult($location->id, false,
                    "Le dossier de stockage local ({$dir}) n'existe pas et n'a pas pu être créé.");
                return;
            }
            if (!is_writable($dir)) {
                $this->storageLocationRepository->recordCheckResult($location->id, false,
                    "Le dossier de stockage local ({$dir}) n'est pas accessible en écriture.");
                return;
            }
            $this->storageLocationRepository->recordCheckResult($location->id, true, null);
            return;
        }

        $backend = $this->storageBackendFactory->create($location);
        $error = $backend instanceof S3StorageBackend ? $backend->testConnection() : null;
        $this->storageLocationRepository->recordCheckResult($location->id, $error === null, $error);
    }

    /**
     * Refreshes the cached health-check result only when it's missing or
     * stale (HEALTH_CHECK_TTL_SECONDS) — the read path galleries actually
     * render through, so it must stay cheap on the common case (a recent,
     * cached "ok").
     */
    public function checkFresh(StorageLocation $location): StorageLocation
    {
        $isStale = $location->lastCheckedAt === null
            || (time() - strtotime($location->lastCheckedAt)) > self::HEALTH_CHECK_TTL_SECONDS;

        if (!$isStale) {
            return $location;
        }

        $this->checkNow($location);
        return $this->storageLocationRepository->findById($location->id) ?? $location;
    }

    /**
     * How much room is left for a local location, for the Galerie
     * configuration page — null for an S3 location (its capacity is the
     * provider's business, not readable from here) and null whenever the
     * host will not answer at all (`open_basedir`, a disabled
     * `disk_free_space()`).
     *
     * Read at render time rather than cached alongside the health check:
     * free space is the one property of a location that is stale the moment
     * it is written down, and it costs one `statvfs` on a page a superadmin
     * opens by hand — the gallery's own read path never calls this.
     */
    public function diskSpaceFor(StorageLocation $location): ?DiskSpace
    {
        if ($location->isS3()) {
            return null;
        }

        $dir = $this->measurableDirFor($location);
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
     * upload or the first health check — and the answer would be the same
     * anyway, since a subdirectory of `storage/` is on the volume
     * `storage/` is on. Reporting "unknown" there would hide the number on
     * exactly the freshly-configured location whose administrator is most
     * likely to be asking.
     */
    private function measurableDirFor(StorageLocation $location): ?string
    {
        $subdir = $location->subdir !== null && $location->subdir !== '' ? $location->subdir : 'gallery';
        $dir = $this->localStoragePath($subdir);
        if (is_dir($dir)) {
            return $dir;
        }

        return is_dir($this->storagePath) ? $this->storagePath : null;
    }

    /**
     * The biggest single file the gallery would accept right now — the
     * threshold under which the page calls the remaining space low. Video
     * counts only while video uploads are actually enabled: warning about a
     * 2 Go limit on an installation that refuses every video would be
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

    private function localStoragePath(string $subdir): string
    {
        return $this->storagePath . '/' . $subdir;
    }
}
