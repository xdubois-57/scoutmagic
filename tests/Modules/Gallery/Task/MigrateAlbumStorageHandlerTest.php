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
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Backend\RangeReadableBackend;
use Core\Storage\Location\Backend\ServerSideCopyBackend;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Modules\Gallery\Task\MigrateAlbumStorageHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Location\Config\LocalLocationConfig;

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
            StorageLocationType::Local, 'Source', new LocalLocationConfig('source'), null
        );
        $this->targetId = $this->storageLocationRepository->create(
            StorageLocationType::Local, 'Cible', new LocalLocationConfig('target'), null
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

        $sourceBackend = new LocalStorageBackend($this->storagePath . '/source');
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

        $targetBackend = new LocalStorageBackend($this->storagePath . '/target');
        $this->assertSame("thumb-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertSame("medium-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertSame("large-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/lg_{$mediaId}.jpg"));

        $sourceBackend = new LocalStorageBackend($this->storagePath . '/source');
        $this->assertFalse($sourceBackend->exists("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertFalse($sourceBackend->exists("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertFalse($sourceBackend->exists("{$this->albumId}/lg_{$mediaId}.jpg"));

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame($this->targetId, $album->locationId);
        $this->assertSame(Album::MIGRATION_NONE, $album->migrationStatus);
        $this->assertNull($album->migrationTargetId);
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
        $this->assertSame($this->sourceId, $album->locationId, 'location_id must stay pinned to the source');

        $sourceBackend = new LocalStorageBackend($this->storagePath . '/source');
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
        $this->assertSame($this->sourceId, $album->locationId);

        $sourceBackend = new LocalStorageBackend($this->storagePath . '/source');
        $this->assertSame("thumb-bytes-{$mediaId}", $sourceBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
    }

    public function testIsANoOpWhenMigrationStatusIsNone(): void
    {
        $this->createMediaWithFiles();
        // migration_status stays 'none' — never started.

        (new MigrateAlbumStorageHandler())->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_NONE, $album->migrationStatus);
        $this->assertSame($this->sourceId, $album->locationId);
    }

    public function testIsANoOpForADeletedAlbum(): void
    {
        // An album deleted between the queueing and the run: the album
        // that is actually migrating must not be moved in its place.
        $this->createMediaWithFiles();
        $this->startMigration();

        (new MigrateAlbumStorageHandler())->handle(['album_id' => 999999], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_IN_PROGRESS, $album->migrationStatus);
        $this->assertSame($this->sourceId, $album->locationId);
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
        $this->assertSame($this->sourceId, $album->locationId);
    }

    /**
     * The pass cannot start without a source, and saying so is the whole
     * point: this used to be a bare `return`, so the task « succeeded »,
     * `migration_status` never left 'in_progress', and the retry guard
     * that reads the same column refused every attempt to try again. The
     * album was frozen — unavailable for uploads, edits and serving —
     * with nothing anywhere saying why.
     */
    public function testAMissingSourceLocationFailsTheMigrationInsteadOfFreezingIt(): void
    {
        $this->createMediaWithFiles();
        $this->startMigration();
        // The source vanishing under an in-flight migration: the one way
        // a row can reach the handler with nothing to read from.
        $this->pdo->exec('UPDATE gallery_albums SET location_id = NULL WHERE id = ' . $this->albumId);

        (new MigrateAlbumStorageHandler())->handle(['album_id' => $this->albumId], $this->buildContext());

        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame(Album::MIGRATION_FAILED, $album->migrationStatus);
        // A failed migration does not block availability, so the album is
        // usable again — and retryable, which is what actually matters.
        $this->assertNotNull($album->migrationError);
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
        $this->assertSame($this->targetId, $album->locationId);

        $targetBackend = new LocalStorageBackend($this->storagePath . '/target');
        $this->assertSame("thumb-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/thumb_{$mediaId}.jpg"));
        $this->assertSame("medium-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/med_{$mediaId}.jpg"));
        $this->assertSame("large-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/lg_{$mediaId}.jpg"));
    }

    /**
     * **A cleanup that fails no longer fails in silence** (#484).
     *
     * This is the most destructive `deletePrefix()` in the application —
     * the location it prunes still holds OTHER albums — and it was the one
     * call whose outcome nothing recorded: the `catch` swallowed the
     * throwable without a word. The migration itself stays successful,
     * which is right; what changes is that « why is the old location still
     * full » has an answer.
     *
     * The source backend is the one decorated here, not the destination:
     * everything up to the cleanup must succeed for the swallow to be
     * reached at all.
     */
    public function testACleanupThatFailsIsJournaledAndDoesNotFailTheMigration(): void
    {
        $mediaId = $this->createMediaWithFiles();
        $this->startMigration();

        $factory = $this->factorySourceRefusingToPrune();
        (new MigrateAlbumStorageHandler($factory))->handle(['album_id' => $this->albumId], $this->buildContext());

        // The migration succeeded: the album points at the destination and
        // the files are there.
        $album = $this->albumRepository->findById($this->albumId);
        $this->assertSame($this->targetId, $album->locationId);
        $this->assertSame(Album::MIGRATION_NONE, $album->migrationStatus);
        $this->assertNull($album->migrationError);
        $targetBackend = new LocalStorageBackend($this->storagePath . '/target');
        $this->assertSame("medium-bytes-{$mediaId}", $targetBackend->get("{$this->albumId}/med_{$mediaId}.jpg"));

        // And the failed tidying left a trace.
        $entry = $this->pdo
            ->query("SELECT * FROM event_log WHERE event_type = 'album_storage_cleanup_failed'")
            ->fetch();
        $this->assertNotFalse($entry, 'a cleanup failure on a shared location was swallowed without a word');
        $this->assertSame('gallery', $entry['category']);
        $this->assertSame('warning', $entry['level']);
        $this->assertStringContainsString((string) $this->albumId, (string) $entry['description']);
        $this->assertStringContainsString('"album_id":' . $this->albumId, (string) $entry['context']);
        $this->assertStringContainsString('"from_location_id":' . $this->sourceId, (string) $entry['context']);

        // The successful migration is still recorded, and it comes first:
        // the tidying is an epilogue, not a condition.
        $migrated = $this->pdo
            ->query("SELECT * FROM event_log WHERE event_type = 'album_storage_migrated'")
            ->fetch();
        $this->assertNotFalse($migrated);
        $this->assertLessThan((int) $entry['id'], (int) $migrated['id']);
    }

    /**
     * The source backend refuses to prune, everything else being real.
     */
    private function factorySourceRefusingToPrune(): StorageBackendFactory
    {
        $sourceId = $this->sourceId;
        $storagePath = $this->storagePath;

        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->method('create')->willReturnCallback(
            function (StorageLocation $location) use ($sourceId, $storagePath): StorageBackendInterface {
                if ($location->id === $sourceId) {
                    return new RefusingToPruneBackend(new LocalStorageBackend($storagePath . '/source'));
                }

                return new LocalStorageBackend($storagePath . '/target');
            }
        );

        return $factory;
    }

    /**
     * A real LocalStorageBackend can't be made to fail on demand for one
     * specific file, so the destination backend is swapped for a decorator
     * that throws once a given number of successful puts have happened —
     * the source backend is always the real, untouched one.
     */
    private function factoryFailingOnNthDestinationPut(int $failOnPutNumber): StorageBackendFactory
    {
        return $this->factoryWithDestination(
            fn(LocalStorageBackend $real) => new FailingPutBackend($real, $failOnPutNumber)
        );
    }

    /**
     * A destination decorator whose put() genuinely writes, but whose get()
     * always returns tampered bytes — simulating a storage backend that
     * silently corrupts data on write.
     */
    private function factoryCorruptingDestinationReads(): StorageBackendFactory
    {
        return $this->factoryWithDestination(fn(LocalStorageBackend $real) => new CorruptingReadBackend($real));
    }

    /**
     * A factory that hands back the real source backend and whatever
     * $decorate makes of the real destination one.
     *
     * @param callable(LocalStorageBackend): StorageBackendInterface $decorate
     */
    private function factoryWithDestination(callable $decorate): StorageBackendFactory
    {
        $sourceId = $this->sourceId;
        $storagePath = $this->storagePath;

        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->method('create')->willReturnCallback(
            function (StorageLocation $location) use ($sourceId, $storagePath, $decorate) {
                if ($location->id === $sourceId) {
                    return new LocalStorageBackend($storagePath . '/source');
                }

                return $decorate(new LocalStorageBackend($storagePath . '/target'));
            }
        );

        return $factory;
    }
}

