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

    /**
     * Whether $candidate is a genuinely newer version than the installed
     * one. A dev/branch build (VERSION content "dev-{sha}", see
     * CommitInfo::shortVersion()) tracks the branch's latest commit and is
     * therefore always more recent than any stable release tag — but
     * PHP's version_compare() ranks "dev" as its lowest special version
     * form, so it would wrongly report a stable release as an upgrade
     * over an installed dev build. All "is there a newer release"
     * comparisons go through this instead of calling version_compare()
     * directly.
     */
    public static function isNewerThan(string $candidate, string $installed): bool
    {
        if (self::isDevBuild($installed)) {
            return false;
        }

        return version_compare($candidate, $installed, '>');
    }

    /**
     * True for the "dev-{sha}" format Task\InstallUpdateHandler writes for
     * development-mode installs (GitHubWebhookService::handlePushEvent()'s
     * version_to convention).
     */
    public static function isDevBuild(string $version): bool
    {
        return preg_match('/^dev-[0-9a-f]{7,40}$/', $version) === 1;
    }
}
