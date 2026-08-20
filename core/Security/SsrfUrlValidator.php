<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * SSRF guard for outbound requests to a URL a user configured — a Web Push
 * subscription endpoint (any identified member), an LLM API endpoint or an
 * S3-compatible storage endpoint (superadmin). Each of these is fetched
 * server-side from inside the hosting network, so a crafted URL could
 * otherwise reach an internal service (a database, a cloud-metadata endpoint,
 * an admin panel bound to localhost) that was never meant to be reachable
 * from outside (audit M4/M5/M6).
 *
 * The IP-range logic here is the same one Modules\Gallery\Service\
 * OgScraperService proved out for link scraping (SECURITY.md §17), factored
 * into one place so the two never drift. Unlike the scraper — which fetches
 * arbitrary http/https pages and pins the resolved IP for the actual
 * connection — this validator only vets an endpoint before it is stored or
 * handed to a library HTTP client (WebPush, the AWS SDK), so it enforces the
 * stricter "https only" and validates every resolved address is public. It
 * does NOT, on its own, close a DNS-rebinding window for a client that
 * re-resolves at connection time; that is why endpoints are re-validated on
 * use, not only when saved.
 */
final class SsrfUrlValidator
{
    public static function isPublicHttpsUrl(string $url, bool $allowCustomPort = false): bool
    {
        try {
            self::assertPublicHttpsUrl($url, $allowCustomPort);
            return true;
        } catch (SsrfValidationException) {
            return false;
        }
    }

    /**
     * @throws SsrfValidationException when the URL is not a safe, public https target
     */
    public static function assertPublicHttpsUrl(string $url, bool $allowCustomPort = false): void
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            throw new SsrfValidationException('URL invalide.');
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new SsrfValidationException('L\'adresse doit être en https.');
        }

        // Embedded credentials (https://user:pass@host/) are an SSRF/auth-bypass
        // trick, refused outright.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfValidationException('L\'adresse ne doit pas contenir d\'identifiants.');
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new SsrfValidationException('Hôte manquant.');
        }

        $port = $parts['port'] ?? 443;
        if (!$allowCustomPort && $port !== 443) {
            throw new SsrfValidationException('Seul le port https par défaut est autorisé.');
        }

        if (self::resolveHostToPublicIp($host) === null) {
            throw new SsrfValidationException('L\'adresse cible n\'est pas une adresse publique.');
        }
    }

    /**
     * Resolves every A/AAAA record for $host (or validates it directly when it
     * is already an IP literal) and returns one public address — but ONLY when
     * every resolved record is public. A hostname resolving to a mix of public
     * and private addresses is refused entirely: which record the network
     * stack uses on a later connection is not controlled here, so a partially
     * private result is treated as fully unsafe.
     */
    public static function resolveHostToPublicIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $host : null;
        }

        $records = array_merge(
            @dns_get_record($host, DNS_A) ?: [],
            @dns_get_record($host, DNS_AAAA) ?: []
        );

        $ips = [];
        foreach ($records as $record) {
            $ip = ($record['type'] ?? null) === 'AAAA' ? ($record['ipv6'] ?? null) : ($record['ip'] ?? null);
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * Rejects loopback, private (RFC1918/RFC4193), link-local — including the
     * cloud-metadata address 169.254.169.254 — and reserved ranges, for both
     * IPv4 and IPv6, plus two shapes PHP's own filter flags don't cover: an
     * IPv4-mapped IPv6 literal (::ffff:127.0.0.1), normalised to its embedded
     * IPv4 form before re-checking, and multicast (224.0.0.0/4 and ff00::/8),
     * which FILTER_FLAG_NO_RES_RANGE does not include.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $embedded = substr($ip, 7);
            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $embedded;
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && $long >= ip2long('224.0.0.0') && $long <= ip2long('239.255.255.255')) {
                return false;
            }
        } elseif (str_starts_with(strtolower($ip), 'ff')) {
            return false;
        }

        return true;
    }
}
