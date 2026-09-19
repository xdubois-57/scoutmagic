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
        $ips = self::addressesOf($host);
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
     * That a host resolves, and resolves somewhere it must not be reached.
     *
     * **This is the half of the check above that is worth repeating before
     * every request, and the other half is not.** `resolveHostToPublicIp()`
     * answers null both for a host that points at `10.0.0.5` and for one
     * the resolver could not answer for at all, and those are not the same
     * fact: the first is the attack this guard exists to stop, while the
     * second means the connection about to be made cannot reach anything
     * either. Refusing on it buys no protection and costs an accusation —
     * a location whose address is perfectly good, recorded as « corrigez
     * l'adresse » because a resolver blinked.
     *
     * Used where a stored address is re-checked on use; the save-time
     * check stays strict, because an address nobody can resolve is not one
     * to write down.
     */
    public static function resolvesOutsideThePublicInternet(string $host): bool
    {
        foreach (self::addressesOf($host) as $ip) {
            if (!self::isPublicIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address $host resolves to, or the literal when it is already
     * one. Empty when nothing could answer for it.
     *
     * **Resolve the way the client that follows will resolve.** This check
     * only means something if it sees what cURL will see, and cURL calls
     * `getaddrinfo()`. `dns_get_record()` speaks the DNS protocol and only
     * that — it never consults `/etc/hosts` or any other NSS source — so a
     * host known only to NSS answered « nothing resolves » here while the
     * request that followed connected to it perfectly well, which turns
     * the whole check into a formality for exactly the addresses it exists
     * to refuse.
     *
     * `gethostbyname()` reads NSS but is **IPv4-only**, and that gap was a
     * live hole rather than a theoretical one: a name carrying a single
     * `fd00::1` in `/etc/hosts` was invisible to both of the lookups this
     * used to do, so {@see isStoredHttpsTargetStillSafe()} called it safe
     * and the share's password travelled to a private address in an
     * `Authorization: Basic` header.
     *
     * So `socket_addrinfo_lookup()` — PHP's own `getaddrinfo()`, both
     * families — is asked first and is the one that matters; the other two
     * stay because they cost nothing and still answer when ext-sockets is
     * absent. When it IS absent we cannot see what the client will see,
     * and {@see canSeeWhatTheClientSees()} says so rather than letting
     * this pretend otherwise.
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        foreach (self::throughGetaddrinfo($host) as $ip) {
            if (!in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        $records = array_merge(
            @dns_get_record($host, DNS_A) ?: [],
            @dns_get_record($host, DNS_AAAA) ?: []
        );

        foreach ($records as $record) {
            $ip = ($record['type'] ?? null) === 'AAAA' ? ($record['ipv6'] ?? null) : ($record['ip'] ?? null);
            if (is_string($ip) && $ip !== '' && !in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        }

        // Returns the name unchanged when it cannot resolve, which is how
        // « nothing answered » is told from an address.
        $system = @gethostbyname($host);
        if (
            $system !== $host
            && filter_var($system, FILTER_VALIDATE_IP) !== false
            && !in_array($system, $ips, true)
        ) {
            $ips[] = $system;
        }

        return $ips;
    }

    /**
     * Whether this installation can resolve the way its HTTP clients do.
     *
     * Only `getaddrinfo()` answers for every source the connecting client
     * will consult. Without ext-sockets the two DNS/IPv4 lookups left are
     * strictly narrower than what cURL does, so a « nothing resolved » here
     * stops being evidence of anything — which is what
     * {@see isStoredHttpsTargetStillSafe()} reads this for.
     */
    private static function canSeeWhatTheClientSees(): bool
    {
        return function_exists('socket_addrinfo_lookup')
            && function_exists('socket_addrinfo_explain');
    }

    /**
     * `getaddrinfo()` for both families, or nothing when ext-sockets is
     * not installed.
     *
     * The service argument is required — the lookup returns nothing at all
     * without one — and `443` is right for every caller here, all of which
     * vet an https target. The resolved address sits under a family-shaped
     * key, `sin_addr` for IPv4 and `sin6_addr` for IPv6.
     *
     * @return list<string>
     */
    private static function throughGetaddrinfo(string $host): array
    {
        if (!self::canSeeWhatTheClientSees()) {
            return [];
        }

        $ips = [];
        foreach ([AF_INET, AF_INET6] as $family) {
            $found = @socket_addrinfo_lookup($host, '443', [
                'ai_family' => $family,
                'ai_socktype' => SOCK_STREAM,
            ]);
            if (!is_array($found)) {
                continue;
            }

            foreach ($found as $one) {
                $explained = socket_addrinfo_explain($one);
                $address = $explained['ai_addr'] ?? null;
                if (!is_array($address)) {
                    continue;
                }

                $ip = $address['sin_addr'] ?? $address['sin6_addr'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        return $ips;
    }

    /**
     * The same target check as {@see assertPublicHttpsUrl()}, minus the
     * requirement that the host resolve right now.
     *
     * For a value that was validated strictly when it was saved and is
     * being re-checked before a request goes out. Everything structural —
     * the scheme, embedded credentials, the port — is refused exactly as
     * before; only « the resolver said nothing » stops being a refusal.
     */
    public static function isStoredHttpsTargetStillSafe(string $url, bool $allowCustomPort = false): bool
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return false;
        }
        if (!$allowCustomPort && ($parts['port'] ?? 443) !== 443) {
            return false;
        }

        // « Nothing resolved » is only an acceptable answer when we looked
        // with the resolver the request itself will use. Without it, the
        // silence says nothing about where cURL would go, and sending the
        // share's credentials on that basis is the hole this exists to
        // close — so the lenient branch is the one that goes away, not the
        // check.
        if (self::addressesOf($host) === [] && !self::canSeeWhatTheClientSees()) {
            return false;
        }

        return !self::resolvesOutsideThePublicInternet($host);
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
