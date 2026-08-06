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
    public function checkSpf(string $domain, string $mode, ?string $smtpHost = null): array
    {
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
            if ($mode === 'smtp' && $smtpHost !== null) {
                $exists = str_contains($actual, "a:{$smtpHost}");
            } else {
                $exists = str_contains($actual, 'v=spf1');
            }
        }

        $expected = $this->buildSpfExpected($actual, $mode, $smtpHost);

        return ['exists' => $exists, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * A domain may only have one SPF TXT record — a second one is invalid
     * per the SPF spec (RFC 7208 §4.5) and providers will typically pick
     * one arbitrarily. So when a record already exists, this never
     * proposes a fresh replacement: it inserts the missing mechanism into
     * the existing record (right before the trailing "all" qualifier),
     * preserving every mechanism and the qualifier strictness the
     * operator already chose rather than silently downgrading it.
     *
     * The inserted mechanism is "a:{smtpHost}" — the exact host given,
     * authorizing whatever IP that hostname's own A/AAAA record resolves
     * to — never a guessed "include:_spf.<domain>". That convention only
     * works for third-party providers that publish their own dedicated
     * _spf.<their-domain> record for customers to reference (Google,
     * Mailgun, etc.) — fabricating one for an arbitrary SMTP host almost
     * always points at a TXT record that doesn't exist, which causes SPF
     * evaluation to fail with a PermError, worse than no suggestion at
     * all. "a:" is always syntactically safe: the host is one the
     * operator is actively connecting to for SMTP submission, so it
     * necessarily resolves to something.
     */
    private function buildSpfExpected(?string $actual, string $mode, ?string $smtpHost): string
    {
        $mechanism = ($mode === 'smtp' && $smtpHost !== null) ? "a:{$smtpHost}" : null;

        if ($actual === null) {
            return $mechanism !== null ? "v=spf1 {$mechanism} ~all" : 'v=spf1 a mx ~all';
        }

        if ($mechanism === null) {
            // Local mode: the existing record is already a valid SPF
            // record (a/mx mechanisms aren't specifically required), so
            // there's nothing to merge in.
            return $actual;
        }

        if (str_contains($actual, $mechanism)) {
            return $actual;
        }

        $tokens = preg_split('/\s+/', trim($actual)) ?: [];
        $last = end($tokens);

        if ($last !== false && preg_match('/^[+\-~?]?all$/i', $last)) {
            array_pop($tokens);
            $tokens[] = $mechanism;
            $tokens[] = $last;
        } else {
            $tokens[] = $mechanism;
        }

        return implode(' ', $tokens);
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

        $expected = $this->buildDmarcExpected($actual, $reportEmail);

        return ['exists' => $exists, 'expected' => $expected, 'actual' => $actual];
    }

    /**
     * A domain may only have one _dmarc TXT record, so when one already
     * exists this never proposes a fresh p=none replacement — that would
     * silently downgrade a policy the operator may have deliberately
     * tightened (e.g. p=quarantine/p=reject). Only the missing rua= report
     * address is appended; an existing rua= is left untouched even if it
     * points elsewhere, since that may be an intentional third-party
     * monitoring address.
     */
    private function buildDmarcExpected(?string $actual, string $reportEmail): string
    {
        if ($actual === null) {
            return "v=DMARC1; p=none; rua=mailto:{$reportEmail}";
        }

        if (str_contains($actual, 'rua=')) {
            return $actual;
        }

        return rtrim($actual, '; ') . '; rua=mailto:' . $reportEmail;
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
