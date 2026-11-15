<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * What a per-address rate limit counts against: the client, not the one
 * address it happens to use for this request (issue #1128).
 *
 * An IPv4 address is one client (or one NAT), so it stays whole. An IPv6
 * subscriber, though, is handed a whole /64 — 2⁶⁴ addresses — and can pick
 * a fresh one for every request: a counter keyed on the full IPv6 address
 * never fills. The /64 is the smallest block a single subscriber is given,
 * so it is the unit a counter has to see. An IPv4 address written in its
 * IPv6-mapped form (`::ffff:192.0.2.1`, what a dual-stack socket reports)
 * is that IPv4 address, and counts with it.
 *
 * Callers still hash the result (blind index) before storing it — an IP
 * prefix is personal data just like the address it comes from
 * (SECURITY.md §11, §16). Anything that is not an IP address comes back
 * unchanged: a rate limit must not merge unrelated garbage into one bucket,
 * nor throw on a value the web server handed over.
 */
final class RateLimitAddress
{
    private const IPV6_PREFIX_BYTES = 8;
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    private function __construct()
    {
    }

    public static function key(string $address): string
    {
        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $address;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }

        $packed = inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        if (str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            $ipv4 = inet_ntop(substr($packed, 12));

            return $ipv4 === false ? $address : $ipv4;
        }

        $network = inet_ntop(
            substr($packed, 0, self::IPV6_PREFIX_BYTES) . str_repeat("\0", 16 - self::IPV6_PREFIX_BYTES)
        );

        return $network === false ? $address : $network . '/64';
    }
}
