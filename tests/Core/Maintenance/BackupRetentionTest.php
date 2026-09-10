<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Maintenance\Backup;
use Core\Maintenance\BackupFamily;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupRetention;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Retention per family, and the one code path that removes a backup.
 *
 * The behaviour these pin is the reason families exist: a single ordered
 * list capped at five let three consecutive updates evict the full backup
 * an administrator had taken five minutes earlier, because the automatic
 * ones outnumber the deliberate ones and the ordered list always kept the
 * noise.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BackupRetentionTest extends TestCase
{
    private \PDO $pdo;
    private BackupRepository $backups;
    private FileRepository $files;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->backups = new BackupRepository($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/retention_' . uniqid();
        @mkdir($this->storagePath . '/maintenance', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/maintenance/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->storagePath . '/maintenance');
        @rmdir($this->storagePath);
    }

    /**
     * The defect that motivated the whole iteration, stated as a test:
     * four updates in a row must not touch the manual backup somebody
     * made on purpose.
     */
    public function testAFamilyEvictsItsOwnAndNobodyElses(): void
    {
        $manual = $this->completed('full_no_gallery');
        $operational = [];
        for ($i = 0; $i < 4; $i++) {
            $operational[] = $this->completed('auto_update');
            $this->retention()->purgeAfterCreating('auto_update');
        }

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());

        $this->assertContains($manual, $surviving, 'Four automatic backups must not evict a deliberate one.');
        $this->assertNotContains($operational[0], $surviving, 'The oldest of the family DID have to go.');
        $this->assertCount(1 + BackupFamily::Operational->defaultQuota(), $surviving);
    }

    public function testEachFamilyKeepsItsOwnQuota(): void
    {
        foreach (['database', 'auto_backup', 'auto_reset'] as $type) {
            for ($i = 0; $i < 5; $i++) {
                $this->completed($type);
                $this->retention()->purgeAfterCreating($type);
            }
        }

        $byFamily = [];
        foreach ($this->backups->findAllNewestFirst() as $backup) {
            $family = BackupFamily::tryFromType($backup->type);
            $byFamily[$family?->value ?? '?'] = ($byFamily[$family?->value ?? '?'] ?? 0) + 1;
        }

        ksort($byFamily);
        $this->assertSame(['manual' => 3, 'operational' => 3, 'scheduled' => 3], $byFamily);
    }

    /**
     * The cross-family cap, and the reason it is not a family quota: one
     * gallery archive can weigh more than every other backup on the disk
     * put together, so a second one is the single most expensive thing
     * retention can allow.
     */
    public function testOnlyOneGalleryArchiveSurvivesAcrossAllFamilies(): void
    {
        $old = $this->completed('full_with_gallery');
        $recent = $this->completed('full_with_gallery');

        // Triggered by a SCHEDULED creation: the cap reads every family,
        // which is what "transversal" has to mean to be worth anything.
        $this->completed('auto_backup');
        $this->retention()->purgeAfterCreating('auto_backup');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertNotContains($old, $surviving);
        $this->assertContains($recent, $surviving);
    }

    /** A row owns two files, and forgetting the dump leaves an orphan only FTP can reach. */
    public function testBothOfABackupsFilesLeaveTheDiskAndTheTable(): void
    {
        $id = $this->completed('database', archive: 'a.zip', dump: 'a.sql');
        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);

        $this->retention()->forget($backup);

        $this->assertFileDoesNotExist($this->storagePath . '/maintenance/a.zip');
        $this->assertFileDoesNotExist($this->storagePath . '/maintenance/a.sql');
        $this->assertNull($this->files->findById((int) $backup->fileId));
        $this->assertNull($this->files->findById((int) $backup->dbDumpFileId));
        $this->assertNull($this->backups->findById($id));
    }

    /**
     * A file already gone is the ordinary case, not an edge one — a purge
     * that half-succeeded, an archive removed over FTP. Leaving the row
     * would leave a line offering the download of nothing.
     */
    public function testAMissingFileDoesNotStopTheRowFromGoing(): void
    {
        $id = $this->completed('database', archive: 'gone.zip');
        $backup = $this->backups->findById($id);
        $this->assertNotNull($backup);
        unlink($this->storagePath . '/maintenance/gone.zip');

        $this->retention()->forget($backup);

        $this->assertNull($this->backups->findById($id));
        $this->assertNull($this->files->findById((int) $backup->fileId));
    }

    /**
     * The trap the roadmap names: purging on boot or on migration would
     * make an installation watch two of its backups vanish in the middle
     * of an update nobody connected to retention.
     */
    public function testNothingIsPurgedWithoutACreation(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->completed('database');
        }

        // No purgeAfterCreating() call: merely building the service, or
        // reading a quota, must move nothing.
        $this->retention()->quotaFor(BackupFamily::Manual);

        $this->assertCount(6, $this->backups->findAllNewestFirst());
    }

    public function testAnAdministratorCanRaiseAFamilysQuota(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('backup_keep_manual', '3', 'text', 'k', 'k');
        $settings->set('backup_keep_manual', '5');

        for ($i = 0; $i < 7; $i++) {
            $this->completed('database');
            $this->retention($settings)->purgeAfterCreating('database');
        }

        $this->assertCount(5, $this->backups->findAllNewestFirst());
    }

    /**
     * Zero is not a retention policy anybody means to express — it says
     * "take a backup, then delete it" — and a negative number read out of
     * a settings row would empty the table.
     */
    public function testAnUnusableQuotaFallsBackToTheDefaultInsteadOfEmptyingTheTable(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('backup_keep_manual', '3', 'text', 'k', 'k');
        $settings->set('backup_keep_manual', '0');

        $this->assertSame(3, $this->retention($settings)->quotaFor(BackupFamily::Manual));
    }

    /** With no settings service at all, the defaults still apply. */
    public function testTheDefaultsApplyWithNoSettingsToRead(): void
    {
        foreach (BackupFamily::cases() as $family) {
            $this->assertSame($family->defaultQuota(), $this->retention()->quotaFor($family));
        }
    }

    /**
     * A type this version cannot classify is kept, never counted and
     * never deleted: keeping an unknown row costs disk, deleting it costs
     * the only copy of something.
     */
    public function testAnUnclassifiableTypeIsLeftAlone(): void
    {
        $this->assertNull(BackupFamily::tryFromType('something_a_later_version_adds'));

        for ($i = 0; $i < 6; $i++) {
            $this->completed('database');
        }
        $this->retention()->purgeAfterCreating('something_a_later_version_adds');

        $this->assertCount(6, $this->backups->findAllNewestFirst());
    }

    /**
     * The bug this rule exists for, and it destroyed real archives.
     *
     * A row is inserted `pending` before its background job runs, and a
     * job that fails leaves it behind as `failed` with no file at all.
     * Counting by family alone made that empty row the newest member of
     * its family — and with the gallery cap at one, the next creation of
     * ANY kind kept the failure and deleted the last archive that
     * actually contained the gallery.
     */
    public function testAFailedAttemptNeverEvictsTheArchiveThatSucceeded(): void
    {
        $good = $this->completed('full_with_gallery', archive: 'good.zip');
        $failed = $this->backups->create('full_with_gallery', null);
        $this->backups->markFailed($failed, 'disque plein');

        $this->completed('auto_backup');
        $this->retention()->purgeAfterCreating('auto_backup');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertContains($good, $surviving, 'The real archive must survive an empty failure.');
        $this->assertFileExists($this->storagePath . '/maintenance/good.zip');
    }

    /** Same blindness on the family quota: three failures are not three backups. */
    public function testFailedAttemptsDoNotSpendAFamilysQuota(): void
    {
        $kept = [];
        for ($i = 0; $i < 3; $i++) {
            $kept[] = $this->completed('database');
        }
        for ($i = 0; $i < 3; $i++) {
            $this->backups->markFailed($this->backups->create('database', null), 'échec');
        }

        $this->completed('database');
        $this->retention()->purgeAfterCreating('database');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        // The quota is three USABLE copies: the newest three completed
        // survive, the oldest completed goes, and the failures never
        // counted.
        $this->assertNotContains($kept[0], $surviving);
        $this->assertContains($kept[1], $surviving);
        $this->assertContains($kept[2], $surviving);
    }

    /**
     * Not counting failures cannot mean keeping them for ever — but the
     * LAST one is what tells an operator the backup stopped working.
     */
    public function testOnlyTheMostRecentFailureOfAFamilySurvives(): void
    {
        $older = $this->backups->create('database', null);
        $this->backups->markFailed($older, 'premier échec');
        usleep(1000);
        $newest = $this->backups->create('database', null);
        $this->backups->markFailed($newest, 'second échec');

        $this->completed('database');
        $this->retention()->purgeAfterCreating('database');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertNotContains($older, $surviving);
        $this->assertContains($newest, $surviving, 'The last failure is the one an operator has to see.');
    }

    /**
     * A row still being written to is never removed: deleting one is a
     * race whose other end is a half-written archive with no record.
     */
    public function testARowAHandlerIsStillWritingToIsNeverTouched(): void
    {
        $pending = $this->backups->create('database', null);
        usleep(1000);
        $running = $this->backups->create('database', null);
        $this->backups->markInProgress($running);
        usleep(1000);
        for ($i = 0; $i < 5; $i++) {
            $this->completed('database');
        }

        $this->retention()->purgeAfterCreating('database');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertContains($pending, $surviving);
        $this->assertContains($running, $surviving);
    }

    private function retention(?SettingService $settings = null): BackupRetention
    {
        return new BackupRetention($this->backups, $this->files, $this->storagePath, $settings);
    }

    private function completed(string $type, ?string $archive = null, ?string $dump = null): int
    {
        $this->assertContains($type, array_merge(Backup::TYPES, ['something_a_later_version_adds']));
        $id = $this->backups->create($type, null);
        $this->backups->markCompleted(
            $id,
            $archive !== null ? $this->file($archive) : null,
            $dump !== null ? $this->file($dump) : null
        );

        // Two rows created inside the same second would otherwise order by
        // id alone; the queries order by created_at first, so the test has
        // to be honest about which is newer.
        usleep(1000);

        return $id;
    }

    private function file(string $name): int
    {
        file_put_contents($this->storagePath . '/maintenance/' . $name, 'x');

        return $this->files->create(
            'maintenance/' . $name,
            $name,
            'application/zip',
            1,
            'admin',
            null,
            null
        );
    }
}
