<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Config\SettingService;
use Core\File\FileRepository;

/**
 * How many backups the server keeps, and the one code path that removes one.
 *
 * **One path, and that is the point rather than a tidiness argument.** Six
 * places used to carry their own copy of the same fifteen lines — the
 * controller's synchronous database backup, and the five task handlers.
 * They agreed, which is why nobody noticed; the risk was the seventh
 * copy. A `backups` row owns **two** files, `file_id` and
 * `db_dump_file_id`, and a routine that forgets the second leaves an
 * orphan on disk that nothing references any more and that only FTP can
 * reach. Manual deletion (Configuration › Maintenance) therefore reuses
 * {@see forget()} rather than growing a seventh copy of it.
 *
 * **Retention is per family** ({@see BackupFamily}). A single ordered list
 * capped at five let three consecutive updates evict the full backup an
 * administrator had taken deliberately: the automatic backups outnumber
 * the wanted ones, so the noise always won. Each family now evicts its
 * own, and nothing else does.
 *
 * **There used to be a cap cutting across all of them** — at most one
 * archive containing the photo gallery, which could weigh more than
 * everything else on the disk together. D10 removed the thing it was
 * weighing: no archive carries a declared storage location any more, so
 * every archive is now of the same modest order and the family quotas
 * are the whole of the policy. A cap that could no longer fire would
 * have been a rule nobody could test.
 *
 * **Purging happens on creation and nowhere else.** Not at boot, not
 * during a migration: an installation holding five backups must not watch
 * two of them vanish in the middle of an update nobody connected to
 * retention. That is also why {@see purgeAfterCreating()} takes the type
 * just written — the family it belongs to is the only family it touches.
 */
final class BackupRetention
{
    public function __construct(
        private readonly BackupRepository $backups,
        private readonly FileRepository $files,
        private readonly string $storagePath,
        private readonly ?SettingService $settings = null,
        private readonly ?BackupSafetyNet $safetyNet = null
    ) {
    }

    /**
     * Enforces retention after a backup of `$createdType` has been written.
     *
     * One rule since D10 (see the class docblock): the family's own
     * quota — the family of the row just created, and no other, so a
     * scheduled backup never evicts a manual one.
     */
    public function purgeAfterCreating(string $createdType): void
    {
        // Read once: two rules over the same handful of rows, and a table
        // that changes under them as forget() runs would make the second
        // rule reason about a row the first has already removed.
        $all = $this->backups->findAllNewestFirst();

        // And the safety net once, for the same reason plus a second: it
        // costs two queries, and asking it per candidate row would pay
        // that for every eviction rather than for every purge.
        $protected = $this->safetyNet?->protectedIds() ?? [];

        $family = BackupFamily::tryFromType($createdType);
        if ($family !== null) {
            foreach ($this->beyondQuota($all, $family) as $old) {
                $this->forgetUnlessInUse($old, $protected);
            }
            foreach ($this->supersededFailures($all, $family) as $old) {
                $this->forgetUnlessInUse($old, $protected);
            }
        }
    }

    /**
     * Evicts a backup unless an operation still in flight would fall back
     * on it.
     *
     * **The automatic purge needs the same refusal the delete button
     * has.** The case that motivated it was the gallery cap — a restore
     * took a gallery-bearing safety copy, and one manual gallery backup
     * made while that restore was running evicted the only thing its
     * rollback could start from, silently and with nobody having asked
     * for anything to be deleted. The cap is gone with D10 and the
     * refusal outlived it, because it was never about the gallery: an
     * `auto_update` copy has held no gallery since issue #298 and is
     * still protected, because the family quota can evict just as
     * quietly.
     *
     * A protected row is **skipped, not deferred**: the quota is exceeded
     * by one until the operation ends and the next creation purges it.
     * That overshoot lasts minutes and costs one archive; the alternative
     * costs an installation its way back.
     *
     * @param int[] $protected
     */
    private function forgetUnlessInUse(Backup $backup, array $protected): void
    {
        if (in_array($backup->id, $protected, true)) {
            return;
        }

        $this->forget($backup);
    }

