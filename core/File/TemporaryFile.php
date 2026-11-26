<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * A temporary file created in a directory the caller chose, and nowhere
 * else (issue #1259).
 *
 * `tempnam($directory, …)` does not fail when the directory is missing or
 * not writable: it raises a notice and quietly creates the file in the
 * system's temporary directory instead. A copy of a member's photo or of
 * an e-mail attachment would then land in a shared `/tmp`, outside the
 * directory whose purge was supposed to find it if the request died before
 * its `finally` — which a timeout or an exhausted memory limit does. So
 * the directory is created first, and the file is checked to be in it.
 */
final class TemporaryFile
{
    /**
     * The absolute path of a new, empty file (mode 0600) in `$directory`,
     * which is created (0700) when absent.
     *
     * @throws \RuntimeException when the file cannot be created there
     */
    public static function createIn(string $directory, string $prefix): string
    {
        $directory = rtrim($directory, '/');
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the directory for a temporary file.');
        }

        $path = @tempnam($directory, $prefix);
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }

        $real = realpath(dirname($path));
        if ($real === false || $real !== realpath($directory)) {
            // tempnam() fell back to the system's temporary directory.
            @unlink($path);
            throw new \RuntimeException('Cannot create a temporary file in the requested directory.');
        }
        @chmod($path, 0600);

        return $path;
    }
}
