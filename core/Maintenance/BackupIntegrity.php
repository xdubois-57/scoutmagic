<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\File\FileRepository;

/**
 * Is a backup that is already on disk still the backup that was written?
 *
 * **Nothing asked that question before.** `RestoreBackupHandler` validates
 * an archive somebody uploads, because it is about to act on it — but a
 * backup sitting in `storage/maintenance/` is opened on exactly one day,
 * the day it is needed, which is the worst possible day to learn it was
 * truncated. And truncation is not hypothetical: it is what a full quota
 * produces, the failure `Core\Storage\DiskBudget` (§8.98) now refuses up
 * front but that every archive written before it could have suffered.
 *
 * So each file's SHA-256 is recorded once, when the backup completes, and
 * a light scheduled pass re-reads the files afterwards and compares
 * ({@see \Core\Maintenance\Task\VerifyBackupIntegrityHandler}).
 *
 * **Not a rehearsed restore.** Actually restoring each backup somewhere to
 * prove it works is the thorough answer and it is unaffordable here: this
 * project targets shared hosting with no shell and a PHP time limit
 * (`docs/exigences-non-fonctionnelles.md` §5). "Is it still readable, byte
 * for byte" is the question that fits in that budget, and it catches the
 * failure that actually happens.
 *
 * **This class is the only production path to `markCompleted()`**, and
 * that is enforced rather than hoped for ({@see
 * \Tests\Core\Maintenance\BackupIntegrityWiringTest}). Six sites complete a
 * backup — the controller's synchronous database dump and five task
 * handlers — and a seventh that recorded no digest would create a backup
 * nothing could ever verify, silently, for ever. Routing completion
 * through here means forgetting is not spelled `// TODO` but a call that
 * does not exist.
 */
final class BackupIntegrity
{
    public function __construct(
        private readonly BackupRepository $backups,
        private readonly FileRepository $files,
        private readonly string $storagePath
    ) {
    }

    /**
     * Marks a backup complete AND records what its files hashed to.
     *
     * One call rather than two, because two is one too many: the second
     * would eventually be forgotten at one of the six sites, and the row
     * would look finished while being unverifiable for the rest of its
     * life.
     *
     * A path that cannot be hashed yields a null digest rather than an
     * exception. The archive is written and the row must record it: a
     * backup that exists and cannot be verified is worth strictly more
     * than a backup the bookkeeping threw away because a `stat()` failed.
     */
    public function complete(
        int $backupId,
        ?int $fileId,
        ?string $filePath,
        ?int $dbDumpFileId,
        ?string $dbDumpPath
    ): void {
        $this->backups->markCompleted(
            $backupId,
            $fileId,
            $dbDumpFileId,
            $filePath !== null ? self::digestOf($filePath) : null,
            $dbDumpPath !== null ? self::digestOf($dbDumpPath) : null
        );
    }

    /**
     * Re-reads a backup's files and says what it found.
     *
     * The order of the answers matters. A file that is gone is reported as
     * gone even if the other one is fine, and a mismatch outranks
     * everything else — an operator whose archive is intact but whose
     * database dump is truncated has an unusable backup, and a summary
     * that averaged the two into "mostly fine" would be a lie in the only
     * direction that costs anything.
     */
    public function verify(Backup $backup): BackupIntegrityStatus
    {
        $pairs = [
            [$backup->fileId, $backup->archiveSha256],
            [$backup->dbDumpFileId, $backup->dbDumpSha256],
        ];

        $verifiedSomething = false;
        $missing = false;

        foreach ($pairs as [$fileId, $expected]) {
            if ($fileId === null) {
                continue;
            }

            $file = $this->files->findById($fileId);
            if ($file === null) {
                $missing = true;
                continue;
            }

            $path = rtrim($this->storagePath, '/') . '/' . $file->relativePath;
            if (!is_file($path)) {
                $missing = true;
                continue;
            }

            // The row predates the digests: there is nothing to compare
            // this file to, and saying so is the only honest answer.
            if ($expected === null || $expected === '') {
                continue;
            }

            $actual = self::digestOf($path);
            if ($actual === null) {
                // Present but unreadable — a permission change, a mount
                // that went away mid-pass. Not a mismatch, and not
                // "fine": the file cannot be restored from either.
                $missing = true;
                continue;
            }

            if (!hash_equals($expected, $actual)) {
                return BackupIntegrityStatus::Corrupt;
            }

            $verifiedSomething = true;
        }

        if ($missing) {
            return BackupIntegrityStatus::Missing;
        }

        return $verifiedSomething
            ? BackupIntegrityStatus::Intact
            : BackupIntegrityStatus::Unverifiable;
    }

    /**
     * SHA-256 of a file, streamed — never loaded into memory.
     *
     * `hash_file()` and not `hash('sha256', file_get_contents(...))`: a
     * gallery-inclusive archive is measured in gigabytes on the
     * installation this project sizes for, and reading one into a string
     * would exhaust a shared host's memory limit on the pass that was
     * meant to reassure everybody. `hash_file()` reads in blocks
     * internally, which is what makes the whole verification affordable —
     * `BackupIntegrityTest` measures the peak allocation across a file far
     * larger than the slack it allows, so this cannot regress into the
     * obvious one-liner without failing.
     *
     * Null on anything unreadable, never an exception: every caller is
     * either recording (where the archive matters more than its
     * bookkeeping) or verifying (where "could not read" is an answer in
     * itself).
     */
    private static function digestOf(string $absolutePath): ?string
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        $digest = @hash_file('sha256', $absolutePath);

        return $digest === false ? null : $digest;
    }
}
