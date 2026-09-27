<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * Where one domain's mail is delivered: its MX hosts.
 *
 * An interface for one reason: the only caller is a scheduled task, and a
 * test of that task that reached the network would be a test that fails
 * on a train and passes in CI for reasons nobody controls.
 */
interface MxLookup
{
    /**
     * The MX hosts of a domain, most preferred first, lower-cased and
     * without their trailing dot.
     *
     * **Three answers, never two**, the distinction `DnsRecordReader`
     * already draws: a list of hosts; `[]` — the domain answered and has
     * no MX, which is a fact; and `null` — the question could not be
     * asked, which is a gap and must never be stored as a fact.
     *
     * @return list<string>|null
     */
    public function hostsFor(string $domain): ?array;
}
