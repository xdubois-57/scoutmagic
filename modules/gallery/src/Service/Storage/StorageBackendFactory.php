<?php

declare(strict_types=1);

namespace Modules\Gallery\Service\Storage;

use Core\Config\SettingService;
use Modules\Gallery\Repository\S3SecretRepository;

/**
 * Builds the configured StorageBackendInterface on demand (never at the
 * composition root — the backend can change any time an admin edits the
 * config page, and constructing an S3Client is pointless work on every
 * request when the backend is local).
 */
class StorageBackendFactory
{
    public function __construct(
        private SettingService $settingService,
        private S3SecretRepository $s3SecretRepository,
        private string $storagePath
    ) {
    }

    public function create(): StorageBackendInterface
    {
        $backend = (string) $this->settingService->get('gallery_storage_backend', 'gallery', 'local');

        if ($backend !== 's3') {
            $subdir = (string) $this->settingService->get('gallery_storage_local_subdir', 'gallery', 'gallery');
            return new LocalStorageBackend($this->storagePath, $subdir !== '' ? $subdir : 'gallery');
        }

        $publicUrl = (string) $this->settingService->get('gallery_s3_public_url', 'gallery', '');

        return new S3StorageBackend(
            (string) $this->settingService->get('gallery_s3_endpoint', 'gallery', ''),
            (string) $this->settingService->get('gallery_s3_region', 'gallery', ''),
            (string) $this->settingService->get('gallery_s3_bucket', 'gallery', ''),
            (string) $this->settingService->get('gallery_s3_access_key', 'gallery', ''),
            $this->s3SecretRepository->get() ?? '',
            $publicUrl !== '' ? $publicUrl : null
        );
    }

    public function isS3(): bool
    {
        return (string) $this->settingService->get('gallery_storage_backend', 'gallery', 'local') === 's3';
    }
}
