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
 * This does NOT authenticate the artifact's *contents* — a compromise of the
 * configured GitHub repository, or the webhook secret plus push access to it,
 * still yields a trusted install. Closing that fully requires a signed release
 * (see SECURITY.md §16). What this does close: pointing the updater at an
 * arbitrary host (`http://attacker/evil.zip`) and off-host redirects.
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
}
