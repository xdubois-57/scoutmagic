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

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');

        $this->storagePath = sys_get_temp_dir() . '/disk_budget_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    // ————— La mesure —————

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

    // ————— Le quota déclaré —————

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

    // ————— Le refus avant écriture —————

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

    // ————— Le cache de la mesure —————

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
