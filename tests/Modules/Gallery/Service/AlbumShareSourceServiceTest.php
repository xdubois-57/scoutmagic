<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\File\StoredFileReader;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\AlbumShareSourceService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * What the gallery lets another module share of an album: nothing unless
 * the caller manages it, and the cover's bytes read the gallery's own way.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AlbumShareSourceServiceTest extends TestCase
{
    private AlbumRepository $albums;
    private MediaRepository $media;
    private bool $manages = true;
    /** @var array<string, string> */
    private array $stored = [];
    private int $scoutYearId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($pdo);
        $this->albums = new AlbumRepository($pdo);
        $this->media = new MediaRepository($pdo);
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $pdo->lastInsertId();
    }

    public function testALocalAlbumIsDescribedWithTheLargeRenditionOfItsCover(): void
    {
        $id = $this->localAlbum();
        $cover = $this->photo($id, 'large.jpg');
        $this->albums->setCoverMediaId($id, $cover);
        $this->stored = ['large.jpg' => 'LARGE-BYTES'];

        $album = $this->service()->describe($id, 'chief', 'chef@unite.be');

        $this->assertNotNull($album);
        $this->assertSame('Camp', $album->title);
        $this->assertSame('LARGE-BYTES', $album->coverContents);
        $this->assertSame('/gallery/' . $id, $album->path);
    }

    public function testAnAlbumWithoutCoverIsDescribedWithoutImage(): void
    {
        $album = $this->service()->describe($this->localAlbum(), 'chief', 'chef@unite.be');

        $this->assertNotNull($album);
        $this->assertNull($album->coverContents);
    }

    public function testAnUnreadableCoverIsNoImageRatherThanAnError(): void
    {
        $id = $this->localAlbum();
        $this->albums->setCoverMediaId($id, $this->photo($id, 'gone.jpg'));

        $this->assertNull($this->service()->describe($id, 'chief', 'chef@unite.be')?->coverContents);
    }

    public function testAnExternalAlbumUsesItsPreviewImage(): void
    {
        $id = $this->albums->create(
            Album::TYPE_EXTERNAL, 'Album externe', null, '2026-01-01', null, $this->scoutYearId,
            'https://photos.example.org/album', null, 1
        );
        $this->albums->updateOgMetadata($id, 'T', null, 'https://photos.example.org/og.jpg', 55);

        $this->assertSame('OG-BYTES', $this->service()->describe($id, 'chief', 'chef@unite.be')?->coverContents);
    }

    public function testAnAlbumTheCallerDoesNotManageIsNotThere(): void
    {
        $this->manages = false;

        $this->assertNull($this->service()->describe($this->localAlbum(), 'chief', 'autre@unite.be'));
    }

    public function testADelegatedOrUnknownAlbumIsNotThere(): void
    {
        $delegated = $this->albums->create(
            Album::TYPE_LOCAL, 'Délégué', null, '2026-01-01', null, $this->scoutYearId, null, 1, 1, 'camp', 42
        );

        $this->assertNull($this->service()->describe($delegated, 'chief', 'chef@unite.be'));
        $this->assertNull($this->service()->describe(999, 'chief', 'chef@unite.be'));
    }

    private function localAlbum(): int
    {
        return $this->albums->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, 1, 1);
    }

    private function photo(int $albumId, string $large): int
    {
        $id = $this->media->create($albumId, 'photo', 1, 1, 'photo.jpg');
        $this->media->markPhotoDone($id, 'thumb.jpg', 'medium.jpg', $large, 800, 600);

        return $id;
    }

    private function service(): AlbumShareSourceService
    {
        $albumService = $this->createStub(AlbumService::class);
        $albumService->method('findById')->willReturnCallback(fn (int $id): ?Album => $this->albums->findById($id));

        $access = $this->createStub(GalleryAccessService::class);
        $access->method('canManageAlbum')->willReturnCallback(fn (): bool => $this->manages);

        $locations = $this->createStub(GalleryLocationService::class);
        $locations->method('resolveLocationForAlbum')->willReturn(new StorageLocation(
            1, StorageLocationType::Local, 'Local', true, new LocalLocationConfig('gallery'), false, null, null, null, '2026-01-01'
        ));

        $backend = $this->createStub(StorageBackendInterface::class);
        $backend->method('get')->willReturnCallback(fn (string $key): string => $this->stored[$key]
            ?? throw new \RuntimeException('missing ' . $key));
        $backends = $this->createStub(StorageBackendFactory::class);
        $backends->method('create')->willReturn($backend);

        $files = $this->createStub(StoredFileReader::class);
        $files->method('read')->willReturnCallback(static fn (int $id): ?string => $id === 55 ? 'OG-BYTES' : null);

        return new AlbumShareSourceService($albumService, $this->media, $access, $locations, $backends, $files);
    }
}
