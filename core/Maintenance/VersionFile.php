<?php

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * The installed site version lives in a plain `VERSION` file at the project
 * root, not in the database — a fresh FTP deployment must be able to report
 * its version before the database schema (or even the DB connection) is
 * configured. scripts/release.sh commits this file as part of every
 * release, so it always matches the release's git tag; Core\Maintenance\
 * Task\InstallUpdateHandler overwrites it as the last step of a successful
 * update.
 */
final class VersionFile
{
    private const FILENAME = 'VERSION';
    private const FALLBACK = '0.0.0';

    public static function read(string $basePath): string
    {
        $path = $basePath . '/' . self::FILENAME;
        if (!is_file($path)) {
            return self::FALLBACK;
        }

        $content = trim((string) file_get_contents($path));
        return $content !== '' ? $content : self::FALLBACK;
    }

    public static function write(string $basePath, string $version): void
    {
        file_put_contents($basePath . '/' . self::FILENAME, $version . "\n");
    }
}
