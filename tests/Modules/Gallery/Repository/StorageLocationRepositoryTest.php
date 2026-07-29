<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Core\Security\EncryptionService;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\GalleryException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
class StorageLocationRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private StorageLocationRepository $repository;
    private AlbumRepository $albumRepository;
    private int $scoutYearId;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new StorageLocationRepository($this->pdo, $encryption);
        $this->albumRepository = new AlbumRepository($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );

        $location = $this->repository->findById($id);

        $this->assertNotNull($location);
        $this->assertSame('Stockage local', $location->label);
        $this->assertFalse($location->isS3());
        $this->assertSame('gallery', $location->subdir);
    }

    public function testCreateS3LocationEncryptsTheSecret(): void
    {
        $id = $this->repository->create(
            StorageLocation::TYPE_S3, 'Bucket Hetzner', null, 'hetzner',
            'https://fsn1.your-objectstorage.com', 'fsn1', 'scoutmagic', 'AK123', null, 'super-secret'
        );

        $location = $this->repository->findById($id);

        $this->assertTrue($location->isS3());
        $this->assertTrue($location->secretConfigured);
        $this->assertSame('super-secret', $this->repository->getSecret($id));

        // The raw column never stores the plaintext secret.
        $raw = $this->pdo->query('SELECT secret_key_encrypted FROM gallery_storage_locations WHERE id = ' . $id)->fetchColumn();
        $this->assertStringNotContainsString('super-secret', (string) $raw);
    }

    public function testFindAllReturnsEveryLocation(): void
    {
        $this->repository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $this->repository->create(StorageLocation::TYPE_S3, 'S3', null, 'custom', 'https://x', 'eu', 'bucket', 'ak', null, 'sk');

        $this->assertCount(2, $this->repository->findAll());
    }

    public function testUpdateWithBlankSecretKeepsTheExistingOne(): void
    {
        $id = $this->repository->create(
            StorageLocation::TYPE_S3, 'S3', null, 'custom', 'https://x', 'eu', 'bucket', 'ak', null, 'original-secret'
        );

        $this->repository->update($id, 'S3 renamed', null, 'custom', 'https://x', 'eu', 'bucket', 'ak', null, '');

        $this->assertSame('original-secret', $this->repository->getSecret($id));
        $this->assertSame('S3 renamed', $this->repository->findById($id)->label);
    }

    public function testUpdateWithANewSecretReplacesIt(): void
    {
        $id = $this->repository->create(
            StorageLocation::TYPE_S3, 'S3', null, 'custom', 'https://x', 'eu', 'bucket', 'ak', null, 'original-secret'
        );

        $this->repository->update($id, 'S3', null, 'custom', 'https://x', 'eu', 'bucket', 'ak', null, 'new-secret');

        $this->assertSame('new-secret', $this->repository->getSecret($id));
    }

    public function testDeleteRemovesAnUnreferencedLocation(): void
    {
        $id = $this->repository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testDeleteThrowsWhenStillReferencedByAnAlbum(): void
    {
        $id = $this->repository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, $id, $this->authorId);

        $this->expectException(GalleryException::class);
        $this->repository->delete($id);
    }

    public function testCountAlbumsUsingReturnsZeroForAnUnreferencedLocation(): void
    {
        $id = $this->repository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);

        $this->assertSame(0, $this->repository->countAlbumsUsing($id));
    }

    public function testRecordCheckResultPersistsOkAndError(): void
    {
        $id = $this->repository->create(StorageLocation::TYPE_LOCAL, 'Local', 'gallery', null, null, null, null, null, null, null);

        $this->repository->recordCheckResult($id, false, 'Dossier introuvable.');

        $location = $this->repository->findById($id);
        $this->assertFalse($location->lastCheckOk);
        $this->assertSame('Dossier introuvable.', $location->lastCheckError);
        $this->assertNotNull($location->lastCheckedAt);
    }

    public function testFindByLabelReturnsNullWhenNoMatch(): void
    {
        $this->assertNull($this->repository->findByLabel('unknown'));
    }
}
