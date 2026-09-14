<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Maintenance\BackupService;
use Core\Storage\Location\DeclaredStorageDirectories;
use Core\Storage\DiskBudget;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\StorageUsage;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Three readings of « the folder the gallery writes to by default », held
 * together.
 *
 * They are three because the concept arrived in three places at different
 * times: `StorageLocationService::DEFAULT_PATH` is where a fresh install
 * creates its location, `Core\Maintenance\BackupService` excludes that
 * folder from an archive told not to carry the photos, and
 * `Core\Storage\DiskBudget` measures it as the gallery's share of the
 * disk rather than as « divers ».
 *
 * Changing the constant alone left the other two naming a directory that
 * holds nothing — a backup silently including the gigabytes it was told
 * to leave out, and a breakdown reporting the gallery as empty. Neither
 * fails anything: the archive is produced, the page renders, and the
 * numbers are simply wrong.
 *
 * This test is what makes that a failure. Both assertions are
 * behavioural — they write real bytes under the folder the constant
 * names and look at what each component does with them — so they follow
 * the constant wherever it goes, and fail the day only one of the three
 * follows it.
 */
class DefaultStorageFolderTest extends TestCase
{
    private string $storagePath;
    private SettingService $settings;

    protected function setUp(): void
    {
        // `storage/` inside an installation root of its own: DiskBudget
        // charges a declared quota for the whole installation, so a
        // `storage/` whose parent is the system temp directory would be
        // measured against everything else on the machine.
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-default-folder-' . bin2hex(random_bytes(6))
            . '/storage';
        mkdir($this->storagePath . '/' . StorageLocationService::DEFAULT_PATH, 0777, true);
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        $this->settings->register(DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');
    }

    protected function tearDown(): void
    {
        $root = dirname($this->storagePath);
        if (!is_dir($root)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($root);
    }

    public function testTheDiskBudgetCountsTheDefaultFolderAsTheGallerysShare(): void
    {
        file_put_contents(
            $this->storagePath . '/' . StorageLocationService::DEFAULT_PATH . '/photo.jpg',
            str_repeat('x', 4096)
        );

        $usage = (new DiskBudget($this->storagePath, $this->settings))->measureNow();

        $this->assertGreaterThanOrEqual(
            4096,
            $usage->breakdown[StorageUsage::AREA_GALLERY] ?? 0,
            'The bytes under the default storage folder must be reported as the gallery\'s share. '
                . 'Reported as « divers » instead means DiskBudget is measuring a folder that no longer '
                . 'holds anything, because the constant moved without it.'
        );
    }

    public function testTheDefaultLocationConfigNamesTheSameFolderAsTheService(): void
    {
        // A record with no path at all — written by a version that did not
        // store one, or truncated. It must resolve to the folder that
        // holds the files, not to a tidier name that holds none.
        $this->assertSame(
            StorageLocationService::DEFAULT_PATH,
            LocalLocationConfig::fromArray([])->path
        );
    }

    public function testTheDefaultFolderIsRelativeSoItHangsUnderStorage(): void
    {
        // An absolute default would put a fresh installation's photos
        // outside `storage/`, where a reset cannot reach them and no
        // archive expects them.
        $this->assertFalse((new LocalLocationConfig(StorageLocationService::DEFAULT_PATH))->isAbsolute());
        $this->assertSame(
            'storage/' . StorageLocationService::DEFAULT_PATH,
            (new LocalLocationConfig(StorageLocationService::DEFAULT_PATH))->describe()
        );
    }

    /**
     * The serious half of the same drift, restated for D10.
     *
     * The exclusion no longer names this folder by hand — it comes from
     * the location itself — so the OLD failure (a constant pointing at a
     * folder the gallery no longer writes to) cannot happen in that
     * spelling any more. The equivalent one can: if
     * `StorageBackendFactory::localDirectoryFor()` resolved the DEFAULT
     * location to anywhere but the folder the gallery actually writes to,
     * the prefix would match nothing and every photo would silently
     * travel in every archive. Nothing would fail — the archive is
     * produced, it is simply gigabytes larger than it should be, which is
     * only noticed by whoever is paying for the destination.
     *
     * So this declares the default location the way a fresh installation
     * does, and checks the photo is gone from the estimate.
     */
    public function testDeclaringTheDefaultLocationReallyKeepsItsFolderOutOfAnArchive(): void
    {
        file_put_contents(
            $this->storagePath . '/' . StorageLocationService::DEFAULT_PATH . '/photo.jpg',
            str_repeat('x', 256 * 1024)
        );

        $connection = new Connection('127.0.0.1', 3306, 'nonexistent_db', 'nobody', '');
        $undeclared = new BackupService($connection, $this->storagePath, dirname($this->storagePath));
        $declared = new BackupService(
            $connection,
            $this->storagePath,
            dirname($this->storagePath),
            null,
            DeclaredStorageDirectories::fromPaths([
                $this->storagePath . '/' . StorageLocationService::DEFAULT_PATH,
            ])
        );

        $this->assertSame(
            $undeclared->estimateFileBackupBytes() - 256 * 1024,
            $declared->estimateFileBackupBytes(),
            'Declaring the default location must remove exactly its folder from the archive. '
                . 'An unchanged size means the prefix is naming a folder the gallery no longer writes to.'
        );
    }
}
