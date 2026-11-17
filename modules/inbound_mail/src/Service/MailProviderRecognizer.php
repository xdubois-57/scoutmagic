<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Mailbox\MailProvider;

/**
 * Which provider an address belongs to, so the form can prefill its
 * server, port and encryption (D8).
 *
 * **The domain first, then its MX.** `gmail.com` or `hotmail.be` say who
 * the provider is on their own; an address on the unit's own domain says
 * nothing until its mail exchangers are read — a domain whose MX points
 * at Google is a Google Workspace box, at Microsoft a Microsoft 365 one.
 * The lookup is injected, so a test never touches the network; by default
 * it is `dns_get_record()`, and a lookup that fails or times out is simply
 * « not recognised »: the operator then types the three settings.
 *
 * Unknown is an honest answer, never a guess: no setting is invented for
 * a domain this list does not know.
 */
class MailProviderRecognizer
{
    /**
     * Address domains that name their provider outright.
     *
     * @var array<string, string> domain => provider key
     */
    private const DOMAINS = [
        'gmail.com' => 'gmail',
        'googlemail.com' => 'gmail',
        'outlook.com' => 'outlook',
        'outlook.fr' => 'outlook',
        'outlook.be' => 'outlook',
        'hotmail.com' => 'outlook',
        'hotmail.fr' => 'outlook',
        'hotmail.be' => 'outlook',
        'live.com' => 'outlook',
        'live.fr' => 'outlook',
        'live.be' => 'outlook',
        'msn.com' => 'outlook',
        'ik.me' => 'infomaniak',
        'ikmail.com' => 'infomaniak',
        'etik.com' => 'infomaniak',
    ];

    /**
     * Suffixes of a mail exchanger's name, and the provider they betray.
     *
     * @var array<string, string> suffix => provider key
     */
    private const MX_SUFFIXES = [
        '.google.com' => 'google_workspace',
        '.googlemail.com' => 'google_workspace',
        '.protection.outlook.com' => 'microsoft_365',
        '.mail.ovh.net' => 'ovh',
        '.ovh.net' => 'ovh',
        '.infomaniak.ch' => 'infomaniak',
        '.infomaniak.com' => 'infomaniak',
    ];

    /**
     * Top-level names that never resolve (RFC 2606, RFC 6761): asking the
     * DNS about them can only wait for a timeout, which on some resolvers
     * is several seconds of a request — on a single-threaded server, of
     * every request behind it.
     */
    private const NEVER_RESOLVED = ['test', 'invalid', 'example', 'localhost', 'local'];

    /** @var \Closure(string): list<string> */
    private \Closure $mxResolver;

    /**
     * @param (\Closure(string): list<string>)|null $mxResolver the mail
     *   exchangers of a domain, by name; `dns_get_record()` when null
     */
    public function __construct(?\Closure $mxResolver = null)
    {
        $this->mxResolver = $mxResolver ?? static fn(string $domain): array => self::dnsMx($domain);
    }

    /**
     * @param bool $lookUpMx false to answer from the domain alone, without
     *   any network: what a page render does, so that a slow resolver never
     *   holds a page up — the form's script asks the full question after.
     */
    public function recognize(string $address, bool $lookUpMx = true): ?MailProvider
    {
        $domain = self::domainOf($address);
        if ($domain === null) {
            return null;
        }

        if (isset(self::DOMAINS[$domain])) {
            return self::provider(self::DOMAINS[$domain]);
        }

        $topLevel = substr($domain, (int) strrpos($domain, '.') + 1);
        if (!$lookUpMx || in_array($topLevel, self::NEVER_RESOLVED, true)) {
            return null;
        }

        foreach (($this->mxResolver)($domain) as $exchanger) {
            $exchanger = '.' . strtolower(rtrim(trim($exchanger), '.'));
            foreach (self::MX_SUFFIXES as $suffix => $key) {
                if (str_ends_with($exchanger, $suffix)) {
                    return self::provider($key);
                }
            }
        }

        return null;
    }

    /**
     * The settings of each provider this class knows.
     */
    public static function provider(string $key): ?MailProvider
    {
        return match ($key) {
            'gmail' => new MailProvider(
                'gmail',
                'Gmail',
                'imap.gmail.com',
                993,
                'ssl',
                MailProvider::HELP_GMAIL
            ),
            'google_workspace' => new MailProvider(
                'google_workspace',
                'Google Workspace',
                'imap.gmail.com',
                993,
                'ssl',
                MailProvider::HELP_GMAIL
            ),
            'outlook' => new MailProvider(
                'outlook',
                'Outlook',
                'outlook.office365.com',
                993,
                'ssl',
                MailProvider::HELP_OUTLOOK
            ),
            'microsoft_365' => new MailProvider(
                'microsoft_365',
                'Microsoft 365',
                'outlook.office365.com',
                993,
                'ssl',
                MailProvider::HELP_OUTLOOK
            ),
            'ovh' => new MailProvider('ovh', 'OVH', 'ssl0.ovh.net', 993, 'ssl'),
            'infomaniak' => new MailProvider('infomaniak', 'Infomaniak', 'mail.infomaniak.com', 993, 'ssl'),
            default => null,
        };
    }

    /**
     * The domain of an address, lowercased, or null when it has none a
     * resolver could be asked about.
     */
    public static function domainOf(string $address): ?string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }

        $domain = strtolower(trim(substr($address, $at + 1)));

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain) === 1 ? $domain : null;
    }

    /**
     * @return list<string>
     */
    private static function dnsMx(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);
        if (!is_array($records)) {
            return [];
        }

        $exchangers = [];
        foreach ($records as $record) {
            if (is_string($record['target'] ?? null)) {
                $exchangers[] = $record['target'];
            }
        }

        return $exchangers;
    }
}
