<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\Backend\WebDav\WebDavAccessException;
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

    private function webDavLocation(string $baseUrl): StorageLocation
    {
        return new StorageLocation(
            id: 9,
            type: StorageLocationType::WebDav,
            label: 'Partage',
            config: new WebDavLocationConfig($baseUrl, 'unite'),
            isDefault: false,
            secretConfigured: true,
            lastCheckedAt: null,
            lastCheckOk: null,
            lastCheckError: null,
            createdAt: '2026-01-01 00:00:00'
        );
    }

    /**
     * **The stored address is checked again here, not only when it was
     * saved.** A username and a password travel on it in an
     * `Authorization` header on every single request, and the row it comes
     * from can have arrived since the form: a restore from another
     * installation, a hand-edited column. Refused before a single byte of
     * the credential leaves this server (SECURITY.md §17).
     *
     * @return list<array{string}>
     */
    public static function addressesNoCredentialMayTravelTo(): array
    {
        return [
            'plain http' => ['http://cloud.example.org/dav'],
            'the loopback interface' => ['https://127.0.0.1/dav'],
            'a private range' => ['https://10.0.0.5/dav'],
            'the cloud metadata address' => ['https://169.254.169.254/dav'],
            'nothing at all' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressesNoCredentialMayTravelTo')]
    public function testAStoredWebDavAddressIsRefusedAgainBeforeTheCredentialTravels(string $baseUrl): void
    {
        $this->expectException(WebDavAccessException::class);
        $this->expectExceptionMessage('https publique');

        $this->factory->create($this->webDavLocation($baseUrl));
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
