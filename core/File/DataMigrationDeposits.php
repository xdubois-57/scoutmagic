<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The directory the data migration module deposits its archives in —
 * `storage/temp/data_migration/` — and the core's own purge of it
 * (issue #1259).
 *
 * The module purges that directory itself, hourly, on the rule the page
 * and the RGPD page state: 24 hours after the run last moved. But a
 * module's scheduled task only has a handler while the module is enabled,
 * and the module is meant to be disabled once the migration is over — at
 * which point its purge stopped for good and the archives of the unit's
 * history (children, families, e-mails) stayed on the disk indefinitely.
 * So the core sweeps the same directory on a clock of its own, whether the
 * module is enabled, disabled or absent, the way it keeps every other
 * retention it promises (Desk imports, support packages).
 *
 * The core reads no table of the module and names none of its classes: it
 * decides from the disk alone. An entry — an archive, a form source's
 * workspace, a slice's working copies — goes once nothing in it has been
 * modified for {@see RETENTION_SECONDS}. That matches the module's rule
 * because the module touches a run's file every time the run moves
 * (`Modules\DataMigration\Service\UploadStorage::markMoved()`), so a run
 * that is moving always has a file younger than a day, and an idle one has
 * a file exactly as old as its last movement.
 */
final class DataMigrationDeposits
{
    /** Relative to `storage/`. The module's `UploadStorage::DIR` is this constant. */
    public const DIR = 'temp/data_migration';

    /** The same 24 hours as the module's own rule and the RGPD page's promise. */
    public const RETENTION_SECONDS = 86400;

    public function __construct(private string $storagePath)
    {
    }

    /** The absolute path of the directory (which may not exist). */
    public function directory(): string
    {
        return rtrim($this->storagePath, '/') . '/' . self::DIR;
    }

    /**
     * Deletes every entry of the directory left untouched for
     * {@see RETENTION_SECONDS} before `$now`, and returns how many went.
     */
    public function sweep(int $now): int
    {
        $cutoff = $now - self::RETENTION_SECONDS;
        $deleted = 0;
        foreach (glob($this->directory() . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            $newest = self::newestModification($entry);
            if ($newest === null || $newest >= $cutoff) {
                continue;
            }
            self::remove($entry);
            if (!file_exists($entry) && !is_link($entry)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The latest modification time of an entry and, for a directory, of
     * everything under it — a workspace is in use while the file inside it
     * moves, whatever the directory's own time says. Links are not
     * followed. Null when the entry vanished meanwhile.
     */
    private static function newestModification(string $entry): ?int
    {
        $stat = @lstat($entry);
        if ($stat === false) {
            return null;
        }
        $newest = (int) $stat['mtime'];
        if (is_dir($entry) && !is_link($entry)) {
            foreach (glob($entry . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
                if (in_array(basename($child), ['.', '..'], true)) {
                    continue;
                }
                $newest = max($newest, self::newestModification($child) ?? $newest);
            }
        }

        return $newest;
    }

    private static function remove(string $entry): void
    {
        if (!is_dir($entry) || is_link($entry)) {
            @unlink($entry);

            return;
        }
        foreach (glob($entry . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
            if (!in_array(basename($child), ['.', '..'], true)) {
                self::remove($child);
            }
        }
        @rmdir($entry);
    }
}
