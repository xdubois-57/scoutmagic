<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

/**
 * Which mailbox provider runs a set of MX hosts (roadmap IT-07, #422).
 *
 * **The key is a domain the seed results already use**, never a brand
 * name: the results table has always been read by « gmail.com » and
 * « outlook.com » (`SeedCopy::providerOf()`), the routing decisions of D13
 * are stored under those same keys, and a family on « famille.be » served
 * by Google has to land in exactly that column and under exactly that
 * decision. A new vocabulary would have split every existing result and
 * every existing decision in two.
 *
 * **A short list, and a miss is an honest answer.** A domain whose MX
 * names nobody below — a Belgian ISP, a university, a filtering gateway
 * placed in front of Google — keeps its own name as its column, which is
 * what the site did for every domain before this existed. A guess that
 * put it under the wrong provider would be worse than no attribution: it
 * would move that domain's mailings on somebody else's evidence.
 */
final class MxProviderMap
{
    /**
     * Host suffix => provider key. Matched on a label boundary, so
     * « evilgoogle.com » is not Google.
     *
     * @var array<string, string>
     */
    public const SUFFIXES = [
        // aspmx.l.google.com and its alternates, smtp.google.com (the
        // single MX Google now hands new Workspace domains), and
        // gmail-smtp-in.l.google.com for gmail.com itself.
        'google.com' => 'gmail.com',
        'googlemail.com' => 'gmail.com',
        // <tenant>.mail.protection.outlook.com for Microsoft 365, and
        // <domain>.olc.protection.outlook.com for hotmail/live/outlook.
        'outlook.com' => 'outlook.com',
        'yahoodns.net' => 'yahoo.com',
        'mail.icloud.com' => 'icloud.com',
        'protonmail.ch' => 'proton.me',
    ];

    /**
     * The provider behind these hosts, or null when none is known.
     *
     * **The most preferred host decides**, not a vote: it is where the
     * mail actually goes, and a backup MX at another provider is a
     * fallback nobody's inbox lives behind.
     *
     * @param list<string> $hosts most preferred first, as `MxLookup` gives them
     */
    public static function providerFor(array $hosts): ?string
    {
        $first = $hosts[0] ?? null;
        if ($first === null) {
            return null;
        }

        $host = rtrim(strtolower(trim($first)), '.');
        foreach (self::SUFFIXES as $suffix => $provider) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return $provider;
            }
        }

        return null;
    }
}
