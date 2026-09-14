<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\GalleryLocationService;
use PHPUnit\Framework\TestCase;

/**
 * The free-space figure the gallery's configuration page prints, and the
 * one path that used to lose it.
 *
 * `rtrim($path, '/')` turns the filesystem root into the empty string, and
 * `is_dir('')` is false — so a location configured at `/`, or a
 * `storagePath` of `/` on a container that mounts the site there, fell
 * through to « inconnu » instead of reporting the root volume.
 */
class GalleryLocationSpaceTest extends TestCase
{
    private function serviceRootedAt(string $storagePath): GalleryLocationService
    {
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, string $module, mixed $default = null): mixed => $default
        );

        return new GalleryLocationService(
            $this->createStub(StorageLocationService::class),
            $this->createStub(AlbumRepository::class),
            $settings,
            $storagePath
        );
    }

    private static function localLocation(string $path): StorageLocation
    {
        return new StorageLocation(
            id: 1,
            type: StorageLocationType::Local,
            label: 'Racine',
            config: new LocalLocationConfig($path),
            isDefault: true,
            secretConfigured: false,
            lastCheckedAt: null,
            lastCheckOk: null,
            lastCheckError: null,
            createdAt: '2026-01-01 00:00:00'
        );
    }

    public function testAnAbsoluteLocationAtTheFilesystemRootIsStillMeasured(): void
    {
        // The storage root is deliberately absent here, so the fallback
        // cannot rescue the answer: what is measured is the location's own
        // path, `/`, and `rtrim()` alone turns that into the empty string.
        $space = $this->serviceRootedAt('/un-dossier-qui-n-existe-pas')
            ->diskSpaceFor(self::localLocation('/'));

        $this->assertNotNull($space);
        $this->assertGreaterThan(0, $space->totalBytes);
    }

    public function testADirectoryThatDoesNotExistYetFallsBackToTheStorageRoot(): void
    {
        // A location created a minute ago has no directory until the first
        // upload — and a relative one is on the volume `storage/` is on
        // anyway, so « inconnu » would hide the figure from exactly the
        // administrator who just configured it.
        $space = $this->serviceRootedAt('/tmp')->diskSpaceFor(self::localLocation('pas-encore-cree'));

        $this->assertNotNull($space);
        $this->assertGreaterThan(0, $space->totalBytes);
    }

    public function testNothingIsMeasuredWhenNeitherThePathNorTheStorageRootExists(): void
    {
        $space = $this->serviceRootedAt('/un-dossier-qui-n-existe-pas')
            ->diskSpaceFor(self::localLocation('/un-autre-dossier-absent'));

        $this->assertNull($space);
    }

    /**
     * Capacity is the provider's business, not ours: measuring a bucket
     * with `statvfs` would report the web server's own disk.
     */
    public function testABucketReportsNoLocalFreeSpaceAtAll(): void
    {
        $location = new StorageLocation(
            id: 2,
            type: StorageLocationType::ObjectStorage,
            label: 'Bucket',
            config: new ObjectStorageLocationConfig('https://s3.example.org', 'eu-west-1', 'b', 'k', null),
            isDefault: false,
            secretConfigured: true,
            lastCheckedAt: null,
            lastCheckOk: null,
            lastCheckError: null,
            createdAt: '2026-01-01 00:00:00'
        );

        $this->assertNull($this->serviceRootedAt('/tmp')->diskSpaceFor($location));
    }
}
