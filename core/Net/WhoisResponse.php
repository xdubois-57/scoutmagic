<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * One answer from one WHOIS server, kept whole.
 *
 * The raw text is the point. `Service\WhoisRegistration` reads a handful of
 * fields out of it, every registry prints them differently, and the only
 * way to tell a wrong reading from a right one is to look at what the
 * server actually wrote — the same reason `support_mail_probes` keeps the
 * header block beside the verdict it was read from (ARCHITECTURE.md
 * §8.49quater).
 */
final class WhoisResponse
{
    /**
     * @param string $domain the name that answered — not necessarily the
     *   host asked about, since a subdomain has no registration of its own
     * @param string $server the server whose words these are
     * @param string $raw the response, verbatim and bounded
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $server,
        public readonly string $raw
    ) {
    }
}