/**
 * Everything forwarded to a real local backend, so that a test double only
 * has to say what it changes.
 *
 * A named class rather than an anonymous one per test: the interface has
 * capability methods as well as the common floor, and three anonymous
 * copies of the forwarding meant three places to update every time it
 * moved — which is exactly how they came to be declaring a capability
 * whose method they no longer had.
 */
abstract class DecoratedLocalBackend implements RangeReadableBackend, ServerSideCopyBackend
{
    public function __construct(protected LocalStorageBackend $real)
    {
    }

    public function capabilities(): array
    {
        return $this->real->capabilities();
    }

    public function supports(\Core\Storage\Location\StorageCapability $capability): bool
    {
        return $this->real->supports($capability);
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        $this->real->put($key, $contents, $mimeType);
    }

    public function get(string $key): string
    {
        return $this->real->get($key);
    }

    public function localPath(string $key): ?string
    {
        // Null on purpose in every double: it forces the buffered read
        // path, so whatever a subclass changes about the bytes is actually
        // exercised instead of being streamed straight off the disk.
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

    public function copy(string $fromKey, string $toKey): void
    {
        $this->real->copy($fromKey, $toKey);
    }

    public function delete(string $key): void
    {
        $this->real->delete($key);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->real->deletePrefix($prefix);
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): \Core\Storage\Location\StorageListing
    {
        return $this->real->list($prefix, $cursor, $limit);
    }

    public function announcedChecksum(string $key): ?string
    {
        return $this->real->announcedChecksum($key);
    }

    public function directUrl(string $key, string $ttl = '+1 hour'): ?string
    {
        return $this->real->directUrl($key, $ttl);
    }

    public function stableDirectUrl(string $key): ?string
    {
        return $this->real->stableDirectUrl($key);
    }

    public function exists(string $key): bool
    {
        return $this->real->exists($key);
    }

    public function testConnection(): ?string
    {
        return $this->real->testConnection();
    }
}

/** Throws on the Nth put, having really written the ones before it. */
final class FailingPutBackend extends DecoratedLocalBackend
{
    private int $putCount = 0;

