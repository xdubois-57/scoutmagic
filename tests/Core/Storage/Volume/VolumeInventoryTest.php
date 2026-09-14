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

    /**
     * The same double-count, through the door the first test does not open:
     * a child declared BEFORE its parent.
     *
     * The de-duplication skips a directory nested inside one already
     * counted, so it depends entirely on a parent being seen first — and a
     * parent is never `isUnder()` its own child. `declaredDirectories()`
     * happens to seed `storage/` first, which is what makes the nested case
     * above pass; two arbitrary locations on one device have no such order.
     * The inflated occupation feeds `VolumeUsage::availableBytes()`, which
     * `DiskBudget::ensureRoomOn()` uses to refuse a write — this subsystem's
     * own defect, pointing the other way: refusing room that exists.
     */
    public function testANestedDirectoryIsNotCountedTwiceWhateverOrderItWasDeclaredIn(): void
    {
        $parent = $this->nasPath();
        $child = $parent . '/photos';
        mkdir($child, 0777, true);
        $this->writeBytes($child . '/photo.jpg', 4096);
        // The child first, the parent second — the order this method must
        // not depend on.
        $this->declareLocal('Photos', $child);
        $this->declareLocal('Disque réseau', $parent);

        $volumes = $this->inventory([
            $this->storagePath => '8',
            $child => '42',
            $parent => '42',
        ])->measure();

        $nas = $volumes[1];
        $this->assertSame('42', $nas->deviceId);
        $this->assertSame(
            4096,
            $nas->occupiedBytes,
            'The child is inside the parent, so its bytes are counted once whichever was declared first.'
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

    // ————— The screen and the enforcer answer the same question —————

    /**
     * **The invariant this file exists to protect, stated as one
     * assertion**: the primary volume must never promise more room than
     * `DiskBudget` will actually grant.
     *
     * They are computed from different walks — the screen sums the
     * DECLARED directories, the budget charges the whole installation
     * (`vendor/`, `core/`, `modules/`, `public/`) because the quota is the
     * hosting account's allowance — and while the screen used the narrow
     * one it read « il reste de la place » where the budget had less. An
     * administrator plans an import on the screen, and IT-02 removed the
     * Maintenance panel that used to show the wide figure.
     */
    public function testThePrimaryVolumeNeverPromisesMoreRoomThanTheBudgetGrants(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '100 Mo');
        // Under `storage/`, so both walks see it...
        $this->writeBytes($this->storagePath . '/gallery/photo.bin', 4 * self::MIB);
        // ...and beside it, inside the installation: the quota pays for
        // this too, and the declared directories do not contain it.
        $this->writeBytes($this->installPath . '/vendor/library.bin', 8 * self::MIB);

        $volumes = $this->inventory([$this->storagePath => '8'])->measure();
        $primary = $volumes[0];
        $budget = new DiskBudget($this->storagePath, $this->settings);

        $this->assertTrue($primary->isPrimary);
        $screenSaysFree = $primary->availableBytes();
        $budgetGrants = $budget->availableBytes();

        $this->assertNotNull($screenSaysFree);
        $this->assertNotNull($budgetGrants);
        $this->assertLessThanOrEqual(
            $budgetGrants,
            $screenSaysFree,
            'The screen promised more room than the budget grants — the direction that truncates a write.'
        );
    }

    /**
     * And the occupation it prints counts the installation, not just the
     * declared directories — otherwise the percentage on the card is a
     * different measurement from the refusal.
     */
    public function testThePrimaryVolumeOccupationUnderAQuotaCountsTheInstallation(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '100 Mo');
        $this->writeBytes($this->storagePath . '/gallery/photo.bin', 4 * self::MIB);
        $this->writeBytes($this->installPath . '/vendor/library.bin', 8 * self::MIB);

        $primary = $this->inventory([$this->storagePath => '8'])->measure()[0];

        $this->assertSame(VolumeUsage::BASIS_QUOTA, $primary->basis());
        $used = $primary->basisUsedBytes();
        $this->assertNotNull($used);
        $this->assertGreaterThanOrEqual(
            11 * self::MIB,
            $used,
            'The 8 MiB beside storage/ are on the allowance too, and were not being counted.'
        );
    }

    // ————— Absent, unreadable, and measured are three answers —————

    /**
     * A declared directory that is simply not there weighs nothing and is
     * SAID to weigh nothing-known — `exists` is false and the size is
     * null, never an occupation of zero.
     */
    public function testADeclaredDirectoryThatIsNotThereHasNoSizeRatherThanZero(): void
    {
        $gone = $this->nasPath() . '/montage-disparu';
        $this->declareLocal('Montage disparu', $gone);

        $volumes = $this->inventory([$this->storagePath => '8', $gone => '42'])->measure();
        $directory = $this->directoryNamed($volumes, $gone);

        $this->assertFalse($directory->exists, 'The mount is gone, and the screen says so.');
        $this->assertNull($directory->sizeBytes, 'Absent is unknown, never zero.');
    }

    /**
     * **The one that costs something when it is wrong.**
     * `DirectorySize::measure()` swallows an unreadable directory under
     * `DirectoryWalk::Measurement` and answers `0` — right for a page that
     * must not 500 over one folder's permissions, wrong as an occupation:
     * the volume then comes out short by exactly what nobody could see,
     * and under a declared quota `availableBytes()` turns that into
     * OVERSTATED room left, which is what lets a write be approved onto a
     * volume that cannot take it.
     *
     * Skipped as root rather than run as root: the permission bits do not
     * apply to uid 0, so `is_readable()` answers true whatever the mode and
     * this test would pass without exercising a single thing it asserts —
     * green for the wrong reason is worse than absent.
     */
    public function testADirectoryThatExistsButCannotBeReadHasNoSizeRatherThanZero(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: the permission bits this test turns on do not apply.');
        }

        $locked = $this->nasPath() . '/sans-permission';
        mkdir($locked, 0777, true);
        $this->writeBytes($locked . '/photo.bin', 4096);
        $this->declareLocal('Montage verrouillé', $locked);

        try {
            $this->assertTrue(chmod($locked, 0000), 'The test needs to be able to take the permissions away.');

            $volumes = $this->inventory([$this->storagePath => '8', $locked => '42'])->measure();
            $directory = $this->directoryNamed($volumes, $locked);

            $this->assertTrue($directory->exists, 'It IS there — that is what makes zero the wrong answer.');
            $this->assertNull(
                $directory->sizeBytes,
                'An unreadable directory has an unknown size, and reporting 0 overstates the room left.'
            );
        } finally {
            @chmod($locked, 0777);
        }
    }

    /** @param list<\Core\Storage\Volume\VolumeUsage> $volumes */
    private function directoryNamed(array $volumes, string $path): \Core\Storage\Volume\VolumeDirectory
    {
        foreach ($volumes as $volume) {
            foreach ($volume->directories as $directory) {
                if (rtrim($directory->path, '/') === rtrim($path, '/')) {
                    return $directory;
                }
            }
        }

        $this->fail('No declared directory named ' . $path . ' came back from the inventory.');
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

        // **Only the device ids are stubbed**, so the free space of the
        // NAS volume is whatever the machine running this really has —
        // and `ensureRoomOn()` wants 1 Kio plus DiskBudget's fixed safety
        // margin of it. On a small tmpfs this test would fail for a reason
        // that has nothing to do with what it asserts, which is that the
        // PRIMARY volume's quota does not reach here. Checked after the
        // 2 MiB written above, since that write comes out of the same
        // filesystem when sys_get_temp_dir() holds both.
        $reallyFree = @disk_free_space($nas);
        if (!is_float($reallyFree) || $reallyFree < 1024 + DiskBudget::SAFETY_MARGIN_BYTES) {
            $this->markTestSkipped(
                'The filesystem behind ' . $nas . ' has less free space than DiskBudget\'s own margin, '
                    . 'so its verdict here would say nothing about the quota.'
            );
        }

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
