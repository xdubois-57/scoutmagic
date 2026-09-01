<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * The cache-busting token for static assets — the release version plus a
 * fingerprint of the stylesheets and scripts actually on disk.
 *
 * **`VERSION` alone was never enough, and production proved it twice.**
 * `public/sw.js` names its app-shell cache after the `?v=` of its own
 * registration URL and serves every precached asset with
 * `ignoreSearch: true`, so `/assets/css/components.css` comes out of that
 * cache whatever query string the page asks for. The token was the
 * release version, which does not change between two deploys of the same
 * release — and `main` sits at one release across dozens of merges. The
 * result is a page rendering NEW markup against the PREVIOUS stylesheet,
 * for as long as nobody cuts a release: a ticket description that lost
 * its `white-space: pre-wrap` and ran off the side of a phone, and before
 * that a collapse label that showed both of its states at once (see
 * `public/assets/js/collapse-label.js`, which worked around it by moving
 * the rule out of CSS entirely — the workaround this replaces).
 *
 * **The fingerprint is stat, not content.** Name, size and mtime of every
 * `public/assets/css/*.css`, `public/assets/js/*.js` and `public/sw.js`,
 * hashed. Reading the files would be the stronger signal and costs an
 * order of magnitude more on the shared hosting this runs on, for a
 * distinction that does not arise: a deploy writes files, and a file that
 * is written has a new mtime.
 *
 * Deliberately NOT covering `assets/vendor/`: Bootstrap changes on a
 * dependency upgrade, which is a release, which moves `VERSION` anyway.
 *
 * Deliberately NOT the offline CONTENT cache's version either — that one
 * travels by postMessage as `app_version` and names caches full of pages
 * a member downloaded on purpose. Purging those every time a stylesheet
 * changes would empty somebody's offline copy of the site to fix a
 * margin.
 */
final class AssetVersion
{
    /** @var array<string, string> project root => resolved token */
    private static array $memo = [];

    /**
     * @param string $projectRoot the directory holding `public/`
     * @param string $appVersion  what `VersionFile::read()` answered
     */
    public static function forProject(string $projectRoot, string $appVersion): string
    {
        $key = $projectRoot . '|' . $appVersion;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $fingerprint = self::fingerprint($projectRoot);

        return self::$memo[$key] = $fingerprint === null
            ? $appVersion
            : $appVersion . '-' . $fingerprint;
    }

    /**
     * Test seam only — the memo is per project root, and a test that
     * touches a file under one has to be able to ask again.
     */
    public static function forgetMemo(): void
    {
        self::$memo = [];
    }

    private static function fingerprint(string $projectRoot): ?string
    {
        $files = array_merge(
            glob($projectRoot . '/public/assets/css/*.css') ?: [],
            glob($projectRoot . '/public/assets/js/*.js') ?: [],
            glob($projectRoot . '/public/sw.js') ?: []
        );

        if ($files === []) {
            return null;
        }

        sort($files);

        $signature = '';
        foreach ($files as $file) {
            $stat = @stat($file);
            if ($stat === false) {
                continue;
            }
            $signature .= basename($file) . ':' . $stat['mtime'] . ':' . $stat['size'] . '|';
        }

        return $signature === '' ? null : substr(hash('sha256', $signature), 0, 10);
    }
}
