<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Mail\Feedback\Dmarc\KnownSenders;
use Core\Mail\Transport\ProviderConnections;
use PHPUnit\Framework\TestCase;

/**
 * Telling the unit's own relays from everybody else's (roadmap IT-06).
 */
class KnownSendersTest extends TestCase
{
    /**
     * @param array<string, list<string>> $zone host => addresses
     * @param array<string, string>       $names prefix => display name
     */
    private function senders(array $zone, array $names = ['smtp' => 'Brevo']): KnownSenders
    {
        return new KnownSenders(
            new ProviderConnections(['smtp_host' => 'smtp-relay.brevo.com', 'mail_provider_2_host' => 'mail.ovh.net']),
            $names,
            static fn(string $host): array => $zone[$host] ?? []
        );
    }

    public function testAnAddressOfTheUnitsOwnRelayIsRecognised(): void
    {
        $senders = $this->senders(['smtp-relay.brevo.com' => ['185.12.80.100', '185.12.80.101']]);

        $this->assertTrue($senders->isOwn('185.12.80.100'));
        $this->assertSame('Brevo', $senders->nameFor('185.12.80.101'));
    }

    public function testSomebodyElsesAddressIsNot(): void
    {
        $senders = $this->senders(['smtp-relay.brevo.com' => ['185.12.80.100']]);

        $this->assertFalse($senders->isOwn('203.0.113.77'));
        $this->assertNull($senders->nameFor('203.0.113.77'));
    }

    /**
     * **A resolution that fails is « autres », and that is the safe side.**
     *
     * Wrong this way, somebody investigates their own relay and finds
     * nothing — ten minutes lost. Wrong the other way, an unknown sender
     * is labelled « votre relais » and nobody ever looks at it again,
     * which is the entire point of the screen.
     */
    public function testARelayThatCannotBeResolvedLeavesItsSourcesUnclaimed(): void
    {
        $senders = $this->senders([]);

        $this->assertFalse($senders->isOwn('185.12.80.100'));
    }

    /** IPv6 is not a second class of address. */
    public function testAnIpV6RelayIsRecognised(): void
    {
        $senders = $this->senders(['smtp-relay.brevo.com' => ['2a02:1788:5ff:8ac::1']]);

        $this->assertTrue($senders->isOwn('2a02:1788:5ff:8ac::1'));
    }

    /** Several providers, each recognised under its own name. */
    public function testEachProviderIsNamedForItsOwnAddresses(): void
    {
        $senders = $this->senders(
            [
                'smtp-relay.brevo.com' => ['185.12.80.100'],
                'mail.ovh.net' => ['51.68.1.1'],
            ],
            ['smtp' => 'Brevo', 'mail_provider_2' => 'OVH']
        );

        $this->assertSame('Brevo', $senders->nameFor('185.12.80.100'));
        $this->assertSame('OVH', $senders->nameFor('51.68.1.1'));
    }

    /** A provider with no host configured claims nothing. */
    public function testAProviderWithoutAHostClaimsNothing(): void
    {
        $senders = new KnownSenders(
            new ProviderConnections([]),
            ['smtp' => 'Brevo'],
            static fn(string $host): array => ['203.0.113.1']
        );

        $this->assertFalse($senders->isOwn('203.0.113.1'));
    }

    /**
     * The resolver is asked once per host however many sources are
     * matched: a report screen checks dozens of addresses against the
     * same handful of relays.
     */
    public function testTheResolverIsAskedOncePerHostNotOncePerSource(): void
    {
        $calls = 0;
        $senders = new KnownSenders(
            new ProviderConnections(['smtp_host' => 'smtp-relay.brevo.com']),
            ['smtp' => 'Brevo'],
            static function (string $host) use (&$calls): array {
                $calls++;

                return ['185.12.80.100'];
            }
        );

        $senders->isOwn('185.12.80.100');
        $senders->isOwn('203.0.113.1');
        $senders->nameFor('185.12.80.100');

        $this->assertSame(1, $calls);
    }
}