    public function __construct(LocalStorageBackend $real, private int $failOnPutNumber)
    {
        parent::__construct($real);
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        $this->putCount++;
        if ($this->putCount === $this->failOnPutNumber) {
            throw new \RuntimeException('Simulated destination write failure');
        }
        parent::put($key, $contents, $mimeType);
    }
}

/**
 * Writes honestly and reads back tampered bytes — a storage that corrupts
 * silently.
 *
 * EVERY read path reports the same corruption, not just get(): the handler
 * happens to verify through get() today, but a double that corrupted only
 * that one path would let a future switch to size()/getRange() pass this
 * test for the wrong reason — no mismatch detected because the double
 * stopped simulating one.
 */
/**
 * Deletes one key happily and refuses to prune a folder — the one failure
 * the migration's epilogue has to survive, and now to report (#484).
 */
final class RefusingToPruneBackend extends DecoratedLocalBackend
{
    public function deletePrefix(string $prefix): void
    {
        throw new \RuntimeException('Simulated cleanup failure on the source location');
    }
}

final class CorruptingReadBackend extends DecoratedLocalBackend
{
    public function get(string $key): string
    {
        return parent::get($key) . '-corrupted';
    }

    public function size(string $key): ?int
    {
        return strlen($this->get($key));
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        return substr($this->get($key), $offset, $length);
    }
}
