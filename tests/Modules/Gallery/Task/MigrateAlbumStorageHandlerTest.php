<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\Storage\LocalStorageBackend;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\Storage\StorageBackendInterface;
use Modules\Gallery\Task\MigrateAlbumStorageHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MigrateAlbumStorageHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;
    private AlbumRepository $albumRepository;
    private MediaRepository $mediaRepository;
    private StorageLocationRepository $storageLocationRepository;
    private int $sourceId;
    private int $targetId;
    private int $albumId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/gallery_migration_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);

        $this->albumRepository = new AlbumRepository($this->pdo);
        $this->mediaRepository = new MediaRepository($this->pdo);
        $this->storageLocationRepository = new StorageLocationRepository($this->pdo, $this->encryption);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $authorId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $this->sourceId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Source', 'source', null, null, null, null, null, null, null
        );
        $this->targetId = $this->storageLocationRepository->create(
            StorageLocation::TYPE_LOCAL, 'Cible', 'target', null, null, null, null, null, null, null
        );

        $this->albumId = $this->albumRepository->create(
            Album::TYPE_LOCAL, 'Camp', null, '2026-01-01', null, $scoutYearId, null, $this->sourceId, $authorId
        );
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->storagePath);
    }

    private function removeRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    /**
     * Creates one media row with thumb/medium/large renditions physically
     * present at the source location, distinct content per path.
     */
    private function createMediaWithFiles(): int
    {
        $mediaId = $this->mediaRepository->create($this->albumId, Media::TYPE_PHOTO, 1, 0, 'test.jpg');
        $thumbPath = "{$this->albumId}/thumb_{$mediaId}.jpg";
        $mediumPath = "{$this->albumId}/med_{$mediaId}.jpg";
        $largePath = "{$this->albumId}/lg_{$mediaId}.jpg";

        $sourceBackend = new LocalStorageBackend($this->storagePath, 'source');
        $sourceBackend->put($thumbPath, "thumb-bytes-{$mediaId}", 'image/jpeg');
        $sourceBackend->put($mediumPath, "medium-bytes-{$mediaId}", 'image/jpeg');
        $sourceBackend->put($largePath, "large-bytes-{$mediaId}", 'image/jpeg');

        $this->mediaRepository->markPhotoDone($mediaId, $thumbPath, $mediumPath, $largePath, 100, 100);

        return $mediaId;
    }

    private function startMigration(): void
    {
        $this->albumRepository->startMigration($this->albumId, $this->targetId);
    }

    private function buildContext(?StorageBackendFactory $storageBackendFactory = null): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->storagePath
        );
    }

    public function testSuccessfulMigrationMovesEveryFileAndUpdatesTheAlbum(): void
    {
        $mediaId = $this->createMediaWithFiles();
        $this->startMigration();

        (new MigrateAlbumStorageHandler())->handle(['album_id' => $this->albumId], $this->buildContext());

        $targetBackend = new LocalStorageBackend($this->storagePath, 'target');
        $this->assertSame("thumb-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertSame("medium-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertSame("large-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/lg_{$mediaId}.jpg"));

        $sourceBackend = new LocalStorageBackend($this->storagePath, 'source');
        $this->assertFalse($sourceBackend->exists("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertFalse($sourceBackend->exists("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertFalse($sourceBackend->exists("{$this->albumId}/lg_{$mediaId}.jpg"));

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame($this->targetId, $album->storageLocationId);
        $this->assertSame(Album::MIGRATION_NONE, $album->migrationStatus);
        $this->assertNull($album->migrationTargetLocationId);
        $this->assertNull($album->migrationError);

        $entry = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'album_storage_migrated'")->fetch();
        $this->assertNotFalse($entry);
    }

    /**
     * The single most important assertion in this whole feature: when the
     * destination fails partway through, the source is left byte-for-byte
     * intact and the album keeps pointing at it.
     */
    public function testDestinationWriteFailureLeavesTheSourceFullyIntact(): void
    {
        $mediaId = $this->createMediaWithFiles();
        $this->startMigration();

        $factory = $this->factoryFailingOnNthDestinationPut(2);
        (new MigrateAlbumStorageHandler($factory))->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_FAILED, $album->migrationStatus);
        $this->assertNotNull($album->migrationError);
        $this->assertSame($this->sourceId, $album->storageLocationId, 'storage_location_id must stay pinned to the source');

        $sourceBackend = new LocalStorageBackend($this->storagePath, 'source');
        $this->assertSame("thumb-bytes-{$mediaId}", $sourceBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertSame("medium-bytes-{$mediaId}", $sourceBackend->get("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertSame("large-bytes-{$mediaId}", $sourceBackend->get("{$this->albumId}/lg_{$mediaId}.jpg"));
    }

    /**
     * Same safety requirement when the write itself "succeeds" but the
     * self-verification read-back reveals corrupted content.
     */
    public function testVerificationMismatchLeavesTheSourceFullyIntact(): void
    {
        $mediaId = $this->createMediaWithFiles();
        $this->startMigration();

        $factory = $this->factoryCorruptingDestinationReads();
        (new MigrateAlbumStorageHandler($factory))->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_FAILED, $album->migrationStatus);
        $this->assertStringContainsString('différent après copie', (string) $album->migrationError);
        $this->assertSame($this->sourceId, $album->storageLocationId);

        $sourceBackend = new LocalStorageBackend($this->storagePath, 'source');
        $this->assertSame("thumb-bytes-{$mediaId}", $sourceBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
    }

    public function testIsANoOpWhenMigrationStatusIsNone(): void
    {
        $this->createMediaWithFiles();
        // migration_status stays 'none' — never started.

        (new MigrateAlbumStorageHandler())->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_NONE, $album->migrationStatus);
        $this->assertSame($this->sourceId, $album->storageLocationId);
    }

    public function testIsANoOpForADeletedAlbum(): void
    {
        (new MigrateAlbumStorageHandler())->handle(['album_id' => 999999], $this->buildContext());
        $this->assertTrue(true);
    }

    public function testIsANoOpWhenTheTargetLocationWasDeletedMidFlight(): void
    {
        $this->createMediaWithFiles();
        $this->startMigration();
        $this->storageLocationRepository->delete($this->targetId);

        (new MigrateAlbumStorageHandler())->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        // Left exactly as-is for an admin to notice and retry with a valid target.
        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $album->migrationStatus);
        $this->assertSame($this->sourceId, $album->storageLocationId);
    }

    public function testARetryAfterAFailedMigrationSucceeds(): void
    {
        $mediaId = $this->createMediaWithFiles();
        $this->startMigration();

        $failingFactory = $this->factoryFailingOnNthDestinationPut(2);
        (new MigrateAlbumStorageHandler($failingFactory))->handle(['album_id' => $this->albumId], $this->buildContext());
        $this->assertSame(Album::MIGRATION_FAILED, $this->albumRepository->findById($this->albumId)->migrationStatus);

        // Admin retries — same trigger action, source untouched so this is safe.
        $this->albumRepository->startMigration($this->albumId, $this->targetId);
        (new MigrateAlbumStorageHandler())->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_NONE, $album->migrationStatus);
        $this->assertSame($this->targetId, $album->storageLocationId);

        $targetBackend = new LocalStorageBackend($this->storagePath, 'target');
        $this->assertSame("thumb-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertSame("medium-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertSame("large-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/lg_{$mediaId}.jpg"));
    }

    /**
     * A real LocalStorageBackend can't be made to fail on demand for one
     * specific file, so the destination backend is swapped for a decorator
     * that throws once a given number of successful puts have happened —
     * the source backend is always the real, untouched one.
     */
    private function factoryFailingOnNthDestinationPut(int $failOnPutNumber): StorageBackendFactory
    {
        $sourceId = $this->sourceId;
        $storagePath = $this->storagePath;

        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->method('create')->willReturnCallback(function (StorageLocation $location) use ($sourceId, $storagePath, $failOnPutNumber) {
            if ($location->id === $sourceId) {
                return new LocalStorageBackend($storagePath, 'source');
            }
            return new class(new LocalStorageBackend($storagePath, 'target'), $failOnPutNumber) implements StorageBackendInterface {
                private int $putCount = 0;
                public function __construct(private LocalStorageBackend $real, private int $failOnPutNumber)
                {
                }
                public function put(string $key, string $contents, string $mimeType): void
                {
                    $this->putCount++;
                    if ($this->putCount === $this->failOnPutNumber) {
                        throw new \RuntimeException('Simulated destination write failure');
                    }
                    $this->real->put($key, $contents, $mimeType);
                }
                public function get(string $key): string
                {
                    return $this->real->get($key);
                }
                public function localPath(string $key): ?string
                {
                    return null;
                }
                public function size(string $key): ?int
                {
                    return $this->real->size($key);
                }
                public function getRange(string $key, int $offset, int $length): string
                {
                    return $this->real->getRange($key, $offset, $length);
                }
                public function delete(string $key): void
                {
                    $this->real->delete($key);
                }
                public function deletePrefix(string $prefix): void
                {
                    $this->real->deletePrefix($prefix);
                }
                public function url(string $key, string $ttl = '+1 hour'): string
                {
                    return $this->real->url($key, $ttl);
                }
                public function exists(string $key): bool
                {
                    return $this->real->exists($key);
                }
            };
        });

        return $factory;
    }

    /**
     * A destination decorator whose put() genuinely writes, but whose get()
     * always returns tampered bytes — simulating a storage backend that
     * silently corrupts data on write.
     */
    private function factoryCorruptingDestinationReads(): StorageBackendFactory
    {
        $sourceId = $this->sourceId;
        $storagePath = $this->storagePath;

        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->method('create')->willReturnCallback(function (StorageLocation $location) use ($sourceId, $storagePath) {
            if ($location->id === $sourceId) {
                return new LocalStorageBackend($storagePath, 'source');
            }
            return new class(new LocalStorageBackend($storagePath, 'target')) implements StorageBackendInterface {
                public function __construct(private LocalStorageBackend $real)
                {
                }
                public function put(string $key, string $contents, string $mimeType): void
                {
                    $this->real->put($key, $contents, $mimeType);
                }
                public function get(string $key): string
                {
                    return $this->real->get($key) . '-corrupted';
                }
                public function localPath(string $key): ?string
                {
                    // Force the buffered read path so the corruption above is
                    // always exercised (never streamed straight off disk).
                    return null;
                }
                // Every read path reports the SAME corrupted content, not
                // just get(): the handler happens to verify through get()
                // today, but a double that corrupts only that one path
                // would let a future switch to size()/getRange() silently
                // pass this test for the wrong reason — no mismatch
                // detected because the double stopped simulating one.
                public function size(string $key): ?int
                {
                    return strlen($this->get($key));
                }
                public function getRange(string $key, int $offset, int $length): string
                {
                    return substr($this->get($key), $offset, $length);
                }
                public function delete(string $key): void
                {
                    $this->real->delete($key);
                }
                public function deletePrefix(string $prefix): void
                {
                    $this->real->deletePrefix($prefix);
                }
                public function url(string $key, string $ttl = '+1 hour'): string
                {
                    return $this->real->url($key, $ttl);
                }
                public function exists(string $key): bool
                {
                    return $this->real->exists($key);
                }
            };
        });

        return $factory;
    }
}
