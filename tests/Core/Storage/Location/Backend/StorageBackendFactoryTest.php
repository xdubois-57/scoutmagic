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
        $this->expectExceptionMessage('adresse publique');

        $this->factory->create($this->webDavLocation($baseUrl));
    }

    /**
     * **And a host the resolver cannot answer for is not refused here.**
     * This check runs before every request that touches the share, so its
     * message is what the Emplacements page shows about the location. A
     * resolver blink would otherwise record « vérifiez l'adresse » against
     * a configuration that is perfectly good — and refusing buys nothing,
     * because the request about to be made cannot reach anything either.
     * What must be refused is an address that resolves somewhere it must
     * not be reached, which the cases above cover.
     */
    public function testAnAddressTheResolverCannotAnswerForIsNotCalledWrong(): void
    {
        // A name under the documentation domain: well-formed, public in
        // shape, and answering nothing.
        $backend = $this->factory->create(
            $this->webDavLocation('https://partage-inexistant.example.org/dav/scoutmagic')
        );

        $this->assertInstanceOf(\Core\Storage\Location\Backend\WebDavBackend::class, $backend);
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
        $this->assertSame('/etc/hosts', $backend->localPath('etc/hosts'));
    }

    public function testAnAbsolutePathThatClimbsIsNormalisedRatherThanRefused(): void
    {
        // The refusal below is about a RELATIVE path leaving `storage/`.
        // An absolute one is already « somewhere else » by construction —
        // there is nothing for it to climb out of — so it is resolved, not
        // rejected.
        $backend = $this->factory->create($this->localLocation(5, '/etc/../etc'));

        $this->assertSame('/etc/hosts', $backend->localPath('hosts'));
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
    /**
     * Issue #1129: a location saved before the rule knew layout B, or
     * arrived by a restore, is checked again when it is opened. A folder
     * the web server serves is not opened at all — nothing written there,
     * nothing already there touched.
     */
    public function testALocalFolderTheWebServerServesIsNotOpened(): void
    {
        // Layout B: the factory's storage/ sits in a project root carrying
        // the index.php stub next to public/.
        $root = $this->storagePath . '/install';
        mkdir($root . '/storage', 0700, true);
        mkdir($root . '/public', 0700, true);
        mkdir($root . '/photos', 0700, true);
        file_put_contents($root . '/index.php', '<?php');
        file_put_contents($root . '/public/index.php', '<?php');
        file_put_contents($root . '/photos/existante.jpg', 'octets');
        $repository = new StorageLocationRepository(
            DatabaseTestHelper::createTestDatabase(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $factory = new StorageBackendFactory($repository, $root . '/storage');

        try {
            $factory->create($this->localLocation(3, $root . '/photos'));
            $this->fail('A folder the web server serves was opened.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('servi directement par le serveur web', $e->getMessage());
        }

        $this->assertSame('octets', file_get_contents($root . '/photos/existante.jpg'));
        // Outside the served area, the same factory opens as before.
        $this->assertInstanceOf(
            LocalStorageBackend::class,
            $factory->create($this->localLocation(4, $root . '/storage/archives'))
        );
    }
    /**
     * A relative location is under `storage/`, which is protected — unless
     * a symbolic link there leads into a served directory (review of
     * #1197).
     */
    public function testARelativeLocationLinkedIntoAServedDirectoryIsNotOpened(): void
    {
        $root = $this->storagePath . '/install';
        mkdir($root . '/storage', 0700, true);
        mkdir($root . '/public', 0700, true);
        mkdir($root . '/photos', 0700, true);
        file_put_contents($root . '/index.php', '<?php');
        file_put_contents($root . '/public/index.php', '<?php');
        if (!@symlink($root . '/photos', $root . '/storage/gallery')) {
            $this->markTestSkipped('This filesystem does not allow creating a symbolic link.');
        }
        $repository = new StorageLocationRepository(
            DatabaseTestHelper::createTestDatabase(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $factory = new StorageBackendFactory($repository, $root . '/storage');

        $this->expectException(StorageLocationException::class);
        $this->expectExceptionMessage('servi directement par le serveur web');
        $factory->create($this->localLocation(5, 'gallery'));
    }

    /** The document root reported during a request counts too (review of #1197). */
    public function testALocationUnderTheReportedDocumentRootIsNotOpened(): void
    {
        $docRoot = $this->storagePath . '/htdocs';
        mkdir($docRoot, 0700, true);
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;

        try {
            $this->factory->create($this->localLocation(6, $docRoot . '/photos'));
            $this->fail('A folder under the reported document root was opened.');
        } catch (StorageLocationException $e) {
            $this->assertStringContainsString('servi directement par le serveur web', $e->getMessage());
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }
}
