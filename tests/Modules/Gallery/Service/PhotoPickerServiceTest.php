<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Security\Role;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Api\DelegatedAlbumAccessChecker;
use Modules\Gallery\Api\PickablePhoto;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\DelegatedAlbumAccessRegistry;
use Modules\Gallery\Service\GalleryLocationService;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\PhotoPickerService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * The photo picker: thirty photos, the covers of the fifteen newest albums
 * first in the selection, the gallery's own visibility rule.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PhotoPickerServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albums;
    private MediaRepository $media;
    private int $scoutYearId;
    private bool $delegatedAllowed = false;
    private int $clock = 0;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->albums = new AlbumRepository($this->pdo);
        $this->media = new MediaRepository($this->pdo);
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    public function testTheCoversOfTheFifteenNewestAlbumsComeFirstInTheSelectionThenTheNewestPhotos(): void
    {
        $covers = [];
        for ($i = 1; $i <= 17; $i++) {
            $album = $this->album(sprintf('2025-%02d-01', min($i, 12)), 'Album ' . $i);
            $cover = $this->photo($album);
            $this->albums->setCoverMediaId($album, $cover);
            $covers[$i] = $cover;
            for ($j = 0; $j < 2; $j++) {
                $this->photo($album);
            }
        }

        $photos = $this->service()->pickablePhotos('chief', []);

        $this->assertCount(PhotoPickerService::LIMIT, $photos);
        $this->assertCount(15, array_filter($photos, static fn (PickablePhoto $p): bool => $p->isCover));
        $ids = array_map(static fn (PickablePhoto $p): int => $p->mediaId, $photos);
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids, 'Newest first.');
        $this->assertSame('/gallery/media/' . $ids[0] . '/thumb', $photos[0]->thumbUrl, 'The gallery\'s own URL.');
    }

    public function testVideosUnprocessedPhotosExternalAndMigratingAlbumsAreLeftOut(): void
    {
        $album = $this->album('2026-01-01', 'Camp');
        $kept = $this->photo($album);
        $video = $this->media->create($album, 'video', 1, 2, 'v.mp4');
        $this->media->markVideoDone($video, 'thumb.jpg', 'v720.mp4', null, null, 1280, 720, 60);
        $this->media->create($album, 'photo', 1, 3, 'pending.jpg');
        $migrating = $this->album('2026-02-01', 'En déplacement');
        $this->photo($migrating);
        $this->albums->startMigration($migrating, 2);
        $this->albums->create(Album::TYPE_EXTERNAL, 'Externe', null, '2026-03-01', null, $this->scoutYearId, 'https://x.example', null, 1);

        $ids = array_map(static fn (PickablePhoto $p): int => $p->mediaId, $this->service()->pickablePhotos('chief', []));

        $this->assertSame([$kept], $ids);
    }

    public function testADelegatedAlbumIsOfferedOnlyWhenItsOwnersCheckerAllows(): void
    {
        $delegated = $this->albums->create(
            Album::TYPE_LOCAL, 'Camp délégué', null, '2026-01-01', null, $this->scoutYearId, null, 1, 1, 'camp', 42
        );
        $photo = $this->photo($delegated);

        $this->assertSame([], $this->service()->pickablePhotos('chief', []));
        $this->assertNull($this->service()->photoContents($photo, 'chief', []));

        $this->delegatedAllowed = true;
        $this->assertCount(1, $this->service()->pickablePhotos('chief', [7]));
        $this->assertSame('LARGE', $this->service()->photoContents($photo, 'chief', [7]));
    }

    public function testBelowChiefNothingIsOffered(): void
    {
        $this->photo($this->album('2026-01-01', 'Camp'));

        $this->assertSame([], $this->service()->pickablePhotos('intendant', []));
    }

    public function testTheContentsAreTheLargeRenditionOfAVisiblePhotoOnly(): void
    {
        $album = $this->album('2026-01-01', 'Camp');
        $photo = $this->photo($album);
        $video = $this->media->create($album, 'video', 1, 2, 'v.mp4');

        $this->assertSame('LARGE', $this->service()->photoContents($photo, 'chief', []));
        $this->assertNull($this->service()->photoContents($video, 'chief', []));
        $this->assertNull($this->service()->photoContents(999, 'chief', []));
        $this->assertNull($this->service()->photoContents($photo, 'intendant', []));
    }

    private function album(string $date, string $title): int
    {
        return $this->albums->create(Album::TYPE_LOCAL, $title, null, $date, null, $this->scoutYearId, null, 1, 1);
    }

    private function photo(int $albumId): int
    {
        $id = $this->media->create($albumId, 'photo', 1, 1, 'p.jpg');
        $this->media->markPhotoDone($id, 'thumb.jpg', 'medium.jpg', 'large.jpg', 800, 600);
        // Distinct, increasing timestamps: the order under test.
        $this->pdo->prepare('UPDATE gallery_media SET created_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', 1_700_000_000 + ++$this->clock), $id]);

        return $id;
    }

    private function service(): PhotoPickerService
    {
        $mediaService = $this->createStub(MediaService::class);
        $mediaService->method('resolveUrl')->willReturn(null);

        $locations = $this->createStub(GalleryLocationService::class);
        $locations->method('resolveLocationForAlbum')->willReturn(new StorageLocation(
            1, StorageLocationType::Local, 'Local', true, new LocalLocationConfig('gallery'), false, null, null, null, '2026-01-01'
        ));
        $backend = $this->createStub(StorageBackendInterface::class);
        $backend->method('get')->willReturnCallback(static fn (string $key): string => $key === 'large.jpg' ? 'LARGE' : 'OTHER');
        $backends = $this->createStub(StorageBackendFactory::class);
        $backends->method('create')->willReturn($backend);

        $checker = new class ($this) implements DelegatedAlbumAccessChecker {
            public function __construct(private readonly PhotoPickerServiceTest $test)
            {
            }

            public function supports(string $ownerType): bool
            {
                return $ownerType === 'camp';
            }

            public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
            {
                return $ownerId === 42 && $this->test->delegatedAllowed() && $linkedMemberIds === [7];
            }
        };

        return new PhotoPickerService(
            $this->albums,
            $this->media,
            $mediaService,
            $locations,
            $backends,
            static fn (): DelegatedAlbumAccessRegistry => new DelegatedAlbumAccessRegistry([$checker])
        );
    }

    public function delegatedAllowed(): bool
    {
        return $this->delegatedAllowed;
    }
}