    /**
     * Removes a backup: both of its files from disk, both of its `files`
     * rows, then the `backups` row.
     *
     * **A file already gone must not stop the row from going.** That is
     * the ordinary case, not an edge one — a purge that half-succeeded, an
     * archive somebody removed over FTP, a `storage/` restored from
     * elsewhere. Leaving the row would leave a line in the list offering a
     * download of nothing, which is worse than the missing file it
     * describes. `@unlink` and the null check are what make that true.
     */
    public function forget(Backup $backup): void
    {
        foreach ([$backup->fileId, $backup->dbDumpFileId] as $fileId) {
            if ($fileId === null) {
                continue;
            }
            $file = $this->files->findById($fileId);
            if ($file !== null) {
                @unlink($this->storagePath . '/' . $file->relativePath);
                $this->files->delete($fileId);
            }
        }

        $this->backups->delete($backup->id);
    }

    /**
     * How many of a family this installation keeps — its setting, or its
     * default when the setting is absent or unreadable.
     *
     * Clamped to at least one. Zero would mean "take a backup, then delete
     * it", which is not a retention policy anybody means to express, and a
     * negative number read out of a settings row would silently empty the
     * table.
     *
     * **A family with no setting key is not configurable at all**, and the
     * settings table is not even consulted for it — `Portable` keeps
     * exactly one, and a `backup_keep_portable` row invented by a hand, an
     * older site's restored settings or a future version cannot raise it.
     * Reading the setting and then ignoring the answer would have been the
     * same behaviour today and a trap the first time someone wondered why
     * their row did nothing.
     */
    public function quotaFor(BackupFamily $family): int
    {
        $key = $family->quotaSettingKey();
        if ($key === null) {
            return $family->defaultQuota();
        }

        $configured = $this->settings?->get($key);
        $value = is_scalar($configured) ? (int) $configured : 0;

        return $value > 0 ? $value : $family->defaultQuota();
    }

    /**
     * The COMPLETED rows of one family beyond its quota, oldest last.
     *
     * **Only a completed backup occupies a slot**, and getting this wrong
     * destroyed real archives. A row is inserted `pending` before its
     * background job runs, and a job that fails leaves the row behind as
     * `failed` with no file at all — so counting by family alone made the
     * newest member of a family an empty record of a failure. Under the
     * gallery cap this codebase used to carry — one archive, all families
     * together — the next creation of ANY kind then kept that empty row
     * and deleted the last archive that actually contained the gallery.
     * The cap is gone with D10, the lesson is not: the quota is a promise
     * about how many usable copies exist, and a row that is not one
     * cannot spend it.
     *
     * @param Backup[] $all
     * @return Backup[]
     */
    private function beyondQuota(array $all, BackupFamily $family): array
    {
        $usable = array_values(array_filter(
            $all,
            static fn(Backup $backup): bool => $backup->status === 'completed'
                && BackupFamily::tryFromType($backup->type) === $family
        ));

        return array_slice($usable, $this->quotaFor($family));
    }

    /**
     * Failed attempts of one family, except the most recent.
     *
     * Not counting failures towards the quota cannot mean keeping them for
     * ever: the table would grow one row per failure and the list would
     * fill with them. But the LAST failure is exactly what an operator
     * needs to see — « Échouée » on the most recent attempt is the whole
     * reason the row is not simply deleted when the job gives up — so one
     * survives per family and the rest go.
     *
     * `pending` and `in_progress` are deliberately absent: a handler is
     * writing to those rows right now, and deleting one is a race whose
     * other end is a half-written archive with no record.
     *
     * @param Backup[] $all
     * @return Backup[]
     */
    private function supersededFailures(array $all, BackupFamily $family): array
    {
        $failures = array_values(array_filter(
            $all,
            static fn(Backup $backup): bool => $backup->status === 'failed'
                && BackupFamily::tryFromType($backup->type) === $family
        ));

        return array_slice($failures, 1);
    }

}
