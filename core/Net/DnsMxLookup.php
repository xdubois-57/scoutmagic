<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * The MX records of a domain, through the server's own resolver.
 *
 * **No timeout of its own**, and the caller must know it: `dns_get_record()`
 * obeys the system resolver, which on an unreachable network sits for
 * seconds. That is why this class is only ever called from a scheduled
 * task that checks a wall clock between two lookups, and never from a
 * request or from the send path (ARCHITECTURE.md § Seed mailboxes).
 */
class DnsMxLookup implements MxLookup
{
    /** Enough for any real zone; a zone with more is doing something else. */
    public const MAX_HOSTS = 10;

    public function hostsFor(string $domain): ?array
    {
        // Silenced on purpose: PHP warns on NXDOMAIN and on a resolver
        // that did not answer, and neither is this installation's fault.
        // What matters is `false` (could not ask) against `[]` (no MX).
        $answer = @$this->query($domain);
        if ($answer === false) {
            return null;
        }

        $ranked = [];
        foreach ($answer as $record) {
            $host = rtrim(strtolower(trim((string) ($record['target'] ?? ''))), '.');
            if ($host === '') {
                continue;
            }

            $ranked[] = ['pri' => (int) ($record['pri'] ?? 0), 'host' => $host];
        }

        usort(
            $ranked,
            static fn(array $a, array $b): int => [$a['pri'], $a['host']] <=> [$b['pri'], $b['host']]
        );

        return array_slice(array_values(array_unique(array_column($ranked, 'host'))), 0, self::MAX_HOSTS);
    }

    /**
     * The one call to the resolver, alone so a test can replace it
     * without a network — the same seam as `DnsRecordReader::query()`.
     *
     * @return array<int, array<string, mixed>>|false
     */
    protected function query(string $domain): array|false
    {
        return dns_get_record($domain, DNS_MX);
    }
}
