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
 * own, plus one cap that cuts across all of them — at most a single
 * archive containing the photo gallery, which can weigh more than
 * everything else on the disk together.
 *
 * **Purging happens on creation and nowhere else.** Not at boot, not
 * during a migration: an installation holding five backups must not watch
 * two of them vanish in the middle of an update nobody connected to
 * retention. That is also why {@see purgeAfterCreating()} takes the type
 * just written — the family it belongs to is the only family it touches.
 */
final class BackupRetention
{
    /**
     * How many gallery-bearing archives survive, all families together.
     *
     * One, and not a setting: this is the cap that actually decides
     * whether the disk holds. Two of them is routinely more than every
     * other backup combined, and an administrator who wants a second copy
     * has somewhere better to put it than the server it is meant to
     * survive.
     */
    public const KEEP_GALLERY = 1;

    public function __construct(
        private readonly BackupRepository $backups,
        private readonly FileRepository $files,
        private readonly string $storagePath,
        private readonly ?SettingService $settings = null
    ) {
    }

    /**
     * Enforces retention after a backup of `$createdType` has been written.
     *
     * Two rules, in order. The family's own quota first — the family of
     * the row just created, and no other, so a scheduled backup never
     * evicts a manual one. Then the gallery cap, which by its nature reads
     * every family at once.
     *
     * The gallery cap runs after every creation rather than only after a
     * gallery one, and that is a deliberate reading of "at creation": a
     * second gallery archive can only exist because somebody made one, and
     * an installation that already had two when this shipped should not
     * have to make a third before the cap notices.
     */
    public function purgeAfterCreating(string $createdType): void
    {
        $family = BackupFamily::tryFromType($createdType);
        if ($family !== null) {
            foreach ($this->beyondQuota($family) as $old) {
                $this->forget($old);
            }
        }

        foreach ($this->galleryArchivesBeyondCap() as $old) {
            $this->forget($old);
        }
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
     */
    public function quotaFor(BackupFamily $family): int
    {
        $configured = $this->settings?->get($family->quotaSettingKey());
        $value = is_scalar($configured) ? (int) $configured : 0;

        return $value > 0 ? $value : $family->defaultQuota();
    }

    /**
     * The rows of one family beyond its quota, oldest last.
     *
     * @return Backup[]
     */
    private function beyondQuota(BackupFamily $family): array
    {
        $ofFamily = array_values(array_filter(
            $this->backups->findAllNewestFirst(),
            static fn(Backup $backup): bool => BackupFamily::tryFromType($backup->type) === $family
        ));

        return array_slice($ofFamily, $this->quotaFor($family));
    }

    /**
     * Gallery-bearing archives beyond {@see KEEP_GALLERY}, any family.
     *
     * @return Backup[]
     */
    private function galleryArchivesBeyondCap(): array
    {
        $withGallery = array_values(array_filter(
            $this->backups->findAllNewestFirst(),
            static fn(Backup $backup): bool => in_array($backup->type, Backup::GALLERY_TYPES, true)
        ));

        return array_slice($withGallery, self::KEEP_GALLERY);
    }
}
