<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\OgScraperService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
class AlbumServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albumRepository;
    private AlbumService $service;
    private GalleryAccessService $accessService;
    private StorageBackendFactory $storageBackendFactory;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $mediaRepository = new MediaRepository($this->pdo);
        $this->accessService = $this->createMock(GalleryAccessService::class);
        $this->accessService->method('canManageAlbum')->willReturn(true);
        $ogScraperService = $this->createMock(OgScraperService::class);
        $this->storageBackendFactory = $this->createMock(StorageBackendFactory::class);
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $scoutYearService = new ScoutYearService($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'MEUTE_A', 'Meute A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->service = new AlbumService(
            $this->albumRepository, $mediaRepository, $this->accessService, $ogScraperService,
            $this->storageBackendFactory, $scoutYearService, $settingService
        );
    }

    public function testCreateLocalAlbum(): void
    {
        $album = $this->service->create(
            Album::TYPE_LOCAL, 'Camp', 'Semaine 1', '2026-07-01', $this->sectionId, null, $this->authorId, Role::CHIEF, 'chief@test.com'
        );

        $this->assertSame('Camp', $album->title);
        $this->assertSame($this->scoutYearId, $album->scoutYearId);
    }

    public function testCreateRejectsEmptyTitle(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(Album::TYPE_LOCAL, '   ', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateRejectsInvalidType(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create('bogus', 'Titre', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateExternalAlbumRequiresAUrl(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-07-01', null, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testCreateRejectsWhenTheChiefDoesNotManageTheSection(): void
    {
        $accessService = $this->createMock(GalleryAccessService::class);
        $accessService->method('canManageAlbum')->willReturn(false);
        $service = new AlbumService(
            $this->albumRepository, new MediaRepository($this->pdo), $accessService,
            $this->createMock(OgScraperService::class), $this->storageBackendFactory,
            new ScoutYearService($this->pdo), $this->settingServiceAllowingEverything()
        );

        $this->expectException(GalleryException::class);
        $service->create(Album::TYPE_LOCAL, 'Titre', null, '2026-07-01', $this->sectionId, null, $this->authorId, Role::CHIEF, 'chief@test.com');
    }

    public function testUpdateChangesTitle(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->authorId);

        $updated = $this->service->update($id, 'Nouveau titre', null, '2026-01-01', null, null, Role::CHIEF, 'chief@test.com');

        $this->assertSame('Nouveau titre', $updated->title);
    }

    public function testUpdateThrowsWhenAlbumDoesNotExist(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->update(999999, 'Titre', null, '2026-01-01', null, null, Role::CHIEF, 'chief@test.com');
    }

    public function testDeleteRemovesTheAlbum(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_LOCAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, null, $this->authorId);
        $backend = $this->createMock(\Modules\Gallery\Service\Storage\StorageBackendInterface::class);
        $backend->expects($this->once())->method('deletePrefix')->with((string) $id);
        $this->storageBackendFactory->method('create')->willReturn($backend);

        $this->service->delete($id, Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->albumRepository->findById($id));
    }

    public function testDeleteExternalAlbumNeverTouchesStorage(): void
    {
        $id = $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Titre', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com', $this->authorId);
        $this->storageBackendFactory->expects($this->never())->method('create');

        $this->service->delete($id, Role::CHIEF, 'chief@test.com');

        $this->assertNull($this->albumRepository->findById($id));
    }

    public function testSetCoverRejectsMediaFromAnotherAlbum(): void
    {
        $id1 = $this->albumRepository->create(Album::TYPE_LOCAL, 'Album 1', null, '2026-01-01', null, $this->scoutYearId, null, $this->authorId);
        $id2 = $this->albumRepository->create(Album::TYPE_LOCAL, 'Album 2', null, '2026-01-01', null, $this->scoutYearId, null, $this->authorId);
        $stmt = $this->pdo->prepare("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('a', 'a', 'image/jpeg', 1, 'identified')");
        $stmt->execute();
        $fileId = (int) $this->pdo->lastInsertId();
        $mediaRepository = new MediaRepository($this->pdo);
        $mediaId = $mediaRepository->create($id2, 'photo', $fileId, 0, null);

        $this->expectException(GalleryException::class);
        $this->service->setCover($id1, $mediaId, Role::CHIEF, 'chief@test.com');
    }

    private function settingServiceAllowingEverything(): SettingService
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        return $settingService;
    }
}
