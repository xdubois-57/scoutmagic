<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Mail\Transport\LaneEntry;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;

/**
 * One reading of the three records outgoing mail depends on — SPF, DKIM,
 * DMARC — in the shape {@see DnsCheckMemory::remember()} keeps.
 *
 * Two callers take it, and they must not drift apart: the « Vérifier le
 * DNS » button on Configuration › Courrier sortant › Authentification,
 * with somebody watching, and the daily operational pass
 * ({@see \Core\Alert\Check\MailDnsRecordsCheck}, issue #718 § C point 15),
 * with nobody. Until the second existed the stored reading was only ever
 * as fresh as the last time a superadmin pressed the button — a record
 * deleted at the registrar stayed « publié » for as long as nobody looked.
 */
final class DnsRecordsReading
{
    /**
     * The hosts SPF must authorise: every relay an enabled lane uses. The
     * local send contributes none — it leaves from the web server itself,
     * and no `a:` mechanism names that.
     *
     * @param array<string, array<int, LaneEntry>> $chains lane value => entries
     * @param array<int, MailProvider> $providers
     * @return list<string>
     */
    public static function sendingHosts(array $chains, array $providers): array
    {
        $hosts = [];
        foreach ($providers as $provider) {
            if ($provider->isLocal() || $provider->host === '' || in_array($provider->host, $hosts, true)) {
                continue;
            }
            if (self::isOnAnEnabledLane($chains, $provider->id)) {
                $hosts[] = $provider->host;
            }
        }

        return $hosts;
    }

    /**
     * The three records, read now.
     *
     * @param list<string> $sendingHosts
     * @param string|null $dkimPublicKey null when no key pair exists yet:
     *                                   there is no value to publish, not even a placeholder
     * @return array<string, array<string, mixed>>
     */
    public static function take(
        DnsVerifier $dns,
        MailIdentity $identity,
        string $selector,
        ?string $dkimPublicKey,
        array $sendingHosts
    ): array {
        $dkimDomain = $identity->dkimDomain();

        return [
            DnsCheckMemory::SPF => $dns->checkSpfForHosts($identity->spfDomain(), $sendingHosts),
            DnsCheckMemory::DKIM => $dkimPublicKey !== null
                ? $dns->checkDkim($dkimDomain, $selector, $dkimPublicKey)
                : ['key_missing' => true],
            // Only when an address was actually asked for: a site that
            // wants no reports needs no record, and proposing one would be
            // pushing an edit nobody asked for (D12 — `rua` only, never `ruf`).
            DnsCheckMemory::DMARC => $identity->configuredDmarcReportAddress() === ''
                ? ['not_requested' => true]
                : $dns->checkDmarc($dkimDomain, $identity->dmarcReportAddress()),
        ];
    }

    /**
     * The TXT hosts a reading queries — for a caller that must tell « the
     * record is absent » from « the resolver could not be asked », which
     * the three checks above do not ({@see DnsVerifier::txtRecordsOrNone()}).
     *
     * @return list<string>
     */
    public static function queriedHosts(MailIdentity $identity, string $selector, bool $withDkim): array
    {
        $hosts = [$identity->spfDomain()];
        if ($withDkim) {
            $hosts[] = $selector . '._domainkey.' . $identity->dkimDomain();
        }
        if ($identity->configuredDmarcReportAddress() !== '') {
            $hosts[] = '_dmarc.' . $identity->dkimDomain();
        }

        return $hosts;
    }

    /** @param array<string, array<int, LaneEntry>> $chains */
    private static function isOnAnEnabledLane(array $chains, int $providerId): bool
    {
        foreach (MailLane::ordered() as $lane) {
            foreach ($chains[$lane->value] ?? [] as $entry) {
                if ($entry->providerId === $providerId && $entry->enabled) {
                    return true;
                }
            }
        }

        return false;
    }
}
