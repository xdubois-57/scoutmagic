<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MediaRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MediaRepository $repository;
    private int $albumId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->repository = new MediaRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $albumRepository = new AlbumRepository($this->pdo);
        $this->albumId = $albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $scoutYearId, null, null, $authorId);

        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['gallery/orig/a.jpg', 'a.jpg', 'image/jpeg', 1000, 'identified', 'gallery', $authorId]);
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, 'photo.jpg');

        $media = $this->repository->findById($id);

        $this->assertNotNull($media);
        $this->assertSame($this->albumId, $media->albumId);
        $this->assertSame(Media::STATUS_PENDING, $media->processingStatus);
        $this->assertSame('photo.jpg', $media->originalFilename);
        $this->assertFalse($media->isVideo());
    }

    public function testFindByAlbumIdOrdersBySortOrder(): void
    {
        $id2 = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 1, 'second.jpg');
        $id1 = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, 'first.jpg');

        $media = $this->repository->findByAlbumId($this->albumId);

        $this->assertCount(2, $media);
        $this->assertSame($id1, $media[0]->id);
        $this->assertSame($id2, $media[1]->id);
    }

    public function testCountByAlbumId(): void
    {
        $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, null);
        $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 1, null);

        $this->assertSame(2, $this->repository->countByAlbumId($this->albumId));
    }

    public function testMarkProcessingAndFailed(): void
    {
        $id = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, null);

        $this->repository->markProcessing($id);
        $this->assertSame(Media::STATUS_PROCESSING, $this->repository->findById($id)->processingStatus);

        $this->repository->markFailed($id);
        $this->assertSame(Media::STATUS_FAILED, $this->repository->findById($id)->processingStatus);
    }

    public function testMarkPhotoDoneSetsAllFields(): void
    {
        $id = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, null);

        $this->repository->markPhotoDone($id, 'thumb.jpg', 'med.jpg', 'lg.jpg', 1920, 1080);

        $media = $this->repository->findById($id);
        $this->assertSame(Media::STATUS_DONE, $media->processingStatus);
        $this->assertSame('thumb.jpg', $media->thumbPath);
        $this->assertSame('med.jpg', $media->mediumPath);
        $this->assertSame('lg.jpg', $media->largePath);
        $this->assertSame(1920, $media->width);
        $this->assertSame(1080, $media->height);
    }

    public function testMarkVideoDoneSetsAllFields(): void
    {
        $id = $this->repository->create($this->albumId, Media::TYPE_VIDEO, $this->fileId, 0, null);

        $this->repository->markVideoDone($id, 'poster.jpg', 'med.mp4', 'lg.mp4', 'orig.mov', 1920, 1080, 42);

        $media = $this->repository->findById($id);
        $this->assertSame(Media::STATUS_DONE, $media->processingStatus);
        $this->assertSame('poster.jpg', $media->thumbPath);
        $this->assertSame('med.mp4', $media->mediumPath);
        $this->assertSame('lg.mp4', $media->largePath);
        $this->assertSame('orig.mov', $media->originalPath);
        $this->assertSame(42, $media->durationSeconds);
    }

    public function testReorderUpdatesSortOrder(): void
    {
        $id1 = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, null);
        $id2 = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 1, null);

        $this->repository->reorder([$id2, $id1]);

        $media = $this->repository->findByAlbumId($this->albumId);
        $this->assertSame($id2, $media[0]->id);
        $this->assertSame($id1, $media[1]->id);
    }

    public function testDeleteRemovesMedia(): void
    {
        $id = $this->repository->create($this->albumId, Media::TYPE_PHOTO, $this->fileId, 0, null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }
}
