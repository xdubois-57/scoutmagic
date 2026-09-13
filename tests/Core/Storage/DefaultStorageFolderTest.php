<?php

declare(strict_types=1);

namespace Tests\Core\Storage;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Maintenance\BackupService;
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

    public function testAnArchiveToldToLeaveTheGalleryOutReallyLeavesTheDefaultFolderOut(): void
    {
        // The serious half of the same drift. `excludedArchivePrefixes()`
        // names this folder by hand: pointed at one that holds nothing,
        // the exclusion matches nothing, and a backup taken WITHOUT the
        // gallery silently carries every photo in it. Nothing fails — the
        // archive is produced, it is simply gigabytes larger than asked
        // for, which is only noticed by whoever is paying for the
        // destination.
        file_put_contents(
            $this->storagePath . '/' . StorageLocationService::DEFAULT_PATH . '/photo.jpg',
            str_repeat('x', 256 * 1024)
        );

        $service = new BackupService(
            new Connection('127.0.0.1', 3306, 'nonexistent_db', 'nobody', ''),
            $this->storagePath,
            dirname($this->storagePath)
        );

        $this->assertLessThan(
            $service->estimateFileBackupBytes(true),
            $service->estimateFileBackupBytes(false),
            'An archive told to exclude the gallery must be smaller than one that carries it. '
                . 'Equal sizes mean the exclusion is naming a folder the gallery no longer writes to.'
        );
    }
}
