<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * Whether a URL is safe to fetch a code artifact from — i.e. an https URL
 * whose host is GitHub's.
 *
 * The self-update path unpacks a downloaded archive over the live PHP tree,
 * so the URL it downloads is security-critical. The release/push webhook
 * payloads carry that URL as free-form JSON, and GitHub download URLs redirect
 * across a small set of GitHub hosts (api.github.com → codeload.github.com;
 * github.com/…/releases/download → objects.githubusercontent.com), so this is
 * checked both on the initial URL and on every redirect hop.
 *
 * Two questions, kept apart on purpose:
 *
 *  - isAllowed() — is this a GitHub https URL at all? It is the per-hop test
 *    of a redirect chain, where the next host is whatever GitHub answers
 *    (objects.githubusercontent.com names no repository).
 *  - isAllowedForRepository() — is this INITIAL URL inside the repository the
 *    site is configured to update from? Every URL the updater is handed (the
 *    webhook payload, the cached « Installer maintenant » address, the queued
 *    task) goes through it, with the owner and repository read from the
 *    settings and never from the payload that carries the URL: a host test
 *    alone accepts an asset of ANY repository on GitHub.
 *
 * This does NOT authenticate the artifact's *contents* — a compromise of the
 * configured GitHub repository, or the webhook secret plus push access to it,
 * still yields a trusted install. Closing that fully requires a signed release
 * (see SECURITY.md §16 and §20).
 */
final class GitHubUrlValidator
{
    /** Every host a legitimate GitHub release/zipball download resolves through. */
    private const ALLOWED_HOSTS = [
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    public static function isAllowed(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if ($scheme !== 'https' || !is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_HOSTS, true);
    }

    /**
     * Whether $url is an https download URL inside github.com/{owner}/{repo}.
     *
     * Only the three hosts an initial URL can live on are accepted, each with
     * the path prefix GitHub gives that repository: a release asset
     * (github.com/{o}/{r}/releases/download/…), an API zipball
     * (api.github.com/repos/{o}/{r}/…) or a codeload archive
     * (codeload.github.com/{o}/{r}/…). The asset hosts a redirect lands on
     * name no repository, so they are never valid as a first URL. Owner and
     * repository compare case-insensitively, as GitHub does, and the prefix
     * ends on a slash so `repo-evil` is not `repo`.
     *
     * A path that could step out of the prefix — a `.` / `..` segment, or an
     * encoded dot, slash or backslash — is refused outright, as are userinfo
     * and any port but 443: the point of the check is that the text it read is
     * the address that gets fetched.
     */
    public static function isAllowedForRepository(string $url, string $owner, string $repo): bool
    {
        $owner = strtolower(trim($owner));
        $repo = strtolower(trim($repo));
        if ($owner === '' || $repo === '' || str_contains($owner, '/') || str_contains($repo, '/')) {
            return false;
        }
        if (!self::isAllowed($url)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#%(2e|2f|5c)#i', $path) === 1 || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        $prefix = match (strtolower((string) ($parts['host'] ?? ''))) {
            'github.com' => "/{$owner}/{$repo}/releases/download/",
            'api.github.com' => "/repos/{$owner}/{$repo}/",
            'codeload.github.com' => "/{$owner}/{$repo}/",
            default => null,
        };

        return $prefix !== null && str_starts_with(strtolower($path), $prefix);
    }
}
