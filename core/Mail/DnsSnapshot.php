<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * A DnsVerifier that asks the resolver once per host, and answers every
 * later question about that host from the first answer.
 *
 * For a caller that must tell « absent » from « could not ask » before it
 * believes a verdict ({@see \Core\Alert\Check\MailDnsRecordsCheck}): it
 * probes each host through txtRecordsFor(), which keeps the null, and then
 * hands this same object to the record checks, which collapse a failure to
 * « absent ». Without the snapshot the two would be two lookups, and a
 * resolver that answered the probe and failed the check a moment later
 * would produce exactly the false « absent » the probe exists to prevent.
 */
final class DnsSnapshot extends DnsVerifier
{
    /** @var array<string, ?array<string>> */
    private array $answers = [];

    public function __construct(private readonly DnsVerifier $resolver)
    {
    }

    protected function readTxtRecords(string $host): ?array
    {
        if (!array_key_exists($host, $this->answers)) {
            $this->answers[$host] = $this->resolver->readTxtRecords($host);
        }

        return $this->answers[$host];
    }
}
