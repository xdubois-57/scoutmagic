<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Service\AlbumReadme;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryLocationService;
use Modules\Gallery\Service\OgScraperService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Storage\Location\Backend\Drive\FakeDrive;
use Tests\Core\Storage\Location\Backend\RefusingBackend;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * The album folder's `LISEZMOI.txt` (#474): what it says, when it is
 * (re)written, and that it is never anything but a courtesy file.
 *
 * Written on every kind of storage — the case for Drive is asserted
 * through {@see FakeDrive}, where the file lands in
 * `ScoutMagic/<label>/<album number>/`, and the local disk stands for the
 * others.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AlbumReadmeTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private StorageLocationRepository $locations;
    private AlbumRepository $albums;
    private int $authorId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/gallery_readme_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->albums = new AlbumRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}')"
        );

        $this->locationId = $this->locations->create(
            StorageLocationType::Local,
            'Disque du serveur',
            new LocalLocationConfig('gallery'),
            null
        );
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($this->storagePath);
    }

    // ————— What it says —————

    public function testItNamesTheAlbumItsDateAndItsAddressAndAsksToBeLeftAlone(): void
    {
        $text = (new AlbumReadme($this->settings('https://unite.example/')))->contentFor(
            $this->album(5, 'Camp de Pâques', '2026-04-12')
        );

        $this->assertStringContainsString('Album : Camp de Pâques', $text);
        $this->assertStringContainsString('Date de l\'activité : 12 avril 2026', $text);
        $this->assertStringContainsString('Sur le site : https://unite.example/gallery/5', $text);
        $this->assertStringContainsString(
            'Ce dossier est tenu à jour par ScoutMagic. Ne le renommez pas et n\'y déposez rien.',
            $text
        );
    }

    /** A site that does not know its address says so rather than printing half a link. */
    public function testASiteWithoutAnAddressSaysSoInsteadOfALink(): void
    {
        $text = (new AlbumReadme($this->settings('')))->contentFor($this->album(5, 'Camp', '2026-04-12'));

        $this->assertStringNotContainsString('/gallery/5', $text);
        $this->assertStringContainsString('l\'adresse du site n\'est pas encore renseignée', $text);
    }

    /**
     * **Best effort, and said.** A storage that refuses the file costs the
     * album nothing, and the journal keeps a line saying why the folder
     * has no readme.
     */
    public function testAWriteTheStorageRefusesIsJournaledRatherThanThrown(): void
    {
        $journal = new RecordingJournalRepository();
        $readme = new AlbumReadme($this->settings('https://unite.example'), new JournalService($journal));
        $refusing = new class extends RefusingBackend {
        };

        $this->assertFalse($readme->write($this->album(5, 'Camp', '2026-04-12'), $refusing));
        $this->assertSame(1, $journal->countOf('album_readme_failed'));
    }

    // ————— When it is written —————

    public function testCreatingAnAlbumWritesItsReadme(): void
    {
        $album = $this->create('Camp', '2026-07-01');

        $this->assertStringContainsString(
            'Album : Camp',
            (string) file_get_contents($this->readmePath($album->id))
        );
    }

    /** On Drive, it lands in the album's folder under the location's own. */
    public function testOnGoogleDriveItLandsInTheAlbumFolder(): void
    {
        $drive = new FakeDrive('folder-1', 'Photos des galeries');
        $driveId = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Photos des galeries',
            new GoogleDriveLocationConfig('client-1', 'folder-1', '2026-09-01T00:00:00+00:00'),
            (string) (new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org'))->toStorage()
        );
        $this->locations->setDefault($driveId);

        $album = $this->create('Camp', '2026-07-01', $drive);

        $this->assertSame(
            ["ScoutMagic/Photos des galeries/{$album->id}/LISEZMOI.txt"],
            $drive->paths()
        );
    }

    public function testRenamingTheAlbumRewritesItsReadme(): void
    {
        $album = $this->create('Camp', '2026-07-01');

        $this->service()->update($album->id, 'Grand camp', null, '2026-07-01', null, null, Role::ADMIN, 'a@test.be');

        $this->assertStringContainsString('Album : Grand camp', (string) file_get_contents($this->readmePath($album->id)));
    }

    public function testChangingTheDateRewritesItsReadme(): void
    {
        $album = $this->create('Camp', '2026-07-01');

        $this->service()->update($album->id, 'Camp', null, '2026-07-14', null, null, Role::ADMIN, 'a@test.be');

        $this->assertStringContainsString('14 juillet 2026', (string) file_get_contents($this->readmePath($album->id)));
    }

    /** A save that changed neither the name nor the date leaves the file alone. */
    public function testASaveThatChangesNeitherLeavesItAlone(): void
    {
        $album = $this->create('Camp', '2026-07-01');
        file_put_contents($this->readmePath($album->id), 'témoin');

        $this->service()->update($album->id, 'Camp', 'Un sous-titre', '2026-07-01', null, null, Role::ADMIN, 'a@test.be');

        $this->assertSame('témoin', file_get_contents($this->readmePath($album->id)));
    }

    /**
     * **Never taken for a medium.** The gallery finds its media in
     * `gallery_media`, never by listing the folder, so an album holding
     * only its readme holds no photograph — and deleting the album takes
     * the readme with the folder.
     */
    public function testItIsNotAMediumAndGoesWithTheAlbum(): void
    {
        $album = $this->create('Camp', '2026-07-01');
        $this->assertFileExists($this->readmePath($album->id));

        $this->assertSame([], (new MediaRepository($this->pdo))->findByAlbumId($album->id));

        $this->service()->delete($album->id, Role::ADMIN, 'a@test.be');
        $this->assertFileDoesNotExist($this->readmePath($album->id));
    }

    public function testAnExternalAlbumHasNoFolderAndNoReadme(): void
    {
        $service = $this->service();
        $album = $service->create(
            Album::TYPE_EXTERNAL,
            'Photos ailleurs',
            null,
            '2026-07-01',
            null,
            'https://photos.example.org/album',
            $this->authorId,
            Role::ADMIN,
            'a@test.be'
        );

        $this->assertFileDoesNotExist($this->readmePath($album->id));
    }

    // ————— Plumbing —————

    private function create(string $title, string $date, ?FakeDrive $drive = null): Album
    {
        return $this->service($drive)->create(
            Album::TYPE_LOCAL,
            $title,
            null,
            $date,
            null,
            null,
            $this->authorId,
            Role::ADMIN,
            'a@test.be'
        );
    }

    private function service(?FakeDrive $drive = null): AlbumService
    {
        $settings = $this->settings('https://unite.example');
        $factory = new StorageBackendFactory($this->locations, $this->storagePath, $drive?->client());
        $locationService = new StorageLocationService($this->locations, $factory, new StorageLocationConsumerRegistry());
        $access = $this->createStub(GalleryAccessService::class);
        $access->method('canManageAlbum')->willReturn(true);
        $og = $this->createStub(OgScraperService::class);
        $og->method('fetch')->willReturn(['title' => null, 'description' => null, 'image' => null]);
        $files = new FileRepository($this->pdo);

        return new AlbumService(
            $this->albums,
            new MediaRepository($this->pdo),
            $access,
            $og,
            $factory,
            $this->locations,
            $locationService,
            new GalleryLocationService($locationService, $this->albums, $settings),
            new ScoutYearService($this->pdo),
            $settings,
            $this->createStub(SchedulerService::class),
            new UploadHandler($files, sys_get_temp_dir()),
            null,
            null,
            null,
            new AlbumReadme($settings)
        );
    }

    private function settings(string $baseUrl): SettingService
    {
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $module = null, mixed $default = null): mixed
                => $key === 'base_url' ? $baseUrl : $default
        );

        return $settings;
    }

    private function album(int $id, string $title, string $date): Album
    {
        return new Album(
            $id, Album::TYPE_LOCAL, $title, null, $date, null, 1, null, null, null, null, null, null,
            $this->locationId, Album::MIGRATION_NONE, null, null, 1, '2026-01-01 00:00:00'
        );
    }

    private function readmePath(int $albumId): string
    {
        return $this->storagePath . '/gallery/' . $albumId . '/LISEZMOI.txt';
    }
}
