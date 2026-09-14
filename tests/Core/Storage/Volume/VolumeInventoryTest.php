<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Volume;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Core\Storage\DiskBudget;
use Core\Storage\InsufficientDiskSpaceException;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Core\Storage\Volume\DeviceResolver;
use Core\Storage\Volume\VolumeInventory;
use Core\Storage\Volume\VolumeUsage;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The rule this whole class exists for: **free space is a property of a
 * filesystem, never of a folder**.
 *
 * The device numbers here come from a stub rather than from the kernel,
 * and that is deliberate — see {@see DeviceResolver}. A test of « two
 * devices are two volumes » needs two devices, a CI runner will not let a
 * test mount one, and writing it against whatever second filesystem the
 * machine happens to have is how it becomes a test that proves nothing on
 * the machine that has only one. What the kernel contributes is checked
 * separately, in {@see StatDeviceResolverTest}.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class VolumeInventoryTest extends TestCase
{
    private const MIB = 1024 * 1024;

    private \PDO $pdo;
    private SettingService $settings;
    private StorageLocationRepository $repository;
    private StorageLocationService $locations;
    private StorageBackendFactory $backends;
    private string $installPath;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');

        $this->installPath = sys_get_temp_dir() . '/scoutmagic-volumes-' . bin2hex(random_bytes(6));
        $this->storagePath = $this->installPath . '/storage';
        mkdir($this->storagePath, 0777, true);

        $this->repository = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->backends = new StorageBackendFactory($this->repository, $this->storagePath);
        $this->locations = new StorageLocationService(
            $this->repository,
            $this->backends,
            new StorageLocationConsumerRegistry()
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->installPath);
        $this->removeDirectory(sys_get_temp_dir() . '/scoutmagic-nas-' . getmypid());
    }

    // ————— The grouping —————

    /**
     * Three declared folders on one disk are ONE volume with one free-space
     * figure — not three rows each announcing the same terabytes, which is
     * how an administrator ends up believing in three times the room they
     * have.
     */
    public function testTwoDirectoriesOnTheSameDeviceAreOneVolume(): void
    {
        $photos = $this->storagePath . '/photos';
        $videos = $this->storagePath . '/videos';
        mkdir($photos);
        mkdir($videos);
        $this->declareLocal('Photos', $photos);
        $this->declareLocal('Vidéos', $videos);

        $volumes = $this->inventory([
            $this->storagePath => '8',
            $photos => '8',
            $videos => '8',
        ])->measure();

        $this->assertCount(1, $volumes, 'One device must produce one volume.');
        $this->assertSame('8', $volumes[0]->deviceId);
        $this->assertTrue($volumes[0]->isPrimary);
        $this->assertCount(3, $volumes[0]->directories, 'Every declared folder is still listed on it.');
    }

    /**
     * The same three folders on two disks are two volumes — and the one
     * carrying `storage/` is the primary, whatever the paths look like.
     */
    public function testDirectoriesOnDistinctDevicesAreDistinctVolumes(): void
    {
        $nas = $this->nasPath();
        $this->declareLocal('Disque réseau', $nas);

        $volumes = $this->inventory([
            $this->storagePath => '8',
            $nas => '42',
        ])->measure();

        $this->assertCount(2, $volumes);
        $this->assertTrue($volumes[0]->isPrimary, 'The primary volume is listed first.');
        $this->assertSame('8', $volumes[0]->deviceId);
        $this->assertFalse($volumes[1]->isPrimary);
        $this->assertSame('42', $volumes[1]->deviceId);
    }

    /**
     * A path the system will not place is not « the same unknown volume »
     * as another one. Merging two unknowns would add their occupations onto
     * a single free-space figure and under-report the room left — the one
     * direction in which being wrong costs a truncated write.
     */
    public function testTwoUnplaceableDirectoriesAreNeverMergedIntoOneVolume(): void
    {
        $first = $this->storagePath . '/first';
        $second = $this->storagePath . '/second';
        mkdir($first);
        mkdir($second);
        $this->declareLocal('Premier', $first);
        $this->declareLocal('Second', $second);

        $volumes = $this->inventory([
            $this->storagePath => '8',
            $first => null,
            $second => null,
        ])->measure();

        $this->assertCount(3, $volumes, 'Two unknowns stay two volumes, never one.');
    }

    /**
     * `storage/gallery` is inside `storage/`, and both are declared. Adding
     * the two would charge the gallery to the volume twice — the same
     * double-count this class exists to stop, one level down.
     */
    public function testANestedDirectoryIsNotCountedTwiceInTheVolumeOccupation(): void
    {
        $gallery = $this->storagePath . '/gallery';
        mkdir($gallery);
        $this->writeBytes($gallery . '/photo.jpg', 4096);
        $this->declareLocal('Galerie', $gallery);

        $volumes = $this->inventory([$this->storagePath => '8', $gallery => '8'])->measure();

        $this->assertCount(1, $volumes);
        $this->assertSame(
            4096,
            $volumes[0]->occupiedBytes,
            'The gallery is inside storage/, so its bytes are counted once, not twice.'
        );
    }

    /** An S3 location is on nobody's filesystem, and must not become a volume. */
    public function testAnObjectStorageLocationIsNotAVolume(): void
    {
        $this->repository->create(
            StorageLocationType::ObjectStorage,
            'Bucket',
            new ObjectStorageLocationConfig('https://s3.example.net', 'fr-par', 'photos', 'AKIA', null, 'scaleway'),
            'secret'
        );

        $volumes = $this->inventory([$this->storagePath => '8'])->measure();

        $this->assertCount(1, $volumes);
        $this->assertCount(1, $volumes[0]->directories);
    }

    // ————— Which measurement the screen announces —————

    /**
     * A declared quota wins over the system's figure, because that is
     * exactly the case where the system's figure is somebody else's disk —
     * and the screen has to say which of the two it used, since « 62 % »
     * means something different under each.
     */
    public function testADeclaredQuotaTakesPriorityOverTheSystemMeasurementOnThePrimaryVolume(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (4 * self::MIB));
        $this->writeBytes($this->storagePath . '/backup.zip', self::MIB);

        $primary = $this->inventory([$this->storagePath => '8'])->measure()[0];

        $this->assertSame(VolumeUsage::BASIS_QUOTA, $primary->basis());
        $this->assertSame(4 * self::MIB, $primary->basisTotalBytes());
        $this->assertSame(self::MIB, $primary->basisUsedBytes(), 'The quota is charged what the site occupies.');
        $this->assertSame(25, $primary->usedPercent());
        $this->assertStringContainsString('quota que vous avez déclaré', $primary->basisSentence());
    }

    /**
     * And that quota stops at the primary volume. It is the share a hosting
     * contract grants on the account's own disk; applying it to a mounted
     * NAS would report 900 Go of network storage as 4 Go.
     */
    public function testTheDeclaredQuotaDoesNotFollowOntoAnotherVolume(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (4 * self::MIB));
        $nas = $this->nasPath();
        $this->declareLocal('Disque réseau', $nas);

        $volumes = $this->inventory([$this->storagePath => '8', $nas => '42'])->measure();

        $this->assertSame(4 * self::MIB, $volumes[0]->declaredQuotaBytes);
        $this->assertNull($volumes[1]->declaredQuotaBytes);
        $this->assertSame(VolumeUsage::BASIS_VOLUME, $volumes[1]->basis());
        $this->assertStringContainsString('Mesure du système', $volumes[1]->basisSentence());
        $this->assertStringNotContainsString('Quota disque déclaré', $volumes[1]->basisSentence());
    }

    /** The screen's « ce dossier est hors de storage/ » warning, as a fact rather than a guess. */
    public function testAVolumeKnowsWhetherAnyOfItsDirectoriesEscapesStorage(): void
    {
        $nas = $this->nasPath();
        $this->declareLocal('Disque réseau', $nas);

        $volumes = $this->inventory([$this->storagePath => '8', $nas => '42'])->measure();

        $this->assertFalse($volumes[0]->hasDirectoryOutsideStorage());
        $this->assertTrue($volumes[1]->hasDirectoryOutsideStorage());
    }

    // ————— The refusal that follows the write —————

    /**
     * The latent defect IT-02 came to fix: a write destined for a mounted
     * disk used to be approved against the system disk's free space. Here
     * the quota leaves nothing at all on the primary volume, and a write to
     * the NAS must still be approved — it is not landing there.
     */
    public function testEnsureRoomOnChargesTheWriteToTheVolumeItWillLandOn(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '1');
        $this->writeBytes($this->storagePath . '/already-full.bin', 2 * self::MIB);
        $nas = $this->nasPath();
        $this->declareLocal('Disque réseau', $nas);

        $inventory = $this->inventory([$this->storagePath => '8', $nas => '42']);
        $budget = new DiskBudget($this->storagePath, $this->settings);

        // The primary volume is over its declared quota, so a write there
        // is refused...
        $this->expectException(InsufficientDiskSpaceException::class);
        $budget->ensureRoomOn($inventory, $this->storagePath, 1024);
    }

    public function testAWriteToAnotherVolumeIsNotRefusedByThePrimaryVolumesQuota(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '1');
        $this->writeBytes($this->storagePath . '/already-full.bin', 2 * self::MIB);
        $nas = $this->nasPath();
        $this->declareLocal('Disque réseau', $nas);

        $inventory = $this->inventory([$this->storagePath => '8', $nas => '42']);
        $budget = new DiskBudget($this->storagePath, $this->settings);

        // ...while the same write to the mounted disk goes through, because
        // the contract that quota comes from says nothing about that disk.
        $budget->ensureRoomOn($inventory, $nas, 1024);

        $this->assertTrue(true, 'No refusal: the NAS has room and the quota does not cover it.');
    }

    /**
     * A destination on no volume anybody declared still gets a verdict:
     * falling back on the primary is a guess, and a guess beats approving
     * a write against nothing at all.
     */
    public function testAnUnknownDestinationFallsBackOnThePrimaryVolume(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '1');
        $this->writeBytes($this->storagePath . '/already-full.bin', 2 * self::MIB);

        $inventory = $this->inventory([$this->storagePath => '8']);
        $budget = new DiskBudget($this->storagePath, $this->settings);

        $this->expectException(InsufficientDiskSpaceException::class);
        $budget->ensureRoomOn($inventory, '/somewhere/nobody/declared', 1024);
    }

    // ————— Helpers —————

    /** @param array<string, string|null> $devices resolved path => device id */
    private function inventory(array $devices): VolumeInventory
    {
        return new VolumeInventory(
            $this->storagePath,
            $this->settings,
            $this->locations,
            $this->backends,
            new class ($devices) implements DeviceResolver {
                /** @param array<string, string|null> $devices */
                public function __construct(private array $devices)
                {
                }

                public function deviceIdOf(string $path): ?string
                {
                    return $this->devices[rtrim($path, '/')] ?? null;
                }
            }
        );
    }

    private function declareLocal(string $label, string $absolutePath): void
    {
        $this->repository->create(
            StorageLocationType::Local,
            $label,
            new LocalLocationConfig($absolutePath),
            null
        );
    }

    /** A directory standing in for a mounted disk: outside `storage/`, and outside the installation. */
    private function nasPath(): string
    {
        $path = sys_get_temp_dir() . '/scoutmagic-nas-' . getmypid();
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    private function writeBytes(string $path, int $bytes): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, str_repeat('x', $bytes));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                @unlink((string) $item);
                continue;
            }
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }
}
