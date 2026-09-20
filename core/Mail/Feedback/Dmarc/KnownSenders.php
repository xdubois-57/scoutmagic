<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

use Core\Mail\Transport\ProviderConnections;

/**
 * Which of the addresses in a report are this unit's own relays
 * (roadmap IT-06).
 *
 * **Without this the screen is a list of IP addresses, and a volunteer
 * closes it.** `203.0.113.77` answers no question anybody has. With it,
 * two questions are answered at a glance: is my own relay authenticating,
 * and who else is sending in my name.
 *
 * The site knows its relays by the SMTP host each provider connects to, so
 * the hosts are resolved to addresses and the report's sources matched
 * against them.
 *
 * **A resolution that fails puts the source in « autres », which is the
 * safe side.** Wrong in that direction, a volunteer investigates their own
 * relay and finds nothing; wrong in the other, an unknown sender is
 * labelled « votre relais » and nobody ever looks at it again. The first
 * wastes ten minutes, the second is the whole point of the screen.
 *
 * **The host itself never reaches a screen.** What is shown is the
 * provider's name — « Brevo » — because a relay hostname is infrastructure
 * and SECURITY.md §11 keeps that out of anything a screenshot can carry.
 */
class KnownSenders
{
    /**
     * Resolved once per request: a report screen matches a few dozen
     * sources against the same handful of relays, and asking the resolver
     * again for each one would turn one page into as many DNS round trips.
     *
     * @var array<string, string>|null address => provider name
     */
    private ?array $addresses = null;

    /**
     * @param array<int|string, string> $providerNames prefix => display name
     */
    public function __construct(
        private ProviderConnections $connections,
        private array $providerNames,
        /** Injected so a test can answer without a resolver. */
        private ?\Closure $resolver = null
    ) {
    }

    /**
     * The provider this address belongs to, or null when it is somebody
     * else's — which includes every address we could not resolve.
     */
    public function nameFor(string $sourceIp): ?string
    {
        return $this->addresses()[$sourceIp] ?? null;
    }

    public function isOwn(string $sourceIp): bool
    {
        return $this->nameFor($sourceIp) !== null;
    }

    /**
     * @return array<string, string>
     */
    private function addresses(): array
    {
        if ($this->addresses !== null) {
            return $this->addresses;
        }

        $addresses = [];

        foreach ($this->providerNames as $prefix => $name) {
            $host = '';

            try {
                $host = trim($this->connections->host((string) $prefix));
            } catch (\Throwable) {
                // A provider whose secret cannot be read is a provider we
                // cannot vouch for, which is « autres » and correct.
                continue;
            }

            if ($host === '') {
                continue;
            }

            foreach ($this->resolve($host) as $address) {
                // First provider wins a shared address rather than the
                // last: two providers behind one relay is a configuration
                // nobody can act on from this screen either way, and
                // stability beats whichever happened to be iterated last.
                $addresses[$address] ??= $name;
            }
        }

        return $this->addresses = $addresses;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        // **Both families, and `dns_get_record` rather than
        // `gethostbyname`.** The latter is IPv4 only, and a relay reached
        // over IPv6 would be filed under « autres » for ever — the exact
        // mislabelling this class exists to prevent, and the gap
        // `Core\Security\SsrfUrlValidator` documents having been bitten by.
        $records = array_merge(
            @dns_get_record($host, DNS_A) ?: [],
            @dns_get_record($host, DNS_AAAA) ?: []
        );

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
