<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Module\SubProcessorView;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Service\GalleryStorageSubProcessorService;
use PHPUnit\Framework\TestCase;

/**
 * The object storage the gallery writes to, as declared sub-processors (Core\Module\
 * SubProcessorProvider, chantier IT-05). Dynamic: local storage keeps
 * every byte on the unit's own server and declares NOTHING; the wording
 * per provider is the exact wording the RGPD prompt carried when core
 * read this table itself.
 */
class GalleryStorageSubProcessorServiceTest extends TestCase
{
    /**
     * @param list<StorageLocation> $locations
     */
    private function service(array $locations): GalleryStorageSubProcessorService
    {
        $repository = $this->createMock(StorageLocationRepository::class);
        $repository->method('findAll')->willReturn($locations);

        return new GalleryStorageSubProcessorService($repository);
    }

    private static function localLocation(): StorageLocation
    {
        return new StorageLocation(
            1,
            StorageLocationType::Local,
            'Un emplacement',
            false,
            new LocalLocationConfig('modules/gallery'),
            false,
            null,
            null,
            null,
            '2026-01-01 00:00:00'
        );
    }

    private static function bucket(?string $provider = null, string $region = ''): StorageLocation
    {
        return new StorageLocation(
            1,
            StorageLocationType::ObjectStorage,
            'Un emplacement',
            false,
            new ObjectStorageLocationConfig('https://s3.example.test', $region, 'b', 'ak', $provider),
            true,
            null,
            null,
            null,
            '2026-01-01 00:00:00'
        );
    }

    public function testLocalStorageDeclaresNoSubProcessor(): void
    {
        $this->assertSame([], $this->service([self::localLocation()])->getSubProcessors());
        $this->assertSame([], $this->service([])->getSubProcessors());
    }

    public function testEachS3ProviderIsWordedWithItsLocationExactlyAsTheRgpdPromptAlwaysWasIt(): void
    {
        $views = $this->service([
            self::localLocation(),
            self::bucket('hetzner'),
            self::bucket('cloudflare_r2', 'weur'),
        ])->getSubProcessors();

        $this->assertCount(2, $views);
        $this->assertSame(SubProcessorView::CATEGORY_MEDIA_STORAGE, $views[0]->category);
        $this->assertSame('Hetzner Object Storage (Allemagne/Finlande, UE)', $views[0]->name);
        $this->assertSame(
            'Cloudflare R2 (réseau mondial, région selon configuration du bucket : weur)',
            $views[1]->name
        );
    }

    public function testTwoLocationsOnTheSameProviderAreOneSubProcessor(): void
    {
        $views = $this->service([
            self::bucket('scaleway'),
            self::bucket('scaleway'),
        ])->getSubProcessors();

        $this->assertCount(1, $views);
        $this->assertSame('Scaleway Object Storage (France/Pays-Bas, UE)', $views[0]->name);
    }

    public function testAnUnknownS3ProviderIsDeclaredAsCustomWithUnstatedLocation(): void
    {
        $views = $this->service([self::bucket('minio-maison')])->getSubProcessors();

        $this->assertSame('Fournisseur S3-compatible personnalisé (localisation selon configuration)', $views[0]->name);
    }
}
