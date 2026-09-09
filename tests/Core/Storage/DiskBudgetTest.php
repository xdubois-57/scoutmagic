<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Storage\DiskBudget;
use Core\Storage\InsufficientDiskSpaceException;
use Core\Storage\StorageUsage;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DiskBudgetTest extends TestCase
{
    private const MIB = 1024 * 1024;

    private \PDO $pdo;
    private SettingService $settings;
    private string $storagePath;
    /** The installation root — `storage/`'s parent, which a declared quota also pays for. */
    private string $installPath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');

        // `storage/` nested inside an installation root of its own, never
        // directly under the system temp directory: `DiskBudget` charges a
        // declared quota for the whole installation, so a `storage/` whose
        // parent is `/tmp` would be measured against everything else on the
        // machine.
        $this->installPath = sys_get_temp_dir() . '/disk_budget_test_' . uniqid();
        $this->storagePath = $this->installPath . '/storage';
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->installPath);
    }

    // ————— The measurement —————

    public function testTheBreakdownSplitsGalleryBackupsAndTempOutOfTheRest(): void
    {
        $this->write('gallery/2026/photo.jpg', 600);
        $this->write('maintenance/backup.zip', 300);
        $this->write('temp/scratch', 90);
        $this->write('receipts/invoice.pdf', 10);

        $usage = $this->budget()->measureNow();

        $this->assertSame(1000, $usage->storageBytes);
        $this->assertSame(600, $usage->breakdown[StorageUsage::AREA_GALLERY]);
        $this->assertSame(300, $usage->breakdown[StorageUsage::AREA_BACKUPS]);
        $this->assertSame(90, $usage->breakdown[StorageUsage::AREA_TEMP]);
        $this->assertSame(10, $usage->breakdown[StorageUsage::AREA_OTHER]);
    }

    /**
     * The remainder bucket is computed, never enumerated: a module that
     * adds a storage directory tomorrow has to show up somewhere rather
     * than fall out of the total.
     */
    public function testAnUnknownStorageDirectoryLandsInTheRemainderRatherThanVanishing(): void
    {
        $this->write('a_module_nobody_has_written_yet/file.bin', 4096);

        $usage = $this->budget()->measureNow();

        $this->assertSame(4096, $usage->storageBytes);
        $this->assertSame(4096, $usage->breakdown[StorageUsage::AREA_OTHER]);
    }

    public function testStorageSizeDoesNotFollowSymbolicLinks(): void
    {
        $this->write('receipts/invoice.pdf', 100);

        $outside = sys_get_temp_dir() . '/disk_budget_outside_' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/huge.bin', str_repeat('x', 9000));

        if (!@symlink($outside . '/huge.bin', $this->storagePath . '/receipts/linked.bin')) {
            $this->markTestSkipped('This filesystem does not support symbolic links.');
        }

        try {
            $this->assertSame(100, $this->budget()->measureNow()->storageBytes);
        } finally {
            @unlink($this->storagePath . '/receipts/linked.bin');
            $this->removeDirectory($outside);
        }
    }

    // ————— The declared quota —————

    public function testTheDeclaredQuotaIsReadInWhicheverUnitTheAdminWroteIt(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '10 Go');

        $this->assertSame(10 * 1024 * self::MIB, $this->budget()->declaredQuotaBytes());
    }

    public function testNoDeclaredQuotaIsNullRatherThanZero(): void
    {
        $this->assertNull($this->budget()->declaredQuotaBytes());
    }

    public function testAnUnreadableQuotaIsTreatedAsNotStated(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, 'beaucoup');

        $this->assertNull($this->budget()->declaredQuotaBytes());
    }

    /**
     * The declared quota is the account's real share; the volume is the
     * host's whole disk. When both are known the quota is what the
     * percentage is computed on — that is the entire point of asking for
     * it.
     */
    public function testADeclaredQuotaTakesPrecedenceOverTheVolume(): void
    {
        $this->write('gallery/photo.jpg', 512);
        $this->settings->set(DiskBudget::QUOTA_SETTING, '1024');

        $usage = $this->budget()->measureNow();

        $this->assertSame(StorageUsage::BASIS_QUOTA, $usage->basis());
        $this->assertSame(1024, $usage->totalBytes());
        $this->assertSame(50, $usage->usedPercent());
    }

    public function testWithoutADeclaredQuotaTheVolumeIsTheDocumentedFallback(): void
    {
        $usage = $this->budget()->measureNow();

        // The container this suite runs in has a real filesystem, so the
        // volume answers and the fallback is the volume rather than
        // "unknown". What matters is that the absence of a quota is not an
        // error and not a refusal.
        $this->assertNotSame(StorageUsage::BASIS_QUOTA, $usage->basis());
        $this->assertNull($usage->declaredQuotaBytes);
    }

    // ————— The refusal, before the write —————

    public function testAWriteThatWouldNotFitIsRefusedBeforeItStarts(): void
    {
        $this->write('gallery/photo.jpg', 8 * self::MIB);
        $this->settings->set(DiskBudget::QUOTA_SETTING, '10 Mo');

        $this->expectException(InsufficientDiskSpaceException::class);
        $this->budget()->ensureRoom(5 * self::MIB);
    }

    public function testTheRefusalSaysHowMuchIsMissing(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '100 Mo');

        try {
            $this->budget()->ensureRoom(200 * self::MIB);
            $this->fail('Expected the write to be refused.');
        } catch (InsufficientDiskSpaceException $e) {
            $this->assertStringContainsString('Il manque', $e->getMessage());
            // 200 needed + 50 of margin, 100 available → 150 missing.
            $this->assertStringContainsString('150 Mo', $e->getMessage());
        }
    }

    public function testAWriteThatFitsIsAccepted(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, '500 Mo');

        $this->budget()->ensureRoom(100 * self::MIB);

        $this->assertTrue(true, 'ensureRoom() returned without refusing.');
    }

    /**
     * The margin is not decoration: an estimate that lands exactly on the
     * remaining space is the one that truncates, because an estimate is a
     * prediction and a filesystem is not.
     */
    public function testTheSafetyMarginIsRequiredOnTopOfTheEstimate(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (100 * self::MIB));

        // Exactly the quota, with nothing left for the margin.
        $this->expectException(InsufficientDiskSpaceException::class);
        $this->budget()->ensureRoom(100 * self::MIB);
    }

    /**
     * A host that declares no quota and reports no volume has not told us
     * the disk is full. Refusing every write there would break the backups
     * this whole guard exists to protect — so "nothing known" returns, and
     * the screen says which measurement it could not make.
     */
    public function testAnUnknownBudgetDoesNotRefuseTheWrite(): void
    {
        $budget = new DiskBudget($this->storagePath . '/gone', $this->settings);

        $budget->ensureRoom(PHP_INT_MAX >> 2);

        $this->assertNull($budget->availableBytes());
    }

    // ————— A reading pinned by the caller —————

    /**
     * `ensureRoomAgainst()` judges against the reading it is handed and
     * takes none of its own — which is the whole point for a caller whose
     * write spans several requests and shrinks the live figure as it goes.
     */
    public function testARefusalCanBeJudgedAgainstAReadingTheCallerPinned(): void
    {
        // A budget with room to spare: a fresh reading would accept this.
        $this->write('gallery/photo.jpg', 100);
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (100 * 1024 * 1024 * 1024));
        $budget = $this->budget();

        $this->expectException(InsufficientDiskSpaceException::class);
        $budget->ensureRoomAgainst(1024, DiskBudget::SAFETY_MARGIN_BYTES);
    }

    public function testAPinnedReadingThatCoversTheEstimateAndTheMarginIsAccepted(): void
    {
        $budget = $this->budget();

        $budget->ensureRoomAgainst(1024, DiskBudget::SAFETY_MARGIN_BYTES + 1024);

        $this->addToAssertionCount(1);
    }

    /** A pinned null is still « nothing known », and still returns. */
    public function testAPinnedNullDoesNotRefuseTheWrite(): void
    {
        $budget = $this->budget();

        $budget->ensureRoomAgainst(PHP_INT_MAX >> 2, null);

        $this->addToAssertionCount(1);
    }

    // ————— The cached measurement —————

    public function testTheMeasurementIsReusedWithinItsLifetime(): void
    {
        $this->write('gallery/photo.jpg', 100);
        $budget = $this->budget();
        $this->assertSame(100, $budget->measureNow()->storageBytes);

        // A new file the cached reading knows nothing about.
        $this->write('gallery/second.jpg', 900);

        $this->assertSame(100, $budget->measure()->storageBytes, 'measure() should reuse the cached walk');
        $this->assertSame(1000, $budget->measureNow()->storageBytes, 'measureNow() should always walk');
    }

    /**
     * An admin who has just typed their quota expects the next page to
     * reflect it, not the one fifteen minutes later.
     */
    public function testChangingTheQuotaInvalidatesTheCachedReading(): void
    {
        $this->write('gallery/photo.jpg', 512);
        $budget = $this->budget();
        $budget->measureNow();

        $this->settings->set(DiskBudget::QUOTA_SETTING, '1024');

        $this->assertSame(1024, $budget->measure()->declaredQuotaBytes);
        $this->assertSame(50, $budget->measure()->usedPercent());
    }

    public function testAnUnreadableCacheFileIsIgnoredRatherThanFatal(): void
    {
        $this->write('gallery/photo.jpg', 42);
        $budget = $this->budget();
        $budget->measureNow();

        file_put_contents($this->storagePath . '/core/disk-usage.json', 'not json at all');

        $this->assertSame(42, $budget->measure()->storageBytes);
    }

    // ————— Approvals granted against a cached reading —————

    /**
     * Two SEPARATE writes inside one cache window must not both be
     * approved against the occupancy from before the first of them.
     *
     * The cached walk is what makes `ensureRoom()` cheap, and nothing
     * invalidates it when bytes land — so a backup followed by another a
     * few minutes later, or two gallery uploads, read the same figure. The
     * fixed safety margin covers one such gap, not a number of them that
     * grows with the traffic.
     */
    public function testApprovalsWorthTheSafetyMarginForceTheNextReadingToWalkAgain(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (2048 * self::MIB));
        $budget = $this->budget();

        $budget->measureNow();
        $this->assertFileExists($this->cacheFile(), 'La mesure doit être en cache pour que le test ait un sujet.');

        // Well under the margin: the reading still stands.
        $budget->ensureRoom(DiskBudget::SAFETY_MARGIN_BYTES - 2 * self::MIB);
        $this->assertFileExists($this->cacheFile());

        // Crossing it drops the reading, so the next question walks.
        $budget->ensureRoom(4 * self::MIB);
        $this->assertFileDoesNotExist($this->cacheFile());
    }

    /** Counting the same bytes twice costs a walk, never a refusal. */
    public function testTheCountOnlyEverDecidesWhenToWalkAgain(): void
    {
        $this->write('gallery/photo.jpg', 100);
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (2048 * self::MIB));
        $budget = $this->budget();

        $budget->measureNow();
        $budget->ensureRoom(DiskBudget::SAFETY_MARGIN_BYTES);

        // The cache is gone, but the answer is unchanged: the count is not
        // subtracted from anything.
        $this->assertFileDoesNotExist($this->cacheFile());
        $this->assertSame(2048 * self::MIB - 100, $budget->availableBytes());
    }

    /** Rewriting the counter must not restart the reading's own lifetime. */
    public function testNotingAWriteDoesNotRejuvenateTheCachedReading(): void
    {
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (2048 * self::MIB));
        $budget = $this->budget();
        $budget->measureNow();

        $before = json_decode((string) file_get_contents($this->cacheFile()), true);
        $budget->notePendingWrite(self::MIB);
        $after = json_decode((string) file_get_contents($this->cacheFile()), true);

        $this->assertSame($before['measured_at_unix'], $after['measured_at_unix']);
        $this->assertSame(self::MIB, $after['pending_bytes']);
    }

    /**
     * Without a declared quota the figure is `disk_free_space()`, read live
     * on every call — it already reflects every byte written, and counting
     * approvals on top would be the double-count this avoids.
     */
    public function testWithoutADeclaredQuotaNothingIsCounted(): void
    {
        $budget = $this->budget();
        $budget->measureNow();

        $budget->ensureRoom(10 * DiskBudget::SAFETY_MARGIN_BYTES);

        $this->assertFileExists($this->cacheFile());
    }

    private function cacheFile(): string
    {
        return $this->storagePath . '/core/disk-usage.json';
    }

    // ————— What the declared quota really pays for —————

    /**
     * A declared quota is the HOSTING ACCOUNT's allowance, « tel qu'il
     * figure sur votre contrat » — so it pays for `vendor/` and the code
     * too, not only for `storage/`.
     *
     * Charging it for `storage/` alone over-reported the room left by the
     * whole application footprint — a couple of hundred megabytes in this
     * project, several times the 50 MiB safety margin — in the one
     * direction that lets a write truncate.
     */
    public function testTheDeclaredQuotaIsChargedForTheWholeInstallationNotOnlyStorage(): void
    {
        $this->write('gallery/photo.jpg', 100);
        $this->writeOutsideStorage('vendor/library.php', 900);
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (10 * self::MIB));

        $usage = $this->budget()->measureNow();

        $this->assertSame(100, $usage->storageBytes);
        $this->assertSame(1000, $usage->installBytes);
        $this->assertSame(1000, $usage->usedBytes(), 'Le quota paie aussi le code et vendor/.');
        $this->assertSame(10 * self::MIB - 1000, $usage->availableBytes());
    }

    /** The application's share is a line of the breakdown, not a hidden difference. */
    public function testTheApplicationFootprintIsShownAsItsOwnLine(): void
    {
        $this->write('gallery/photo.jpg', 100);
        $this->writeOutsideStorage('vendor/library.php', 900);

        $usage = $this->budget()->measureNow();

        $this->assertSame(900, $usage->breakdown[StorageUsage::AREA_APPLICATION]);
        $this->assertContains('application et bibliothèques 900 o', $usage->breakdownLabels());
    }

    /** And `ensureRoom()` refuses on that figure, not on the `storage/` one. */
    public function testAWriteIsRefusedOnTheInstallationFigureRatherThanTheStorageOne(): void
    {
        $this->writeOutsideStorage('vendor/library.php', 5 * self::MIB);
        // Room for the margin and a megabyte — but only if vendor/ is free,
        // which it is not.
        $this->settings->set(DiskBudget::QUOTA_SETTING, (string) (DiskBudget::SAFETY_MARGIN_BYTES + 4 * self::MIB));

        $this->expectException(InsufficientDiskSpaceException::class);
        $this->budget()->ensureRoom(self::MIB);
    }

    /** A reading cached before this field existed falls back on `storage/`. */
    public function testACachedReadingWithoutAnInstallationFigureFallsBackOnStorage(): void
    {
        $usage = new StorageUsage(
            storageBytes: 500,
            breakdown: [],
            declaredQuotaBytes: 1000,
            volumeFreeBytes: null,
            volumeTotalBytes: null,
            measuredAt: '2026-01-01 00:00:00'
        );

        $this->assertSame(500, $usage->quotaChargedBytes());
        $this->assertSame(500, $usage->availableBytes());
    }

    private function budget(): DiskBudget
    {
        return new DiskBudget($this->storagePath, $this->settings);
    }

    private function write(string $relativePath, int $bytes): void
    {
        $path = $this->storagePath . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, str_repeat('x', $bytes));
    }

    /** Somewhere in the installation but outside `storage/` — `vendor/`, the code. */
    private function writeOutsideStorage(string $relativePath, int $bytes): void
    {
        $path = $this->installPath . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
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
