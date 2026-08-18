<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryMemberQueryService;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\StorageLocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GalleryMemberQueryServiceTest extends TestCase
{
    private \PDO $pdo;
    private AlbumRepository $albumRepository;
    private GalleryMemberQueryService $service;
    private int $authorId;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $mediaRepository = new MediaRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $scoutYearService = new ScoutYearService($this->pdo);

        $accessService = $this->createMock(GalleryAccessService::class);
        $storageLocationRepository = new StorageLocationRepository($this->pdo, $encryption);
        $storageBackendFactory = new StorageBackendFactory($storageLocationRepository, sys_get_temp_dir());
        $settingService = $this->createMock(\Core\Config\SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        $storageLocationService = new StorageLocationService(
            $storageLocationRepository, $this->albumRepository, $storageBackendFactory, $settingService,
            new S3SecretRepository($this->pdo, $encryption), sys_get_temp_dir()
        );
        $mediaService = new MediaService(
            $mediaRepository, $this->albumRepository, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new SchedulerService(new SchedulerRepository($this->pdo)), $settingService,
            $accessService, $storageBackendFactory, $storageLocationService, $this->createMock(FfmpegAvailability::class)
        );

        $this->service = new GalleryMemberQueryService($this->albumRepository, $mediaRepository, $mediaService, $sectionService, $scoutYearService);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'MEUTE_A', 'Meute A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testReturnsEmptyArrayForUnknownScoutYearLabel(): void
    {
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);

        $result = $this->service->getAlbumsForMember([], 'unknown-year', 10);

        $this->assertSame([], $result);
    }

    public function testReturnsUnitWideAlbumRegardlessOfSectionCodes(): void
    {
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $this->scoutYearId, null, null, $this->authorId);

        $result = $this->service->getAlbumsForMember([], '2025-2026', 10);

        $this->assertCount(1, $result);
        $this->assertSame('Camp', $result[0]['title']);
        $this->assertSame('/gallery/' . $result[0]['id'], $result[0]['url']);
    }

    public function testHidesSectionScopedAlbumWhenSectionCodeNotProvided(): void
    {
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Réunion Meute', null, '2026-01-01', $this->sectionId, $this->scoutYearId, null, null, $this->authorId);

        $result = $this->service->getAlbumsForMember([], '2025-2026', 10);

        $this->assertSame([], $result);
    }

    public function testShowsSectionScopedAlbumWhenSectionCodeMatches(): void
    {
        $this->albumRepository->create(Album::TYPE_LOCAL, 'Réunion Meute', null, '2026-01-01', $this->sectionId, $this->scoutYearId, null, null, $this->authorId);

        $result = $this->service->getAlbumsForMember(['MEUTE_A'], '2025-2026', 10);

        $this->assertCount(1, $result);
        $this->assertSame('Réunion Meute', $result[0]['title']);
    }

    public function testExternalAlbumUrlPointsDirectlyAtTheExternalLink(): void
    {
        $this->albumRepository->create(Album::TYPE_EXTERNAL, 'Album externe', null, '2026-01-01', null, $this->scoutYearId, 'https://example.com/x', null, $this->authorId);

        $result = $this->service->getAlbumsForMember([], '2025-2026', 10);

        $this->assertSame('https://example.com/x', $result[0]['url']);
    }

    public function testRespectsTheLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->albumRepository->create(Album::TYPE_LOCAL, "Album $i", null, '2026-01-0' . ($i + 1), null, $this->scoutYearId, null, null, $this->authorId);
        }

        $result = $this->service->getAlbumsForMember([], '2025-2026', 2);

        $this->assertCount(2, $result);
    }

    public function testNeverReturnsADelegatedAlbumEvenUnitWide(): void
    {
        // Api\GalleryAlbumProvider is a public entry point any core/module
        // consumer can call — a delegated album must stay reachable only
        // through its own owning module, never through this one.
        $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Groupe Louveteaux', null, '2026-01-01', null, $this->scoutYearId, null, null,
            $this->authorId, 'discussion_group', 42
        );

        $result = $this->service->getAlbumsForMember([], '2025-2026', 10);

        $this->assertSame([], $result);
    }
}
