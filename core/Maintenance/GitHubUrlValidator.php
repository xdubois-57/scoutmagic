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
     * What a release's tag looks like: a version, with at least one dot, and
     * nothing else. A commit sha has no dot, so it can never match — which
     * matters because GitHub resolves a sha in the ref position across the
     * whole fork network, i.e. to a commit a stranger pushed to their fork.
     */
    private const RELEASE_TAG_PATTERN = '/^[vV]?\d+\.\d+(?:\.\d+)*(?:[-+][0-9A-Za-z][0-9A-Za-z.+-]*)?$/';

    /**
     * Whether $url is an https download URL for a release of
     * github.com/{owner}/{repo}.
     *
     * Only the two shapes a release really has are accepted, as a FIRST URL:
     *
     *  - a release asset, `github.com/{o}/{r}/releases/download/…` — a path
     *    that GitHub serves only for files attached to a release of that
     *    repository;
     *  - the API zipball of a release, `api.github.com/repos/{o}/{r}/zipball/{ref}`,
     *    whose ref is exactly ONE path segment AND looks like a version tag
     *    (RELEASE_TAG_PATTERN). A longer ref is how GitHub names a pull
     *    request's head (`refs/pull/{n}/head`) and a bare sha reaches a
     *    commit of a fork: in both the content is written by whoever opened
     *    the pull request or pushed the fork, not published by the
     *    repository. When $tag is given the ref must also BE that tag, which
     *    on its own would not be enough: whoever signs the event writes
     *    `tag_name` and the link both.
     *
     * Not accepted: `codeload.github.com` (nothing hands the updater one as a
     * first URL — it is only ever where the API redirects to, which
     * isAllowed() checks hop by hop) and the asset hosts a redirect lands on
     * (`objects.…`, `release-assets.…`), which name no repository.
     *
     * Owner and repository compare case-insensitively, as GitHub does, and
     * each prefix ends on a slash so `repo-evil` is not `repo`.
     */
    public static function isAllowedForRepository(string $url, string $owner, string $repo, ?string $tag = null): bool
    {
        $owner = strtolower(trim($owner));
        $repo = strtolower(trim($repo));
        if ($owner === '' || $repo === '' || str_contains($owner, '/') || str_contains($repo, '/')) {
            return false;
        }

        $parts = self::fetchableParts($url);
        if ($parts === null) {
            return false;
        }

        return match (strtolower((string) ($parts['host'] ?? ''))) {
            'github.com' => str_starts_with(strtolower((string) $parts['path']), "/{$owner}/{$repo}/releases/download/"),
            'api.github.com' => self::isReleaseZipball((string) $parts['path'], $owner, $repo, $tag),
            default => false,
        };
    }

    /**
     * The parts of an allowlisted URL whose text can be trusted to be the
     * address that gets fetched, or null.
     *
     * A path that could step out of a prefix — a `.` / `..` segment, or an
     * encoded dot, slash or backslash — is refused outright, as are userinfo
     * and any port but 443.
     *
     * @return array<string, mixed>|null
     */
    private static function fetchableParts(string $url): ?array
    {
        if (!self::isAllowed($url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#%(2e|2f|5c)#i', $path) === 1 || str_contains($path, '\\')) {
            return null;
        }
        if (array_intersect(explode('/', $path), ['.', '..']) !== []) {
            return null;
        }
        $parts['path'] = $path;

        return $parts;
    }

    private static function isReleaseZipball(string $path, string $owner, string $repo, ?string $tag): bool
    {
        $prefix = "/repos/{$owner}/{$repo}/zipball/";
        if (!str_starts_with(strtolower($path), $prefix)) {
            return false;
        }

        // The ref keeps its own case: tags are case-sensitive.
        $ref = substr($path, strlen($prefix));
        if (preg_match(self::RELEASE_TAG_PATTERN, $ref) !== 1) {
            return false;
        }

        return $tag === null || $ref === $tag;
    }
}
