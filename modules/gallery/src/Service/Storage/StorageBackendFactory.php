<?php

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

    public function create(StorageLocation $location): StorageBackendInterface
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
