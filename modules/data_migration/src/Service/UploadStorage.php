<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

use Core\File\DataMigrationDeposits;

/**
 * Where deposited files live: `storage/temp/data_migration/`.
 *
 * Under `temp` on purpose — `Core\Maintenance\BackupService` never archives
 * it, so a ZIP of the unit's personal data never travels into a backup —
 * and never under `public/`. Nothing is ever EXTRACTED here: members are
 * read from the ZIP through `Core\Archive\BoundedZipReader`, so the one file
 * a run owns is the one deleted when it goes. A form source gets a
 * workspace directory of its own, deleted with it.
 *
 * The one thing besides is a slice's **working copies** — an attachment or
 * an image streamed out of the archive for the target module's upload
 * pipeline, which takes a path. They go in `copies-<run id>/`, emptied
 * when each slice of that run starts and when it ends (issue #1259): a
 * request killed by a timeout or its memory limit skips every `finally`,
 * and its copies are then removed by the run's next slice, or by the purge.
 *
 * The directory is also swept by the core, whether this module is enabled
 * or not (`Core\File\DataMigrationDeposits`), on the time of each entry's
 * last modification: {@see markMoved()} is what keeps a run's file as young
 * as its last movement.
 */
class UploadStorage
{
    public const DIR = DataMigrationDeposits::DIR;

    public function __construct(private string $storagePath)
    {
    }

    /** A fresh relative path for a deposited archive. */
    public function newArchivePath(): string
    {
        $this->ensureDirectory();

        return self::DIR . '/run-' . bin2hex(random_bytes(16)) . '.zip';
    }

    /** A fresh workspace (relative path of an empty directory) for a form source. */
    public function newWorkspace(): string
    {
        $relative = self::DIR . '/ws-' . bin2hex(random_bytes(16));
        $absolute = $this->absolute($relative);
        if (!is_dir($absolute) && !@mkdir($absolute, 0700, true) && !is_dir($absolute)) {
            throw new \RuntimeException('Cannot create a data migration workspace.');
        }

        return $relative;
    }

    /**
     * Stamps a run's file with the moment the run moved, so that the core's
     * sweep, which reads only the disk, sees it as young as the run.
     * Nothing for a run that holds no file.
     */
    public function markMoved(?string $relative, \DateTimeImmutable $at): void
    {
        if ($relative === null || $relative === '') {
            return;
        }
        try {
            $absolute = $this->absolute($relative);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (file_exists($absolute)) {
            @touch($absolute, $at->getTimestamp());
        }
    }

    /**
     * An empty directory for the working copies of one slice of a run —
     * whatever an earlier slice of that run left there is deleted first.
     * Absolute path.
     */
    public function freshCopiesDirectory(int $runId): string
    {
        $this->discardCopies($runId);
        $absolute = $this->absolute(self::copiesPath($runId));
        if (!is_dir($absolute) && !@mkdir($absolute, 0700, true) && !is_dir($absolute)) {
            throw new \RuntimeException('Cannot create the data migration working directory.');
        }

        return $absolute;
    }

    /** Deletes a run's working copies, if any. */
    public function discardCopies(int $runId): void
    {
        $absolute = $this->absolute(self::copiesPath($runId));
        if (is_dir($absolute)) {
            self::removeTree($absolute);
        }
    }

    /** Relative path → absolute, refusing anything outside the directory. */
    public function absolute(string $relative): string
    {
        if (!str_starts_with($relative, self::DIR . '/') || str_contains($relative, '..')) {
            throw new \InvalidArgumentException('Not a data migration path.');
        }

        return rtrim($this->storagePath, '/') . '/' . $relative;
    }

    /** Absolute path under the directory → relative, or null when it is outside. */
    public function relative(string $absolute): ?string
    {
        $root = rtrim($this->storagePath, '/') . '/';
        $real = realpath($absolute);
        $realRoot = realpath($root . self::DIR);
        if ($real === false || $realRoot === false || !str_starts_with($real, $realRoot . '/')) {
            return null;
        }

        return self::DIR . substr($real, strlen($realRoot));
    }

    /** Deletes a run's file, and its workspace when it lies in one. */
    public function delete(?string $relative): void
    {
        if ($relative === null || $relative === '') {
            return;
        }

        try {
            $absolute = $this->absolute($relative);
        } catch (\InvalidArgumentException) {
            return;
        }

        if (is_dir($absolute)) {
            self::removeTree($absolute);
        } else {
            @unlink($absolute);
        }

        $parent = dirname($absolute);
        if (str_starts_with(basename($parent), 'ws-')) {
            self::removeTree($parent);
        }
    }

    /**
     * Files and workspaces in the directory that no run references any
     * more — what a crash between a move and an insert can leave behind.
     *
     * @param list<string> $referenced relative paths still owned by a run
     * @return list<string> relative paths older than `$before`
     */
    public function orphans(array $referenced, int $before): array
    {
        $owned = [];
        foreach ($referenced as $path) {
            $owned[$path] = true;
            $parent = dirname($path);
            if (str_starts_with(basename($parent), 'ws-')) {
                $owned[$parent] = true;
            }
        }

        $orphans = [];
        foreach (glob(rtrim($this->storagePath, '/') . '/' . self::DIR . '/*') ?: [] as $entry) {
            $relative = self::DIR . '/' . basename($entry);
            $mtime = @filemtime($entry);
            if (!isset($owned[$relative]) && $mtime !== false && $mtime < $before) {
                $orphans[] = $relative;
            }
        }

        return $orphans;
    }

    private static function copiesPath(int $runId): string
    {
        return self::DIR . '/copies-' . $runId;
    }

    private function ensureDirectory(): void
    {
        $dir = rtrim($this->storagePath, '/') . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the data migration directory.');
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) && !is_link($entry) ? self::removeTree($entry) : @unlink($entry);
        }
        @rmdir($directory);
    }
}
