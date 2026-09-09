<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage;

/**
 * How many bytes a directory tree actually occupies.
 *
 * **Symbolic links are not counted unless a caller asks for them.** Two
 * reasons: a link to a file already inside the tree double-counts it, and
 * a link to a file outside the tree counts bytes that are not on this
 * account's quota at all — which reports a site as fuller than it is, on
 * exactly the shared hosting where the number matters.
 *
 * Symlinked *directories* are never descended into, whatever `$followLinks`
 * says. That is `RecursiveDirectoryIterator`'s own default and this
 * codebase's existing behaviour; changing it would be a way to make a
 * backup loop forever on a link pointing at an ancestor.
 *
 * An unreadable subdirectory is skipped rather than fatal: this measurement
 * feeds a warning and a refusal-to-write, and neither is worth turning a
 * page into a 500 over one directory whose permissions are wrong.
 */
final class DirectorySize
{
    /**
     * @param string[] $excludedPrefixes absolute path prefixes to leave out
     *                                   entirely — matched with
     *                                   str_starts_with, so pass a real
     *                                   directory path without its trailing
     *                                   slash
     * @param bool     $followLinks      see {@see files()}; false is what a
     *                                   quota measurement wants and the
     *                                   default for that reason
     */
    public static function measure(string $directory, array $excludedPrefixes = [], bool $followLinks = false): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $total = 0;
        foreach (self::files($directory, $excludedPrefixes, $followLinks) as $file) {
            $total += max(0, (int) $file->getSize());
        }

        return $total;
    }

    /**
     * The same walk, exposed so a caller that needs the files themselves
     * (Core\Maintenance\BackupService, deciding what to archive) uses one
     * definition of "which files are in this tree" rather than a second
     * copy that forgets an exclusion.
     *
     * @param string[] $excludedPrefixes
     * @param bool     $followLinks whether symlinked FILES are yielded.
     *        **False for a measurement** — see this class's docblock.
     *        **True for an archive**: that is what `BackupService` has
     *        always done, and a backup is not the place to start silently
     *        leaving out a file somebody's host symlinked elsewhere. The
     *        two callers want genuinely different answers, so the
     *        difference is a parameter rather than a compromise. Symlinked
     *        directories are never descended into either way.
     * @return iterable<\SplFileInfo>
     */
    public static function files(string $directory, array $excludedPrefixes = [], bool $followLinks = false): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $directoryIterator = new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            );

            $filtered = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                static function (\SplFileInfo $current) use ($excludedPrefixes, $followLinks): bool {
                    if (!$followLinks && $current->isLink()) {
                        return false;
                    }
                    $path = $current->getPathname();
                    foreach ($excludedPrefixes as $prefix) {
                        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                            return false;
                        }
                    }
                    return true;
                }
            );

            $iterator = new \RecursiveIteratorIterator(
                $filtered,
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

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
