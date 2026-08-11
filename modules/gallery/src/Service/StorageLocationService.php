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

        if ($legacyBackend === 's3') {
            $publicUrl = (string) $this->settingService->get('gallery_s3_public_url', 'gallery', '');
            $provider = (string) $this->settingService->get('gallery_s3_provider', 'gallery', '');
            $locationId = $this->storageLocationRepository->create(
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
        } else {
            $subdir = (string) $this->settingService->get('gallery_storage_local_subdir', 'gallery', 'gallery');
            $locationId = $this->storageLocationRepository->create(
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

        foreach ($this->albumRepository->findAll() as $album) {
            if ($album->isLocal() && $album->storageLocationId === null) {
                $this->albumRepository->setStorageLocationId($album->id, $locationId);
            }
        }
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
            return $this->storageLocationRepository->findById($album->storageLocationId);
        }

        $this->ensureLegacyLocationBackfilled();
        $refreshed = $this->albumRepository->findById($album->id);
        return $refreshed?->storageLocationId !== null
            ? $this->storageLocationRepository->findById($refreshed->storageLocationId)
            : null;
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
                $this->storageLocationRepository->recordCheckResult($location->id, false, "Le dossier de stockage local ({$dir}) n'existe pas et n'a pas pu être créé.");
                return;
            }
            if (!is_writable($dir)) {
                $this->storageLocationRepository->recordCheckResult($location->id, false, "Le dossier de stockage local ({$dir}) n'est pas accessible en écriture.");
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

    private function localStoragePath(string $subdir): string
    {
        return $this->storagePath . '/' . $subdir;
    }
}
