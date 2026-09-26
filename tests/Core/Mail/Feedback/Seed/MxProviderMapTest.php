<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Mail\Feedback\Seed\MxProviderMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which provider runs a set of MX hosts (issue #422). The keys must be
 * the ones the seed results were already read by, or a domain Google
 * hosts would open a column of its own instead of joining gmail.com.
 */
class MxProviderMapTest extends TestCase
{
    /** @return array<string, array{list<string>, ?string}> */
    public static function hosts(): array
    {
        return [
            'Google Workspace' => [['aspmx.l.google.com', 'alt1.aspmx.l.google.com'], 'gmail.com'],
            'Google, the single new-style MX' => [['smtp.google.com'], 'gmail.com'],
            'gmail.com itself' => [['gmail-smtp-in.l.google.com'], 'gmail.com'],
            'Google, the legacy backup names' => [['aspmx2.googlemail.com'], 'gmail.com'],
            'Microsoft 365' => [['famille-be.mail.protection.outlook.com'], 'outlook.com'],
            'hotmail.com' => [['hotmail-com.olc.protection.outlook.com'], 'outlook.com'],
            'Yahoo' => [['mta5.am0.yahoodns.net'], 'yahoo.com'],
            'iCloud' => [['mx01.mail.icloud.com'], 'icloud.com'],
            'Proton' => [['mail.protonmail.ch'], 'proton.me'],
            'a trailing dot and capitals' => [['ASPMX.L.GOOGLE.COM.'], 'gmail.com'],
            'somebody nobody knows' => [['mx.skynet.be'], null],
            'a lookalike is not Google' => [['mx.evilgoogle.com'], null],
            'no MX at all' => [[], null],
        ];
    }

    /** @param list<string> $hosts */
    #[DataProvider('hosts')]
    public function testTheMostPreferredHostNamesTheProvider(array $hosts, ?string $expected): void
    {
        $this->assertSame($expected, MxProviderMap::providerFor($hosts));
    }

    /**
     * **A filtering gateway in front of Google is not Google.** The mail
     * goes where the first MX says; a backup at a big provider is a
     * fallback nobody's inbox lives behind.
     */
    public function testOnlyTheMostPreferredHostDecides(): void
    {
        $this->assertNull(MxProviderMap::providerFor(['gateway.filtre.example', 'aspmx.l.google.com']));
    }
}
