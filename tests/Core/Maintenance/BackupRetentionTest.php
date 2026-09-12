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
use Core\Maintenance\BackupSafetyNet;
use Core\Maintenance\UpdateHistoryRepository;
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
        $this->assertContains($operational[3], $surviving);
    }

    /**
     * Each family keeps its own — and the pre-operation one keeps ONE
     * when what it keeps is a pre-RESET copy, which is the gallery cap
     * binding before its quota does.
     *
     * A pre-reset archive carries the photo gallery
     * (`createFileBackup(true)`: the operation it protects against can
     * wipe `storage/gallery/`, so its safety copy has to hold it), and
     * the cap on gallery-bearing archives is one across all families. So
     * a run of resets reaches one rather than `backup_keep_operational` —
     * stated here because it is surprising, and written into the
     * setting's own description and
     * `docs/exigences-non-fonctionnelles.md` §4bis for the same reason.
     *
     * A run of UPDATES does not: see the test below, and issue #298.
     */
    public function testEachFamilyKeepsItsOwnQuotaAndTheGalleryCapBindsFirst(): void
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
        $this->assertSame(
            ['manual' => 3, 'operational' => BackupRetention::KEEP_GALLERY, 'scheduled' => 3],
            $byFamily
        );
    }

    /**
     * What issue #298 bought: three safety copies before an update, which
     * is what `backup_keep_operational` promised all along.
     *
     * The quota was an upper bound nothing reached while `auto_update`
     * carried the gallery — the cap of one bound first and an
     * installation kept a single pre-operation archive whatever the
     * setting said. An update's safety copy leaves the gallery on disk
     * now, so nothing about it is gallery-sized and the number in the
     * setting is the number of archives.
     */
    public function testAnUpdatesSafetyCopyNoLongerSpendsTheGallerySlot(): void
    {
        $this->assertNotContains(
            'auto_update',
            Backup::GALLERY_TYPES,
            'An update archive is back under the gallery cap, so the quota below is unreachable again.'
        );

        $gallery = $this->completed('full_with_gallery');
        for ($i = 0; $i < 4; $i++) {
            $this->completed('auto_update');
            $this->retention()->purgeAfterCreating('auto_update');
        }

        $surviving = $this->backups->findAllNewestFirst();
        $updates = array_filter($surviving, static fn(Backup $b): bool => $b->type === 'auto_update');

        $this->assertCount(3, $updates, 'The pre-operation quota is a real number now, not an upper bound.');
        $this->assertContains(
            $gallery,
            array_map(static fn(Backup $b): int => $b->id, $surviving),
            'An update archive that holds no gallery must not evict the one that does.'
        );
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
     * **The portable quota is one, and nobody can raise it.**
     *
     * Every other family's number is an administrator's to choose: they
     * are copies of the site, and somebody with the disk for more of them
     * is entitled to more. The portable archive is not that. It carries
     * `master.key`, so the second copy is a second liability sitting on
     * the very server the backup exists to survive — and the screen
     * promises « une seule est conservée ».
     *
     * The mechanism is a family with no setting key at all, rather than a
     * key that is read and then ignored: the settings table is never
     * consulted, so a `backup_keep_portable` row — hand-written, restored
     * from an older site, or invented by a later version — cannot change
     * the answer. This test writes exactly such a row.
     */
    public function testThePortableQuotaIsOneAndNoSettingCanRaiseIt(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('backup_keep_portable', '3', 'text', 'k', 'k');
        $settings->set('backup_keep_portable', '9');

        $this->assertNull(BackupFamily::Portable->quotaSettingKey());
        $this->assertSame(1, $this->retention($settings)->quotaFor(BackupFamily::Portable));
    }

    /**
     * And the quota is enforced: a new portable archive replaces the old.
     *
     * Which is the whole promise on screen. A site accumulating three
     * copies of the master key in `storage/` is the failure this family
     * exists to prevent.
     */
    public function testANewPortableBackupReplacesThePreviousOne(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->completed(Backup::PORTABLE_TYPE);
            $this->retention()->purgeAfterCreating(Backup::PORTABLE_TYPE);
        }

        $this->assertCount(1, $this->backups->findAllNewestFirst());
    }

    /**
     * A portable archive does not evict the ordinary backups.
     *
     * Retention is per family precisely so that one kind cannot push out
     * another, and this is the newest kind: shipping it wired into
     * `Manual` would have made every portable backup silently consume a
     * slot an administrator's own full backups were counting on.
     */
    public function testAPortableBackupDoesNotEvictTheManualOnes(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->completed('full_no_gallery');
        }

        $this->completed(Backup::PORTABLE_TYPE);
        $this->retention()->purgeAfterCreating(Backup::PORTABLE_TYPE);

        $this->assertCount(4, $this->backups->findAllNewestFirst());
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

    /**
     * A pre-RESET archive counts towards the gallery cap, because it holds
     * the gallery — the name of a type says nothing about its contents.
     *
     * With `auto_reset` left out of the cap, an installation could hold
     * four gallery-sized archives at once: one manual, plus a
     * pre-operation family quota of three. That is the exact disk the cap
     * exists to defend. Its sibling `auto_update` is deliberately not this
     * test's subject any more — that archive stopped carrying a gallery
     * with issue #298, and the test above is what pins the consequence.
     */
    public function testAPreOperationArchiveCountsTowardsTheGalleryCap(): void
    {
        $manualGallery = $this->completed('full_with_gallery', archive: 'manual.zip');
        $operationalGallery = $this->completed('auto_reset', archive: 'safety.zip');

        $this->completed('database');
        $this->retention()->purgeAfterCreating('database');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertContains($operationalGallery, $surviving, 'The newest gallery-bearing archive stays.');
        $this->assertNotContains($manualGallery, $surviving, 'The older one is over the cap, whatever its family.');
    }

    /**
     * And retention must not become a way to delete the net of an
     * operation that is running — the exact deletion the manual path
     * refuses.
     *
     * Before the safety net reached the automatic purge, a backup taken
     * while an install was running would have evicted the only thing that
     * install's rollback can start from: silently, with nobody having
     * asked for anything to be deleted.
     *
     * The eviction pressure here is the pre-operation family quota rather
     * than the gallery cap, which is the pressure an `auto_update` archive
     * actually feels since it stopped carrying a gallery (issue #298).
     * The refusal is the same one either way: it is about the row being in
     * use, never about what the row weighs.
     */
    public function testRetentionNeverEvictsTheNetOfARunningOperation(): void
    {
        $inUse = $this->completed('auto_update', archive: 'net.zip');
        $updates = new UpdateHistoryRepository($this->pdo);
        $historyId = $updates->create('1.0.0', '1.1.0', false, null);
        $updates->setBackupId($historyId, $inUse);
        $updates->setStatus($historyId, 'installing');

        // Three newer ones: the oldest of four is over a quota of three.
        for ($i = 0; $i < 3; $i++) {
            $this->completed('auto_update');
        }
        $this->retention(null, $this->safetyNet())->purgeAfterCreating('auto_update');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertContains($inUse, $surviving, 'An install still running must keep the backup it rolls back to.');
        $this->assertFileExists($this->storagePath . '/maintenance/net.zip');
    }

    /** Once the operation finishes, retention applies to it like anything else. */
    public function testRetentionCatchesUpOnceTheOperationIsOver(): void
    {
        $wasInUse = $this->completed('auto_update', archive: 'net.zip');
        $updates = new UpdateHistoryRepository($this->pdo);
        $historyId = $updates->create('1.0.0', '1.1.0', false, null);
        $updates->setBackupId($historyId, $wasInUse);
        $updates->markCompleted($historyId);

        for ($i = 0; $i < 3; $i++) {
            $this->completed('auto_update');
        }
        $this->retention(null, $this->safetyNet())->purgeAfterCreating('auto_update');

        $surviving = array_map(fn($b) => $b->id, $this->backups->findAllNewestFirst());
        $this->assertNotContains($wasInUse, $surviving);
    }

    private function safetyNet(): BackupSafetyNet
    {
        return BackupSafetyNet::forPdo($this->pdo);
    }

    private function retention(
        ?SettingService $settings = null,
        ?BackupSafetyNet $safetyNet = null
    ): BackupRetention {
        return new BackupRetention($this->backups, $this->files, $this->storagePath, $settings, $safetyNet);
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
