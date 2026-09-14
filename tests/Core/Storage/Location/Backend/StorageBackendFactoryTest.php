<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Where a local location actually is — the one place the relative/absolute
 * rule lives, so it is the one place that can get it wrong for everybody.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StorageBackendFactoryTest extends TestCase
{
    private StorageBackendFactory $factory;
    private string $storagePath;

    protected function setUp(): void
    {
        $repository = new StorageLocationRepository(
            DatabaseTestHelper::createTestDatabase(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-factory-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/gallery', 0700, true);
        $this->factory = new StorageBackendFactory($repository, $this->storagePath);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isLink() || !$item->isDir() ? @unlink((string) $item) : @rmdir((string) $item);
        }
        @rmdir($this->storagePath);
    }

    private function localLocation(int $id, string $path): StorageLocation
    {
        return new StorageLocation(
            id: $id,
            type: StorageLocationType::Local,
            label: 'Emplacement',
            config: new LocalLocationConfig($path),
            isDefault: true,
            secretConfigured: false,
            lastCheckedAt: null,
            lastCheckOk: null,
            lastCheckError: null,
            createdAt: '2026-01-01 00:00:00'
        );
    }

    public function testARelativePathHangsUnderTheSitesStorageFolder(): void
    {
        $backend = $this->factory->create($this->localLocation(1, 'gallery'));
        $backend->put('7/a.jpg', 'bytes', 'image/jpeg');

        $this->assertInstanceOf(LocalStorageBackend::class, $backend);
        $this->assertSame($this->storagePath . '/gallery/7/a.jpg', $backend->localPath('7/a.jpg'));
    }

    public function testAnAbsolutePathIsTakenAsItStandsRatherThanHungUnderTheStorageFolder(): void
    {
        // How a network mount or a second volume is reached, and the only
        // spelling that says « somewhere outside storage/ » out loud.
        $elsewhere = $this->storagePath . '/ailleurs';
        mkdir($elsewhere, 0700, true);

        $backend = $this->factory->create($this->localLocation(2, $elsewhere . '/'));
        $backend->put('7/a.jpg', 'bytes', 'image/jpeg');

        $this->assertSame($elsewhere . '/7/a.jpg', $backend->localPath('7/a.jpg'));
    }

    public function testAnAbsolutePathOfNothingButSlashesStaysTheFilesystemRoot(): void
    {
        // rtrim() alone turns `//` into the empty string, and a base of ``
        // makes every key read as an escape from it.
        $backend = $this->factory->create($this->localLocation(3, '//'));

        // A key that reaches a real file proves the base survived: with an
        // empty base every key is refused as an escape from it.
        $this->assertSame('/etc/hostname', $backend->localPath('etc/hostname'));
    }

    public function testAnAbsolutePathThatClimbsIsNormalisedRatherThanRefused(): void
    {
        // The refusal below is about a RELATIVE path leaving `storage/`.
        // An absolute one is already « somewhere else » by construction —
        // there is nothing for it to climb out of — so it is resolved, not
        // rejected.
        $backend = $this->factory->create($this->localLocation(5, '/etc/../etc'));

        $this->assertSame('/etc/hostname', $backend->localPath('hostname'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingRelativePaths(): array
    {
        return [
            'straight out' => ['../public'],
            'out and back' => ['gallery/../../public/uploads'],
            'nothing but a hop' => ['..'],
        ];
    }

    /**
     * A relative path is relative, and `../public` is not. Accepting it
     * would put every rendition in the web root — files served without
     * passing `FileAccessGuard`; normalising it away would silently
     * reinterpret what an administrator typed. Wanting a directory outside
     * `storage/` is legitimate, and is spelled as an absolute path.
     *
     * @dataProvider escapingRelativePaths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('escapingRelativePaths')]
    public function testARelativePathThatClimbsOutOfTheStorageFolderIsRefused(string $path): void
    {
        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessageMatches('/chemin relatif sans/');

        $this->factory->create($this->localLocation(4, $path));
    }
}
