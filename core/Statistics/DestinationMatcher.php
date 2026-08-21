<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

/**
 * Answers the single question "is this installation the statistics
 * receiver?" — the one place allowed to decide it (ARCHITECTURE.md §8.43).
 *
 * The comparison is always between the configured `base_url` setting and
 * the configured `statistics_destination` setting, never against
 * $_SERVER['HTTP_HOST']: the Host header is attacker-supplied on every
 * request, so deriving "am I the receiver?" from it would let a crafted
 * request turn an ordinary installation into one that believes it hosts
 * the receiver module.
 *
 * Pure functions with no dependencies, so the rule is testable in
 * isolation and reusable from both entry points (public/index.php and
 * public/cron.php) before any service is wired.
 */
final class DestinationMatcher
{
    /**
     * Whether two URLs designate the same host.
     *
     * Scheme and port are ignored, the comparison is case-insensitive, and
     * a leading `www.` is equivalent to its absence. Any other subdomain is
     * a different host ("stats.example.be" is not "example.be"). An empty
     * or unparseable URL never matches anything, including another empty
     * one — "unknown" must never read as "same".
     */
    public static function isSameHost(string $urlA, string $urlB): bool
    {
        $hostA = self::normalizeHost($urlA);
        $hostB = self::normalizeHost($urlB);

        if ($hostA === null || $hostB === null) {
            return false;
        }

        return $hostA === $hostB;
    }

    /**
     * The single entry point every caller uses: whether the installation
     * reachable at $baseUrl is the very installation the statistics would
     * be sent to.
     */
    public static function isReceiver(?string $baseUrl, ?string $destination): bool
    {
        if ($baseUrl === null || $destination === null) {
            return false;
        }

        return self::isSameHost($baseUrl, $destination);
    }

    /**
     * The comparable host of a URL, or null when there is none.
     *
     * A bare "example.be" (no scheme) is accepted: `base_url` is admin-typed
     * and a missing scheme is a routine typo, not a reason to silently
     * treat two identical hosts as different.
     */
    private static function normalizeHost(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ((!is_string($host) || $host === '') && !str_contains($url, '://')) {
            // No scheme: parse_url() reports the whole thing as a path.
            // Re-parse with a placeholder scheme rather than string-slicing.
            // Only when the input really has no scheme — prepending one to
            // "https://" would otherwise yield the host "https".
            $host = parse_url('https://' . $url, PHP_URL_HOST);
        }

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));
        // IPv6 literals arrive bracketed from parse_url(); keep them as-is
        // apart from the brackets so two spellings of the same address
        // still compare equal.
        $host = trim($host, '[]');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host !== '' ? $host : null;
    }
}
