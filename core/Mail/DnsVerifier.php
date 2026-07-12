<?php

declare(strict_types=1);

namespace Core\Mail;

class DnsVerifier
{
    /**
     * Check SPF DNS record.
     *
     * @return array{exists: bool, expected: string, actual: ?string}
     */
    public function checkSpf(string $domain, string $mode, ?string $smtpDomain = null): array
    {
        if ($mode === 'smtp' && $smtpDomain !== null) {
            $expected = "v=spf1 include:_spf.{$smtpDomain} ~all";
        } else {
            $expected = 'v=spf1 a mx ~all';
        }

        $records = $this->getTxtRecords($domain);
        $actual = null;

        foreach ($records as $record) {
            if (str_starts_with($record, 'v=spf1')) {
                $actual = $record;
                break;
            }
        }

        $exists = false;
        if ($actual !== null) {
            if ($mode === 'smtp' && $smtpDomain !== null) {
                $exists = str_contains($actual, "include:_spf.{$smtpDomain}")
                    || str_contains($actual, "include:{$smtpDomain}");
            } else {
                $exists = str_contains($actual, 'v=spf1');
            }
        }

        return ['exists' => $exists, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * Check DKIM DNS record.
     *
     * @return array{exists: bool, expected: string, actual: ?string}
     */
    public function checkDkim(string $domain, string $selector, string $expectedPublicKey): array
    {
        $expected = "v=DKIM1; k=rsa; p={$expectedPublicKey}";
        $host = "{$selector}._domainkey.{$domain}";

        $records = $this->getTxtRecords($host);
        $actual = null;

        foreach ($records as $record) {
            if (str_contains($record, 'DKIM1') || str_contains($record, 'k=rsa')) {
                $actual = $record;
                break;
            }
        }

        $exists = false;
        if ($actual !== null) {
            // Check if the public key is present in the record
            $exists = str_contains(str_replace([' ', "\t"], '', $actual), $expectedPublicKey)
                || str_contains($actual, substr($expectedPublicKey, 0, 40));
        }

        return ['exists' => $exists, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * Check DMARC DNS record.
     *
     * @return array{exists: bool, expected: string, actual: ?string}
     */
    public function checkDmarc(string $domain, string $reportEmail): array
    {
        $expected = "v=DMARC1; p=none; rua=mailto:{$reportEmail}";
        $host = "_dmarc.{$domain}";

        $records = $this->getTxtRecords($host);
        $actual = null;

        foreach ($records as $record) {
            if (str_contains($record, 'DMARC1')) {
                $actual = $record;
                break;
            }
        }

        $exists = false;
        if ($actual !== null) {
            $exists = str_contains($actual, "rua=mailto:{$reportEmail}");
        }

        return ['exists' => $exists, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * Get TXT records for a host. Overridable for testing.
     *
     * @return array<string>
     */
    protected function getTxtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $texts = [];
        foreach ($records as $record) {
            if (isset($record['txt'])) {
                $texts[] = $record['txt'];
            }
        }

        return $texts;
    }
}
