<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * The outcome of one WHOIS walk — three states, and the third is not a
 * softer version of the second.
 *
 * **`not_found` and `unavailable` are opposite diagnoses.** The registry
 * answering « ce nom n'est enregistré nulle part » about a domain a site is
 * currently answering on is a hijack or a lapse, and it is one of the more
 * interesting things this receiver can learn. Nobody answering at all —
 * port 43 closed on this host, a TLD with no WHOIS service, a registry
 * having a bad afternoon — is a fact about the receiver's network and says
 * nothing whatsoever about the domain.
 *
 * A `?WhoisResponse` collapsed the two into null, which meant a page could
 * not tell a unit whose domain has lapsed from a unit whose registry we
 * cannot reach. That is why this type exists rather than a nullable.
 */
final class WhoisLookup
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $outcome,
        public readonly ?WhoisResponse $response
    ) {
    }

    public static function found(WhoisResponse $response): self
    {
        return new self(self::FOUND, $response);
    }

    /** A registry answered, and the name is registered nowhere. */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null);
    }

    /** Nobody answered. This is about the network, not about the domain. */
    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, null);
    }
}
