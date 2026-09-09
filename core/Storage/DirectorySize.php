<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

/**
 * How many bytes a directory tree occupies, and which files are in it.
 *
 * One walk, two intents — see {@see DirectoryWalk}, which is where the
 * differences live and why. A measurement is lenient and does not follow
 * links; an archive is strict and does. They were two independent boolean
 * parameters once, which let an archive silently inherit a measurement's
 * leniency: the parameter names an intent now so that cannot happen by
 * forgetting an argument.
 *
 * « Follows links » covers symlinked **directories**, not only symlinked
 * files, and that took a second reading to get right: without
 * `FilesystemIterator::FOLLOW_SYMLINKS` the iterator will not descend into
 * a linked directory no matter what the filter says, so the promise held
 * for files and quietly failed for the case that matters — a host that
 * symlinks `storage/gallery` onto another volume. Following links makes
 * cycles reachable, so each directory is entered once by resolved path.
 */
final class DirectorySize
{
    /**
     * @param string[] $excludedPrefixes absolute path prefixes to leave out
     *                                   entirely — matched on a path
     *                                   boundary, so excluding `temp` never
     *                                   also excludes `temperatures`
     */
    public static function measure(
        string $directory,
        array $excludedPrefixes = [],
        DirectoryWalk $intent = DirectoryWalk::Measurement
    ): int {
        $total = 0;
        foreach (self::files($directory, $excludedPrefixes, $intent) as $file) {
            $total += max(0, (int) $file->getSize());
        }

        return $total;
    }

    /**
     * The same walk, exposed so a caller that needs the files themselves
     * (`Core\Maintenance\BackupService`, deciding what to archive) uses one
     * definition of "which files are in this tree" rather than a second
     * copy that forgets an exclusion.
     *
     * @param string[] $excludedPrefixes
     * @return iterable<\SplFileInfo>
     * @throws \UnexpectedValueException on an unreadable directory, and only
     *         under {@see DirectoryWalk::Archive} — a measurement swallows
     *         it. A directory that is simply ABSENT is neither: it yields
     *         nothing, for both intents.
     */
    public static function files(
        string $directory,
        array $excludedPrefixes = [],
        DirectoryWalk $intent = DirectoryWalk::Measurement
    ): iterable {
        $followLinks = $intent->followsLinks();

        // FOLLOW_SYMLINKS is what makes a symlinked DIRECTORY reachable at
        // all: `RecursiveDirectoryIterator::hasChildren()` refuses to
        // descend into one without it, whatever the filter below returns,
        // so the entry surfaces as a leaf, fails `isFile()` (it stats
        // through to a directory) and is dropped in silence. Symlinked
        // *files* never go through `hasChildren()`, which is why the flag
        // looks unnecessary until a host symlinks `storage/gallery`
        // elsewhere and the archive quietly contains none of it.
        $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO;
        if ($followLinks) {
            $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;
        }

        // Constructed inside the guard, and there is no `is_dir()` ahead of
        // it any more, on purpose.
        //
        // `RecursiveDirectoryIterator` opens the directory in its
        // CONSTRUCTOR and throws `UnexpectedValueException` when it
        // cannot. Built above the try/catch — behind an `is_dir()` that
        // had already returned — it escaped the very handling documented
        // for it: under `Measurement` an unreadable root became an
        // uncaught 500 on the Maintenance page, and on every upload
        // surface once a quota was declared, which is precisely what a
        // lenient measurement exists to prevent. A pre-check could not
        // close that: between `is_dir()` and the open there is always a
        // window, and a directory removed inside it lands here anyway.
        //
        // **Absent is not unreadable.** A tree that is simply not there
        // weighs nothing and contributes nothing, for either intent —
        // `storage/gallery` on a site with no gallery is the ordinary
        // case. Only a path that IS a directory and still could not be
        // opened is the permission failure an archive must refuse to walk
        // past rather than silently omit.
        try {
            $directoryIterator = new \RecursiveDirectoryIterator($directory, $flags);
        } catch (\UnexpectedValueException $e) {
            if (is_dir($directory) && $intent->failsOnUnreadable()) {
                throw $e;
            }

            return;
        }

        // Following links means cycles are now possible — `ln -s .. up` is
        // one, and two directories pointing at each other are another —
        // and PHP's recursive iterator has no cycle detection of its own:
        // it would walk until the pathname limit, producing an archive
        // that never closes. Each directory is therefore entered once, by
        // resolved path, which also stops a tree symlinked twice from
        // being counted twice.
        $enteredDirectories = [];

        $filtered = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (\SplFileInfo $current) use (
                $excludedPrefixes,
                $followLinks,
                &$enteredDirectories
            ): bool {
                if (!$followLinks && $current->isLink()) {
                    return false;
                }
                $path = $current->getPathname();
                foreach ($excludedPrefixes as $prefix) {
                    if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                        return false;
                    }
                }
                if ($followLinks && $current->isDir()) {
                    $resolved = realpath($path);
                    if ($resolved === false) {
                        // A link pointing nowhere. Nothing to walk, and
                        // nothing that belongs in an archive either.
                        return false;
                    }
                    if (isset($enteredDirectories[$resolved])) {
                        return false;
                    }
                    $enteredDirectories[$resolved] = true;
                }
                return true;
            }
        );

        // CATCH_GET_CHILD swallows an unreadable subdirectory and carries
        // on. Right for a measurement, wrong for an archive — an archive
        // that skips what it cannot read is worse than one that fails,
        // because only the failure is visible before the restore.
        $flags = $intent->failsOnUnreadable() ? 0 : \RecursiveIteratorIterator::CATCH_GET_CHILD;

        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY, $flags);

        if ($intent->failsOnUnreadable()) {
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    yield $file;
                }
            }

            return;
        }

        try {
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    yield $file;
                }
            }
        } catch (\UnexpectedValueException) {
            // The root itself became unreadable mid-walk. Nothing to add.
            return;
        }
    }
}
