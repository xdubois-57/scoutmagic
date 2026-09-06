<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * The handful of facts worth reading out of a WHOIS response.
 *
 * **It reads five things and refuses to read a sixth.** Registrar, the
 * three dates and the name servers — who holds the registration, when it
 * was made, when it was last touched, when it lapses, and where the zone
 * is served from. That is what turns « le site ne répond plus » into an
 * answer.
 *
 * **The registrant is deliberately not among them**, and that is a rule
 * rather than an omission. A unit's domain is often registered by a
 * volunteer in their own name, so `Registrant Name` and its neighbours are
 * a natural person's identity (§7.9) — and a parsed copy of them would be
 * a clear-text column, sortable, searchable and exportable, which is
 * exactly what a raw response kept encrypted and read by one person is
 * not. What the registry sent is kept whole; what this application
 * *understands* stops at the organisational half.
 *
 * **Every registry prints these differently**, which is why the reading is
 * an alias table and why {@see WhoisResponse::$raw} is kept beside the
 * result. A reading is not evidence: the only way to tell a right one from
 * a wrong one is to look at what the server actually wrote, the same
 * reason `support_mail_probes` keeps its header block.
 */
final class WhoisRegistration
{
    /**
     * The keys each fact is printed under, lowercased.
     *
     * Drawn from what the registries this fleet actually meets emit: the
     * ICANN gTLD template (`Registrar:`, `Creation Date:`), DNS Belgium's
     * `.be` (`Registered:`, `Name:` inside a `Registrar:` block), AFNIC's
     * `.fr` (`created:`, `Expiry Date:`) and RIPE-style `.eu`.
     *
     * @var array<string, string[]>
     */
    private const ALIASES = [
        'registrar' => ['registrar', 'registrar name', 'sponsoring registrar', 'registrar organization'],
        'created_at' => ['creation date', 'created', 'registered', 'registered on', 'domain registration date'],
        'updated_at' => ['updated date', 'last updated', 'changed', 'last modified'],
        'expires_at' => ['registry expiry date', 'expiry date', 'expiration date', 'expires', 'paid-till'],
        'status' => ['domain status', 'status', 'state'],
    ];

    /** The keys a name server is printed under. */
    private const NAME_SERVER_KEYS = ['name server', 'nserver', 'nameserver', 'name servers'];

    /**
     * Blocks whose contents are a person, where no key is read at all.
     *
     * The alias table above is deliberately generous — registries print
     * `changed:`, `status:` and `state:` under a dozen spellings — and
     * that generosity turns against the rule this class exists to keep
     * once the reader is inside a contact block: AFNIC prints `changed:`
     * under each `nic-hdl`, and an American registrant's address block
     * prints `State:`. Either would land a natural person's data in
     * `whois_registration`, a clear-text, filterable, exportable column
     * (§7.9) — the exact outcome « le titulaire n'est jamais analysé »
     * promises will not happen. `$block` persists until the next heading,
     * so the guard has to be here rather than at the `Registrant:` line.
     *
     * @var string[]
     */
    private const PERSONAL_BLOCKS = [
        'registrant', 'holder', 'owner', 'contact', 'admin', 'admin contact',
        'administrative contact', 'tech', 'tech contact', 'technical contact',
        'billing contact', 'abuse contact',
    ];

    /** A registration has a handful of these; a zone dump is not our business. */
    public const MAX_NAME_SERVERS = 10;

    /** Long enough for a registrar's full legal name, short enough for a cell. */
    public const MAX_VALUE_LENGTH = 200;

    /**
     * @return array{
     *     registrar: ?string, created_at: ?string, updated_at: ?string,
     *     expires_at: ?string, status: ?string, name_servers: string[]
     * }
     */
    public static function parse(string $raw): array
    {
        $found = [];
        $nameServers = [];

        // The heading of the block being read, when a registry opens one.
        //
        // **DNS Belgium earns this**, and it is the registry most of this
        // fleet answers on: `.be` prints `Registrar:` with nothing after
        // it and the name on an indented `Name:` line underneath, and
        // `Nameservers:` followed by bare hostnames with no key at all. A
        // flat `key: value` reading finds neither, and reports « registrar
        // non renseigné » on a perfectly ordinary Belgian record.
        $block = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            // A WHOIS body is `key: value`, with comment lines prefixed by
            // % or #. Anything else is the legal notice at the bottom.
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '%' || $line[0] === '#') {
                continue;
            }

            $separator = strpos($line, ':');
            if ($separator === false) {
                // Inside a name-server block, a bare hostname is a name
                // server. Anywhere else it is prose.
                if ($block === 'nameservers' && DomainName::isQueryable(strtolower($line))) {
                    self::addNameServer($nameServers, $line);
                }

                continue;
            }

            $key = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));

            if ($value === '') {
                $block = $key;
                continue;
            }

            // `Name:` under `Registrar:` is the registrar's name; `Name:`
            // anywhere else is somebody's, and is not read (see the class
            // docblock).
            if ($block === 'registrar' && $key === 'name' && !isset($found['registrar'])) {
                $found['registrar'] = mb_substr($value, 0, self::MAX_VALUE_LENGTH);
                continue;
            }

            if (in_array($key, self::NAME_SERVER_KEYS, true)) {
                self::addNameServer($nameServers, $value);
                continue;
            }

            // Below this line every key is matched against the alias
            // table, so everything inside a person's block stops here.
            if (in_array($block, self::PERSONAL_BLOCKS, true)) {
                continue;
            }

            foreach (self::ALIASES as $fact => $keys) {
                // First occurrence wins: a thin registry's own line comes
                // before the registrar block it refers to, and the two
                // rarely disagree — but when they do, the registry is the
                // authority on its own record.
                if (!isset($found[$fact]) && in_array($key, $keys, true)) {
                    $found[$fact] = mb_substr($value, 0, self::MAX_VALUE_LENGTH);
                }
            }
        }

        return [
            'registrar' => $found['registrar'] ?? null,
            'created_at' => $found['created_at'] ?? null,
            'updated_at' => $found['updated_at'] ?? null,
            'expires_at' => $found['expires_at'] ?? null,
            'status' => $found['status'] ?? null,
            'name_servers' => array_slice($nameServers, 0, self::MAX_NAME_SERVERS),
        ];
    }

    /**
     * One name server, deduplicated and lowercased.
     *
     * Some registries print the glue IP after the host on the same line;
     * the host is the part anybody reads.
     *
     * @param string[] $nameServers
     */
    private static function addNameServer(array &$nameServers, string $value): void
    {
        $host = strtolower((string) (preg_split('/\s+/', trim($value))[0] ?? ''));

        if ($host !== '' && !in_array($host, $nameServers, true)) {
            $nameServers[] = $host;
        }
    }

    /**
     * Whether a reading found anything at all.
     *
     * A response that parses to nothing but is not a « not found » is a
     * registry printing a shape this table does not know — worth keeping
     * the raw of, worth not claiming a registration from.
     *
     * @param array<string, mixed> $registration
     */
    public static function isEmpty(array $registration): bool
    {
        foreach (['registrar', 'created_at', 'updated_at', 'expires_at', 'status'] as $fact) {
            if (($registration[$fact] ?? null) !== null) {
                return false;
            }
        }

        return ($registration['name_servers'] ?? []) === [];
    }
}
