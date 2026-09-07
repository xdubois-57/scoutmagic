<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * The host of a URL, and what may be asked about it.
 *
 * Pure: no lookup, no socket, nothing that can fail slowly. It exists so
 * that {@see DnsRecordReader} and {@see WhoisClient} share ONE idea of what
 * a queryable name is, rather than each inventing a regular expression —
 * and because the answer is a security boundary, not a formatting nicety.
 *
 * **The validation is the injection guard.** A WHOIS query is a line of
 * text terminated by CRLF, sent to a stranger's socket; a "domain" carrying
 * a newline is a second command. The host these classes accept cannot
 * contain one by construction — letters, digits, hyphens and dots, and
 * nothing else — so no escaping is needed anywhere downstream. Every host
 * this application looks up came out of a remote installation's JSON, so
 * the guard is not theoretical.
 */
final class DomainName
{
    /** RFC 1035: 253 characters for a name, 63 for one label. */
    public const MAX_LENGTH = 253;
    public const MAX_LABEL_LENGTH = 63;

    /**
     * How many names one WHOIS lookup may try. See {@see whoisCandidates()}.
     */
    public const MAX_WHOIS_CANDIDATES = 3;

    /**
     * The host part of a URL, lowercased and without its trailing dot, or
     * null when there is nothing usable.
     *
     * An IP literal answers null: neither of the two callers has anything
     * to say about one, and `whois 1.2.3.4` asks a different question of a
     * different registry than the one this application is asking.
     */
    public static function hostOf(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // A bare hostname is a legitimate value in `instance_url` on an
        // installation somebody configured by hand, and parse_url() reads
        // one as a path rather than a host.
        $host = str_contains($url, '//') ? (parse_url($url, PHP_URL_HOST) ?: null) : $url;
        if (!is_string($host)) {
            return null;
        }

        $host = rtrim(strtolower(trim($host)), '.');

        return self::isQueryable($host) ? $host : null;
    }

    /**
     * Whether a name may be sent to a resolver or a WHOIS server as-is.
     *
     * Deliberately narrower than what DNS permits: no underscore, no
     * internationalised label in its Unicode form, no IP literal, at least
     * two labels. Everything this application looks up is a public web
     * host, and a name that fails here is one we would rather not ask
     * about than ask about carefully.
     */
    public static function isQueryable(string $host): bool
    {
        if ($host === '' || strlen($host) > self::MAX_LENGTH) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        // A TLD is never all digits — that shape is an IPv4 address with a
        // label too many, and asking a registry about it wastes a hop.
        return preg_match('/^[0-9]+$/', $labels[count($labels) - 1]) !== 1;
    }

    /** The last label, which is the TLD a registry is found from. */
    public static function tldOf(string $host): ?string
    {
        if (!self::isQueryable($host)) {
            return null;
        }

        $labels = explode('.', $host);

        return $labels[count($labels) - 1];
    }

    /**
     * The names to ask a registry about, most specific first.
     *
     * **Why a list rather than an answer.** Finding the registrable domain
     * of `scouts.example.co.uk` is the public-suffix problem, and solving it
     * properly means shipping and refreshing Mozilla's list — a dependency,
     * a cache and an update path, for a diagnostic field. So this drops the
     * leftmost label instead and lets the registry decide: the first
     * candidate that comes back with a record is the registrable domain, by
     * definition, because the registry is the authority on the question.
     *
     * Bounded at {@see self::MAX_WHOIS_CANDIDATES} and never shorter than
     * two labels, so a deep subdomain costs three queries rather than one
     * per level. `www.` is dropped first: it is a host, never a
     * registration, and asking about it wastes the most likely candidate.
     *
     * @return string[] empty when the host is not queryable at all
     */
    public static function whoisCandidates(string $host): array
    {
        if (!self::isQueryable($host)) {
            return [];
        }

        $labels = explode('.', $host);
        if (count($labels) > 2 && $labels[0] === 'www') {
            array_shift($labels);
        }

        $candidates = [];
        while (count($labels) >= 2 && count($candidates) < self::MAX_WHOIS_CANDIDATES) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }

        return $candidates;
    }
}
