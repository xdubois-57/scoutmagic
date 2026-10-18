<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;
use Core\Config\SettingService;
use Core\Mail\DkimManager;
use Core\Mail\DnsCheckMemory;
use Core\Mail\DnsRecordsReading;
use Core\Mail\DnsSnapshot;
use Core\Mail\DnsVerifier;
use Core\Mail\MailIdentity;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailProviderDirectory;

/**
 * Whether the SPF, DKIM and DMARC records outgoing mail depends on are
 * still published (issue #718 § C point 15).
 *
 * The reading existed — Configuration › Courrier sortant › Authentification
 * keeps one in {@see DnsCheckMemory} — but only a superadmin pressing
 * « Vérifier le DNS » ever took it. A record deleted at the registrar, or
 * a relay added without its `a:` mechanism, stayed « publié » for as long
 * as nobody looked, while the families' mailboxes quietly started sorting
 * the unit's mail as spam. The daily pass now takes the same reading
 * ({@see DnsRecordsReading}), keeps it where the page reads it, and raises
 * an alert when a record is missing.
 *
 * **Only a definite « absent » triggers.** A resolver that could not be
 * asked reads as an empty answer inside {@see DnsVerifier}, which is right
 * for a screen with somebody watching it and wrong at four in the morning:
 * every host is probed first, once ({@see DnsSnapshot}), and a single failed
 * lookup makes the whole reading inconclusive — and leaves the stored one
 * alone. A record the
 * site cannot judge (no DKIM key yet, no DMARC report asked for, an SPF
 * record with no relay to look for) is not missing either.
 *
 * A site whose sender or selector is not set has nothing to check: that
 * is the setup's business, not this alert's, and it re-arms.
 */
final class MailDnsRecordsCheck implements OperationalCheck
{
    public const KEY = 'mail_dns_records';

    /** Where the records to publish are listed. */
    public const AUTHENTICATION_PATH = '/config/courrier-sortant/authentification';
    public const ACTION_LABEL = 'Voir les enregistrements à publier';
    public const ATTENTION_WHY = 'Sans eux, les messageries des familles classent plus souvent le courrier du site '
        . 'en indésirables, ou le refusent. La page Authentification donne la valeur exacte à publier chez le '
        . 'gestionnaire du nom de domaine.';

    private const NAMES = [
        DnsCheckMemory::SPF => 'SPF',
        DnsCheckMemory::DKIM => 'DKIM',
        DnsCheckMemory::DMARC => 'DMARC',
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly DnsVerifier $dns,
        private readonly DkimManager $dkim,
        private readonly LaneChainRepository $chains,
        private readonly MailProviderDirectory $directory,
        /**
         * Whether the relays' hosts could be read at all. They live in the
         * encrypted secrets, and a directory built from secrets that could
         * not be read lists every relay with an empty host — which reads
         * exactly like « no relay », and would let an SPF alert re-arm on a
         * reading that never looked for anything.
         */
        private readonly bool $relayHostsReadable = true
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Enregistrements DNS de l\'e-mail';
    }

    public function read(): AlertReading
    {
        $identity = MailIdentity::fromSettings($this->settings);
        $selector = (string) ($this->settings->get('dkim_selector') ?? '');
        if ($identity->spfDomain() === '' || $selector === '') {
            return new AlertReading(false, true, 'non vérifiés', '', '');
        }

        if (!$this->relayHostsReadable) {
            return AlertReading::inconclusive();
        }

        try {
            $hosts = DnsRecordsReading::sendingHosts($this->chains->all(), $this->directory->all());
            $publicKey = $this->dkim->hasKey() ? $this->dkim->getPublicKey() : null;
        } catch (\Throwable) {
            // The relay list could not be read: an SPF verdict without it
            // could not falsify anything (DnsRecordsReading::sendingHosts()).
            return AlertReading::inconclusive();
        }

        // One lookup per host, shared by the probe and the verdict: two
        // would let a resolver that answered the probe and failed the check
        // a moment later turn « could not ask » back into « absent ».
        $dns = new DnsSnapshot($this->dns);
        foreach (DnsRecordsReading::queriedHosts($identity, $selector, $publicKey !== null) as $host) {
            if ($dns->txtRecordsFor($host) === null) {
                return AlertReading::inconclusive();
            }
        }

        $records = DnsRecordsReading::take($dns, $identity, $selector, $publicKey, $hosts);
        try {
            // Kept where the Authentification page reads it, so the page
            // and this alert never tell two stories.
            DnsCheckMemory::remember(
                $this->settings,
                $identity->spfDomain(),
                $identity->dkimDomain(),
                $selector,
                $identity->configuredDmarcReportAddress(),
                $records
            );
        } catch (\Throwable) {
            // The verdict below stands on the reading, not on its storage.
        }

        $missing = [];
        foreach (self::NAMES as $key => $name) {
            if (self::isMissing($records[$key] ?? [])) {
                $missing[] = $name;
            }
        }

        // An SPF record with no relay to look for in it (« non vérifiable »)
        // re-arms too: that state means no relay is configured, so nothing
        // requires a mechanism — and reading it as inconclusive would leave
        // a site that sends only from its own server unable ever to clear
        // a DKIM or DMARC alert once fixed. The value says « aucun absent »
        // rather than « publiés », which it may not be.
        if ($missing === []) {
            return new AlertReading(false, true, 'aucun absent', '', '');
        }

        $list = self::listOf($missing);
        $plural = count($missing) > 1;

        return new AlertReading(
            overTrigger: true,
            underRearm: false,
            value: $list . ' absent' . ($plural ? 's' : ''),
            title: ($plural ? 'Les enregistrements ' : 'L\'enregistrement ') . $list . ' du domaine '
                . $identity->dkimDomain() . ($plural ? ' manquent ou sont incomplets.' : ' manque ou est incomplet.'),
            why: self::ATTENTION_WHY,
            actionUrl: self::AUTHENTICATION_PATH,
            actionLabel: self::ACTION_LABEL
        );
    }

    /**
     * A definite « absent » — the same reading as DnsCheckMemory::state():
     * a record the site cannot judge (no key yet, no report asked for, no
     * relay to look for) is not missing.
     *
     * @param array<string, mixed> $record
     */
    private static function isMissing(array $record): bool
    {
        if (!empty($record['key_missing']) || !empty($record['not_requested']) || !empty($record['unverifiable'])) {
            return false;
        }

        return empty($record['exists']);
    }

    /** @param list<string> $names « SPF », « SPF et DKIM », « SPF, DKIM et DMARC » */
    private static function listOf(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' et ' . $last;
    }
}
