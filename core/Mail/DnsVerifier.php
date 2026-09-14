<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

class DnsVerifier
{
    /**
     * Check the SPF record of ONE domain — and it has to be the domain of
     * the **envelope sender**, never the one in the `From:` header.
     *
     * SPF authorises a sending host for the domain of the `MAIL FROM` of
     * the SMTP conversation (RFC 7208 §2.4). The two are the same address
     * on this site, but only because `Core\Mail\MailService` forces it;
     * callers get the domain from `Core\Mail\MailIdentity::spfDomain()`,
     * which is the one place that says so and the one place a test pins.
     *
     * @return array{exists: bool, expected: string, actual: ?string}
     */
    public function checkSpf(string $domain, string $mode, ?string $smtpHost = null): array
    {
        return $this->checkSpfForHosts(
            $domain,
            $mode === 'smtp' && $smtpHost !== null && $smtpHost !== '' ? [$smtpHost] : []
        );
    }

    /**
     * The same check, for a site that hands its mail to **several** relays
     * in turn (D4).
     *
     * A chain is only as authorised as its least authorised entry: the
     * day the first provider is down, the message leaves through the
     * second, and an SPF record naming only the first fails on exactly
     * the messages the fallback exists to save. So every host in the
     * chain has to be in the record, and a record missing any one of them
     * is « manquant » rather than « partiel » — there is no useful middle
     * state to report, since the operator's next action is the same
     * either way.
     *
     * An empty list is the local send on its own: nothing specific to
     * authorise beyond the domain's own hosts, so the suggestion falls
     * back to `a mx`.
     *
     * @param list<string> $sendingHosts every relay the site may hand a
     *   message to, deduplicated by the caller or not — this does it.
     * @return array{exists: bool, expected: string, actual: ?string}
     */
    public function checkSpfForHosts(string $domain, array $sendingHosts): array
    {
        $records = $this->getTxtRecords($domain);
        $actual = null;

        foreach ($records as $record) {
            if (str_starts_with($record, 'v=spf1')) {
                $actual = $record;
                break;
            }
        }

        $mechanisms = self::mechanismsFor($sendingHosts);
        $published = self::tokensOf($actual);

        $exists = false;
        if ($actual !== null) {
            $exists = true;
            foreach ($mechanisms as $mechanism) {
                if (!in_array($mechanism, $published, true)) {
                    $exists = false;
                    break;
                }
            }
        }

        return [
            'exists' => $exists,
            'expected' => $this->buildSpfExpected($actual, $mechanisms),
            'actual' => $actual,
        ];
    }

    /**
     * An SPF record's mechanisms, as whole tokens.
     *
     * **Whole tokens and never a substring search.** An SPF record is a
     * space-separated list, and `str_contains($record, 'a:relais.example')`
     * is satisfied by `a:relais.example.net` — a different host, on a
     * different domain, that the operator may not even control. The
     * reading would then report « en place » and propose no change, and
     * the relay that is actually missing would stay unauthorised with the
     * screen saying it was fine.
     *
     * @return list<string>
     */
    private static function tokensOf(?string $record): array
    {
        if ($record === null) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', trim($record)) ?: [],
            static fn(string $token) => $token !== ''
        ));
    }

    /**
     * `a:{host}` per relay, in the caller's order, without duplicates.
     *
     * @param list<string> $sendingHosts
     * @return list<string>
     */
    private static function mechanismsFor(array $sendingHosts): array
    {
        $mechanisms = [];
        foreach ($sendingHosts as $host) {
            $host = trim($host);
            if ($host === '') {
                continue;
            }
            $mechanism = 'a:' . $host;
            if (!in_array($mechanism, $mechanisms, true)) {
                $mechanisms[] = $mechanism;
            }
        }

        return $mechanisms;
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
    /**
     * @param list<string> $mechanisms every `a:{host}` the record must carry
     */
    private function buildSpfExpected(?string $actual, array $mechanisms): string
    {
        if ($actual === null) {
            return $mechanisms !== []
                ? 'v=spf1 ' . implode(' ', $mechanisms) . ' ~all'
                : 'v=spf1 a mx ~all';
        }

        if ($mechanisms === []) {
            // Local send only: the existing record is already a valid SPF
            // record (a/mx mechanisms aren't specifically required), so
            // there's nothing to merge in.
            return $actual;
        }

        // Whole tokens here too, for the reason {@see self::tokensOf()}
        // gives: a substring test would decide the record already carries
        // a mechanism it does not, and propose nothing.
        $published = self::tokensOf($actual);
        $missing = array_values(array_filter(
            $mechanisms,
            static fn(string $mechanism) => !in_array($mechanism, $published, true)
        ));

        if ($missing === []) {
            return $actual;
        }

        $tokens = preg_split('/\s+/', trim($actual)) ?: [];
        $last = end($tokens);

        if ($last !== false && preg_match('/^[+\-~?]?all$/i', $last)) {
            array_pop($tokens);
            $tokens = array_merge($tokens, $missing, [$last]);
        } else {
            $tokens = array_merge($tokens, $missing);
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
