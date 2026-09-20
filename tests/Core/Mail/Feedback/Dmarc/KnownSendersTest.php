<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Feedback\Dmarc\KnownSenders;
use Core\Mail\Transport\MailProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Telling the unit's own relays from everybody else's (roadmap IT-06).
 *
 * **Every case here goes through the stored reading**, refresh then
 * `remembered()`, because that is the only path the site has: the page
 * reads what the DNS check left behind and never resolves for itself. A
 * test that built the object straight from a resolver would be exercising
 * a road nothing travels — which is what this file did before the review
 * pointed out that the render path was blocking on DNS.
 *
 * @group database
 */
class KnownSendersTest extends TestCase
{
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        $this->settings->register(
            KnownSenders::SETTING_KEY,
            '',
            'text',
            'Adresses des relais',
            '',
            null,
            null,
            null,
            false,
            57
        );
    }

    private function relay(string $name, string $host): MailProvider
    {
        return new MailProvider(
            id: 2,
            name: $name,
            host: $host,
            port: 587,
            username: 'unite',
            dailyQuota: null,
            batchSize: 50,
            batchIntervalMinutes: 10,
            secretPrefix: 'mail_provider_2'
        );
    }

    /**
     * @param array<string, list<string>> $zone   host => addresses
     * @param list<MailProvider>|null     $relays
     */
    private function senders(array $zone, ?array $relays = null): KnownSenders
    {
        KnownSenders::refresh(
            $this->settings,
            $relays ?? [$this->relay('Brevo', 'smtp-relay.brevo.com')],
            static fn(string $host): array => $zone[$host] ?? []
        );

        // Read back rather than reusing what `refresh()` returned: the
        // page never holds the object the check built, it holds whatever
        // survived the round trip through the setting.
        return KnownSenders::remembered($this->settings);
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
            [$this->relay('Brevo', 'smtp-relay.brevo.com'), $this->relay('OVH', 'mail.ovh.net')]
        );

        $this->assertSame('Brevo', $senders->nameFor('185.12.80.100'));
        $this->assertSame('OVH', $senders->nameFor('51.68.1.1'));
    }

    /** A provider with no host configured claims nothing. */
    public function testAProviderWithoutAHostClaimsNothing(): void
    {
        $senders = $this->senders(
            ['' => ['203.0.113.1']],
            [$this->relay('Brevo', '')]
        );

        $this->assertFalse($senders->isOwn('203.0.113.1'));
    }

    /**
     * **Reading places a source without asking a resolver anything.**
     *
     * This is the property the whole class was rewritten for, so it is
     * asserted rather than assumed: the resolver is handed to `refresh()`
     * and is never reachable from `remembered()`, so a page that renders
     * a hundred sources makes zero DNS calls. Before, the first
     * `nameFor()` of a render resolved every relay — two blocking lookups
     * each, on the screen somebody opens when mail is already broken.
     */
    public function testReadingTheRememberedAnswerAsksNoResolver(): void
    {
        $calls = 0;
        KnownSenders::refresh(
            $this->settings,
            [$this->relay('Brevo', 'smtp-relay.brevo.com')],
            static function (string $host) use (&$calls): array {
                $calls++;

                return ['185.12.80.100'];
            }
        );
        $this->assertSame(1, $calls, 'The explicit refresh resolves once per host.');

        $senders = KnownSenders::remembered($this->settings);
        $senders->isOwn('185.12.80.100');
        $senders->isOwn('203.0.113.1');
        $senders->nameFor('185.12.80.100');

        $this->assertSame(1, $calls, 'Rendering must never reach a resolver.');
    }

    /**
     * **Two spellings of one IPv6 address are one address.**
     *
     * A reporter writes what its own library produces; a resolver writes
     * what its own library produces; nothing makes the two agree on
     * zero-compression. Compared as text, a unit's IPv6 relay lands in
     * « autres » for ever — and « autres » is precisely the answer nobody
     * questions, so the mislabelling would never be reported.
     */
    public function testTheSameIpV6AddressIsRecognisedWhicheverWayItIsWritten(): void
    {
        $senders = $this->senders(['smtp-relay.brevo.com' => ['2a02:1788:05ff:08ac:0000:0000:0000:0001']]);

        $this->assertSame('Brevo', $senders->nameFor('2a02:1788:5ff:8ac::1'));
    }

    /** No reading taken yet places nothing, and says as much. */
    public function testWithoutAReadingNothingIsPlaced(): void
    {
        $senders = KnownSenders::remembered($this->settings);

        $this->assertTrue($senders->isEmpty());
        $this->assertNull($senders->nameFor('185.12.80.100'));
    }

    /** A stored value that cannot be read is « no reading », never a crash. */
    public function testAnUnreadableStoredValueIsTreatedAsNoReading(): void
    {
        $this->settings->setInternal(KnownSenders::SETTING_KEY, 'pas du JSON');

        $senders = KnownSenders::remembered($this->settings);

        $this->assertTrue($senders->isEmpty());
        $this->assertNull($senders->nameFor('185.12.80.100'));
    }
}
