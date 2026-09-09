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
        if (!is_dir($directory)) {
            return 0;
        }

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
     *         under {@see DirectoryWalk::Archive} — a measurement swallows it
     */
    public static function files(
        string $directory,
        array $excludedPrefixes = [],
        DirectoryWalk $intent = DirectoryWalk::Measurement
    ): iterable {
        if (!is_dir($directory)) {
            return;
        }

        $followLinks = $intent->followsLinks();

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
