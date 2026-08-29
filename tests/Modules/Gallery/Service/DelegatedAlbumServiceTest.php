<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\DelegatedAlbumService;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\Storage\LocalStorageBackend;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DelegatedAlbumServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albumRepository;
    private MediaRepository $mediaRepository;
    private StorageLocationRepository $storageLocationRepository;
    private StorageBackendFactory $storageBackendFactory;
    private DelegatedAlbumService $service;
    private int $authorId;
    private int $localLocationId;
    private ?string $tempStorage = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->mediaRepository = new MediaRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $this->storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        $storageLocationService = new StorageLocationService(
            $this->storageLocationRepository, $this->albumRepository, $this->storageBackendFactory,
            $settingService, new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );
        $mediaService = $this->createMock(MediaService::class);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $this->localLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $this->service = new DelegatedAlbumService(
            $this->albumRepository, $this->mediaRepository, $mediaService, $this->storageLocationRepository,
            $storageLocationService, $this->storageBackendFactory, new ScoutYearService($this->pdo)
        );
    }

    protected function tearDown(): void
    {
        if ($this->tempStorage !== null && is_dir($this->tempStorage)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempStorage, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
            }
            rmdir($this->tempStorage);
        }
        $this->tempStorage = null;
    }

    public function testEnsureAlbumCreatesADelegatedAlbumOnFirstCall(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);

        $this->assertSame('Titre', $album->title);
        $stored = $this->albumRepository->findByOwner('some_owner_type', 42);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isDelegated());
        $this->assertSame($this->localLocationId, $stored->storageLocationId);
    }

    public function testEnsureAlbumIsIdempotentAndReturnsTheSameAlbumOnASecondCall(): void
    {
        $first = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $second = $this->service->ensureAlbum('some_owner_type', 42, 'Titre différent', '2026-02-02', $this->authorId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Titre', $second->title);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
    }

    public function testEnsureAlbumRefusesAStorageLocationWithAPublicUrlConfigured(): void
    {
        $publicLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket public', null, 'custom', 'https://s3.example.com', 'eu',
            'bucket', 'ak', 'https://cdn.example.com', 'sk'
        );

        $this->expectException(GalleryException::class);
        $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId, $publicLocationId);
    }

    public function testEnsureAlbumAcceptsAPrivateS3Location(): void
    {
        $privateLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_S3, 'Bucket privé', null, 'custom', 'https://s3.example.com', 'eu',
            'bucket', 'ak', null, 'sk'
        );

        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId, $privateLocationId);

        $stored = $this->albumRepository->findById($album->id);
        $this->assertSame($privateLocationId, $stored->storageLocationId);
    }

    public function testListMediaThrowsForAnUnknownAlbum(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->listMedia(999999);
    }

    public function testListMediaThrowsForAnOrdinaryNonDelegatedAlbum(): void
    {
        $ordinaryId = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Ordinaire', null, '2026-01-01', null, 1, null, $this->localLocationId, $this->authorId
        );

        $this->expectException(GalleryException::class);
        $this->service->listMedia($ordinaryId);
    }

    public function testListMediaReturnsTheAlbumsMedia(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $this->mediaRepository->create($album->id, 'photo', $fileId, 0, 'photo.jpg');

        $media = $this->service->listMedia($album->id);

        $this->assertCount(1, $media);
        $this->assertSame('photo.jpg', $media[0]->originalFilename);
    }

    public function testDeleteMediaThrowsWhenTheMediaBelongsToAnotherAlbum(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $otherAlbum = $this->service->ensureAlbum('some_owner_type', 43, 'Autre', '2026-01-01', $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaId = $this->mediaRepository->create($otherAlbum->id, 'photo', $fileId, 0, null);

        $this->expectException(GalleryException::class);
        $this->service->deleteMedia($album->id, $mediaId);
    }

    public function testDeleteAlbumRemovesTheAlbumRowAndCleansUpStorage(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->once())->method('deletePrefix')->with((string) $album->id);
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $this->service->deleteAlbum($album->id);

        $this->assertNull($this->albumRepository->findById($album->id));
    }

    public function testDeleteAlbumThrowsForAnUnknownAlbum(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->deleteAlbum(999999);
    }

    /**
     * Simulates two requests racing to create the same owner's first
     * album: findByOwner() misses (the competitor's row isn't there yet),
     * then the competitor's INSERT lands first, so this call's own
     * INSERT hits gallery_albums' UNIQUE(owner_type, owner_id) index and
     * must recover by returning the competitor's album instead of
     * throwing or creating a second one.
     */
    public function testEnsureAlbumIsSafeUnderAConcurrentRaceForTheSameOwner(): void
    {
        $racingRepository = new class ($this->pdo) extends AlbumRepository {
            public ?int $competitorAlbumId = null;

            public function create(
                string $type, string $title, ?string $subtitle, string $albumDate, ?int $sectionId,
                int $scoutYearId, ?string $externalUrl, ?int $storageLocationId, int $createdBy,
                ?string $ownerType = null, ?int $ownerId = null
            ): int {
                // The "other request" wins the race, committing its own
                // album for the same owner right before this call's INSERT
                // runs — parent::create() below must now collide with it.
                $this->competitorAlbumId = parent::create(
                    $type, 'Compétiteur', $subtitle, $albumDate, $sectionId, $scoutYearId,
                    $externalUrl, $storageLocationId, $createdBy, $ownerType, $ownerId
                );

                return parent::create(
                    $type, $title, $subtitle, $albumDate, $sectionId, $scoutYearId,
                    $externalUrl, $storageLocationId, $createdBy, $ownerType, $ownerId
                );
            }
        };

        $service = new DelegatedAlbumService(
            $racingRepository, $this->mediaRepository, $this->createMock(MediaService::class),
            $this->storageLocationRepository,
            new StorageLocationService(
                $this->storageLocationRepository, $racingRepository, $this->storageBackendFactory,
                $this->createMock(SettingService::class), new S3SecretRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
                sys_get_temp_dir()
            ),
            $this->storageBackendFactory, new ScoutYearService($this->pdo)
        );

        $album = $service->ensureAlbum('some_owner_type', 42, 'Titre', '2026-01-01', $this->authorId);

        $this->assertNotNull($racingRepository->competitorAlbumId);
        $this->assertSame($racingRepository->competitorAlbumId, $album->id, 'the competitor\'s album must win, never a second one');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
    }

    // ---------------------------------------------------------------
    // moveMedia() — merging two albums one module owns
    // ---------------------------------------------------------------

    public function testMoveMediaMovesEveryMediaRenditionUnderTheTargetPrefix(): void
    {
        $backend = $this->useRealLocalBackend();
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId);
        $mediaId = $this->createProcessedPhoto($from->id, $backend);

        $moved = $this->service->moveMedia('some_owner_type', $from->id, $to->id);

        $this->assertSame(1, $moved);
        $media = $this->mediaRepository->findById($mediaId);
        $this->assertNotNull($media);
        $this->assertSame($to->id, $media->albumId);
        $this->assertSame("{$to->id}/thumb_{$mediaId}.jpg", $media->thumbPath);
        $this->assertSame("{$to->id}/med_{$mediaId}.jpg", $media->mediumPath);
        $this->assertSame("{$to->id}/lg_{$mediaId}.jpg", $media->largePath);

        // The bytes really followed, and no longer answer at the old key —
        // which is the whole point: the source album's deletePrefix() must
        // have nothing of the target's left to destroy.
        $this->assertSame('thumb-bytes', $backend->get("{$to->id}/thumb_{$mediaId}.jpg"));
        $this->assertFalse($backend->exists("{$from->id}/thumb_{$mediaId}.jpg"));
        $this->assertFalse($backend->exists("{$from->id}/med_{$mediaId}.jpg"));
        $this->assertFalse($backend->exists("{$from->id}/lg_{$mediaId}.jpg"));
    }

    public function testMoveMediaLeavesTheSourceAlbumEmptyButIntact(): void
    {
        $backend = $this->useRealLocalBackend();
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId);
        $this->createProcessedPhoto($from->id, $backend);

        $this->service->moveMedia('some_owner_type', $from->id, $to->id);

        $this->assertNotNull($this->albumRepository->findById($from->id));
        $this->assertSame(0, $this->mediaRepository->countByAlbumId($from->id));
        $this->assertSame(1, $this->mediaRepository->countByAlbumId($to->id));
    }

    public function testMoveMediaAppendsAfterTheTargetsOwnMediaKeepingRelativeOrder(): void
    {
        $backend = $this->useRealLocalBackend();
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId);
        $kept = $this->createProcessedPhoto($to->id, $backend, 'deja-la.jpg');
        $first = $this->createProcessedPhoto($from->id, $backend, 'un.jpg', 0);
        $second = $this->createProcessedPhoto($from->id, $backend, 'deux.jpg', 1);

        $this->service->moveMedia('some_owner_type', $from->id, $to->id);

        $order = array_map(
            static fn($m): int => $m->id,
            $this->mediaRepository->findByAlbumId($to->id)
        );
        $this->assertSame([$kept, $first, $second], $order);
    }

    public function testMoveMediaDoesNotTouchTheOriginalFileRow(): void
    {
        $backend = $this->useRealLocalBackend();
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId);
        $mediaId = $this->createProcessedPhoto($from->id, $backend);
        $fileId = $this->mediaRepository->findById($mediaId)?->fileId;

        $this->service->moveMedia('some_owner_type', $from->id, $to->id);

        // The original lives in `files`, outside any album prefix — a merge
        // must never disturb it.
        $this->assertSame($fileId, $this->mediaRepository->findById($mediaId)?->fileId);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testMoveMediaReturnsZeroForAnEmptySourceAlbum(): void
    {
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId);

        $this->assertSame(0, $this->service->moveMedia('some_owner_type', $from->id, $to->id));
    }

    public function testMoveMediaRefusesAnAlbumBelongingToAnotherOwnerType(): void
    {
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $foreign = $this->service->ensureAlbum('another_module', 1, 'Ailleurs', '2026-01-01', $this->authorId);

        $this->expectException(GalleryException::class);
        $this->service->moveMedia('some_owner_type', $from->id, $foreign->id);
    }

    public function testMoveMediaRefusesWhenTheCallerClaimsTheWrongOwnerType(): void
    {
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId);

        $this->expectException(GalleryException::class);
        $this->service->moveMedia('another_module', $from->id, $to->id);
    }

    public function testMoveMediaRefusesAnOrdinaryNonDelegatedAlbum(): void
    {
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $ordinaryId = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Ordinaire', null, '2026-01-01', null, 1, null, $this->localLocationId, $this->authorId
        );

        $this->expectException(GalleryException::class);
        $this->service->moveMedia('some_owner_type', $from->id, $ordinaryId);
    }

    public function testMoveMediaRefusesMergingAnAlbumWithItself(): void
    {
        $album = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);

        $this->expectException(GalleryException::class);
        $this->service->moveMedia('some_owner_type', $album->id, $album->id);
    }

    public function testMoveMediaRefusesTwoAlbumsOnDifferentStorageLocations(): void
    {
        $this->useRealLocalBackend();
        $otherLocationId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Second stockage', 'gallery2', null, null, null, null, null, null, null
        );
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $to = $this->service->ensureAlbum('some_owner_type', 43, 'Cible', '2026-01-01', $this->authorId, $otherLocationId);

        // copy() cannot span two backends, and the location is read off the
        // album — moving anyway would leave the bytes on the wrong disk.
        $this->expectException(GalleryException::class);
        $this->service->moveMedia('some_owner_type', $from->id, $to->id);
    }

    public function testMoveMediaRefusalChangesNothing(): void
    {
        $backend = $this->useRealLocalBackend();
        $from = $this->service->ensureAlbum('some_owner_type', 42, 'Source', '2026-01-01', $this->authorId);
        $foreign = $this->service->ensureAlbum('another_module', 1, 'Ailleurs', '2026-01-01', $this->authorId);
        $mediaId = $this->createProcessedPhoto($from->id, $backend);

        try {
            $this->service->moveMedia('some_owner_type', $from->id, $foreign->id);
            $this->fail('the move should have been refused');
        } catch (GalleryException) {
            // expected
        }

        $media = $this->mediaRepository->findById($mediaId);
        $this->assertSame($from->id, $media?->albumId);
        $this->assertSame("{$from->id}/thumb_{$mediaId}.jpg", $media?->thumbPath);
        $this->assertTrue($backend->exists("{$from->id}/thumb_{$mediaId}.jpg"));
    }

    /**
     * Points the (mocked) factory at a real on-disk backend, so a move is
     * verified by what actually lands on the filesystem rather than by an
     * expectation on a double.
     */
    private function useRealLocalBackend(): LocalStorageBackend
    {
        $backend = new LocalStorageBackend($this->tempStoragePath(), 'gallery');
        $this->storageBackendFactory->method('create')->willReturn($backend);

        return $backend;
    }

    private function tempStoragePath(): string
    {
        if ($this->tempStorage === null) {
            $path = sys_get_temp_dir() . '/scoutmagic-delegated-move-' . bin2hex(random_bytes(6));
            mkdir($path, 0777, true);
            $this->tempStorage = $path;
        }

        return $this->tempStorage;
    }

    /**
     * A photo as it exists after Task\ProcessPhotoHandler has run: a `files`
     * row for the original, three renditions keyed under the album, and the
     * bytes actually present on the backend.
     */
    private function createProcessedPhoto(
        int $albumId,
        LocalStorageBackend $backend,
        string $filename = 'photo.jpg',
        int $sortOrder = 0
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['originals/' . $filename, $filename, 'image/jpeg', 10, 'identified']);
        $fileId = (int) $this->pdo->lastInsertId();

        $mediaId = $this->mediaRepository->create($albumId, 'photo', $fileId, $sortOrder, $filename);
        $thumb = "{$albumId}/thumb_{$mediaId}.jpg";
        $medium = "{$albumId}/med_{$mediaId}.jpg";
        $large = "{$albumId}/lg_{$mediaId}.jpg";
        $backend->put($thumb, 'thumb-bytes', 'image/jpeg');
        $backend->put($medium, 'medium-bytes', 'image/jpeg');
        $backend->put($large, 'large-bytes', 'image/jpeg');
        $this->mediaRepository->markPhotoDone($mediaId, $thumb, $medium, $large, 800, 600);

        return $mediaId;
    }
}
