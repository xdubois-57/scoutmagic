<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Module\SubProcessorView;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
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

    private static function share(string $baseUrl): StorageLocation
    {
        return new StorageLocation(
            1,
            StorageLocationType::WebDav,
            'Un partage',
            false,
            new WebDavLocationConfig($baseUrl, 'unite'),
            true,
            null,
            null,
            null,
            '2026-01-01 00:00:00'
        );
    }

    private static function drive(): StorageLocation
    {
        return new StorageLocation(
            1,
            StorageLocationType::GoogleDrive,
            'Un Drive',
            false,
            new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-01-01T00:00:00+00:00'),
            true,
            null,
            null,
            null,
            '2026-01-01 00:00:00'
        );
    }

    /**
     * **What flows to these hosts is photographs and films of children**,
     * so a destination missing from this page is a disclosure that is
     * wrong rather than merely short. This used to read one `instanceof`
     * for S3 and skip everything else, which was right while S3 was the
     * only kind there was.
     */
    public function testAShareIsDeclaredByItsHostAndNotByTheAccountUnderIt(): void
    {
        $views = $this->service([
            self::share('https://cloud.exemple.test/remote.php/dav/files/marie.dupont/scoutmagic'),
        ])->getSubProcessors();

        $this->assertCount(1, $views);
        $this->assertSame(SubProcessorView::CATEGORY_MEDIA_STORAGE, $views[0]->category);
        $this->assertStringContainsString('cloud.exemple.test', $views[0]->name);
        $this->assertStringNotContainsString('marie.dupont', $views[0]->name);
    }

    public function testADriveFolderIsDeclaredToo(): void
    {
        $views = $this->service([self::drive()])->getSubProcessors();

        $this->assertCount(1, $views);
        $this->assertStringContainsString('Google', $views[0]->name);
    }

    /**
     * **The one that catches the NEXT omission, and it has to derive its
     * own fixtures to do that.** A hand-written list of locations only
     * catches a type somebody remembered to add to it, which is the same
     * forgetting this test exists to prevent. So it walks
     * `StorageLocationType::cases()` and builds each config through the
     * enum's own `configFromArray()`: a case added there arrives here on
     * its own, and fails if nothing names it.
     *
     * PHP offers no exhaustiveness over a set of classes — a `match`
     * without a default arm would raise on the « Sous-traitants » page
     * itself, trading a silent omission for a broken page — so this is
     * the guarantee.
     */
    public function testEveryKindOfExternalDestinationIsDeclared(): void
    {
        foreach (StorageLocationType::cases() as $type) {
            $views = $this->service([self::locationOfType($type)])->getSubProcessors();

            if ($type === StorageLocationType::Local) {
                $this->assertSame([], $views, 'the unit\'s own server is no sub-processor');
                continue;
            }

            $this->assertCount(
                1,
                $views,
                sprintf(
                    'the « %s » type sends photographs to somebody and is not named on the RGPD page',
                    $type->value
                )
            );
            $this->assertNotSame('', $views[0]->name);
        }
    }

    private static function locationOfType(StorageLocationType $type): StorageLocation
    {
        return new StorageLocation(
            1,
            $type,
            'Un emplacement',
            false,
            // The enum's own reader, so a type added to it needs nothing
            // written here for this test to reach it.
            $type->configFromArray([]),
            false,
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
