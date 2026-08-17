<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * one. While the site is configured to stay on the development channel
     * ($installedTracksDevChannel), an installed dev/branch build (VERSION
     * content "dev-{sha}", see CommitInfo::shortVersion()) tracks the
     * branch's latest commit and is treated as always more recent than any
     * stable release tag — PHP's version_compare() would otherwise rank
     * "dev" as its lowest special version form and wrongly report a stable
     * release as an upgrade. That guard only makes sense while the channel
     * is still 'dev', though: once an admin switches the configured
     * channel to patch/minor/major (deliberately moving off a leftover dev
     * build back to a numbered release), a real newer release must be
     * detected normally instead of being permanently masked by the
     * previously installed dev build. All "is there a newer release"
     * comparisons go through this instead of calling version_compare()
     * directly.
     */
    public static function isNewerThan(string $candidate, string $installed, bool $installedTracksDevChannel = false): bool
    {
        if ($installedTracksDevChannel && self::isDevBuild($installed)) {
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
