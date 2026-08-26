<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service\Storage;

use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;

/**
 * Builds the StorageBackendInterface for a given StorageLocation on demand
 * (never at the composition root — constructing an S3Client is pointless
 * work on every request when it isn't going to be used). Several locations
 * can be configured at once (Repository\StorageLocationRepository::findAll())
 * so callers always resolve the specific location an album/media row is
 * pinned to — there is no single "the" active backend anymore.
 */
class StorageBackendFactory
{
    public function __construct(
        private StorageLocationRepository $storageLocationRepository,
        private string $storagePath
    ) {
    }

    /**
     * Backends already built, per location, for the lifetime of this
     * instance. An S3 backend is the most expensive object in this
     * module's dependency tree (secret decryption plus a full S3Client
     * handler stack), and an album view used to construct one per media
     * per size — 1600 clients for an 800-photo album. A location's
     * configuration cannot change mid-request (the configuration page
     * saves and redirects), so the first backend serves the whole view.
     *
     * @var array<int, StorageBackendInterface>
     */
    private array $backendsByLocationId = [];

    public function create(StorageLocation $location): StorageBackendInterface
    {
        return $this->backendsByLocationId[$location->id] ??= $this->build($location);
    }

    private function build(StorageLocation $location): StorageBackendInterface
    {
        if (!$location->isS3()) {
            $subdir = $location->subdir !== null && $location->subdir !== '' ? $location->subdir : 'gallery';
            return new LocalStorageBackend($this->storagePath, $subdir);
        }

        return new S3StorageBackend(
            $location->s3Endpoint ?? '',
            $location->s3Region ?? '',
            $location->s3Bucket ?? '',
            $location->s3AccessKey ?? '',
            $this->storageLocationRepository->getSecret($location->id) ?? '',
            $location->s3PublicUrl !== null && $location->s3PublicUrl !== '' ? $location->s3PublicUrl : null
        );
    }
}
