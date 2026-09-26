<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Seed;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Feedback\Seed\DomainRouting;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Transport\DomainPreferences;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Routing by recipient domain is a RECOMMENDATION, never an automatism
 * (D13, roadmap IT-07).
 *
 * **The tests that matter are the refusals to conclude.** With three to
 * five seed boxes and a few mailings a year, the expensive mistake is not
 * missing a problem — it is moving a provider's whole traffic on the
 * strength of one unlucky campaign.
 *
 * @group database
 */
#[Group('database')]
class DomainRoutingTest extends TestCase
{
    private \PDO $pdo;
    private SeedCopyRepository $copies;
    private SettingService $settings;
    private DomainPreferences $preferences;
    private LaneChainRepository $chains;
    private DomainRouting $routing;

    protected function setUp(): void
    {
        $pdo = $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->copies = new SeedCopyRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->settings = new SettingService(new SettingRepository($pdo));
        $this->settings->register(
            DomainRouting::SETTING_AUTOMATIC,
            '0',
            'boolean',
            'Routage automatique',
            '',
            null,
            null,
            null,
            false,
            59
        );
        $this->settings->register(
            DomainPreferences::SETTING_KEY,
            '',
            'text',
            'Routage par domaine',
            '',
            null,
            null,
            null,
            false,
            60
        );
        $this->preferences = new DomainPreferences($this->settings);
        $this->chains = new LaneChainRepository($pdo);
        $this->routing = $this->routingWithChain();
    }

    /**
     * A routing that can decide, not only read.
     *
     * Rebuilt rather than mutated because `DomainRouting` memoises the
     * mailing chain: a test that adds a relay and then asks the same
     * instance would be asking about the chain as it was.
     */
    private function routingWithChain(): DomainRouting
    {
        return new DomainRouting(
            $this->copies,
            $this->settings,
            $this->preferences,
            $this->chains,
            new MailProviderDirectory(
                new MailProviderRepository($this->pdo),
                new ProviderConnections([]),
                $this->settings
            )
        );
    }

    /**
     * The local send, on the mailing lane, enabled — **as every real
     * installation has it.**
     *
     * `TransportSeeder::layDownChains()` appends `MailProvider::LOCAL_ID`
     * to every lane, enabled, the mailing lane included. A fixture
     * without it is not a smaller installation, it is one that does not
     * exist — and the omission is what let « a unit with one relay has no
     * alternative » pass while production offered the server's own
     * `mail()` as the alternative.
     */
    private function localSend(int $position = 99): void
    {
        $entry = $this->pdo->prepare(
            'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, ?, 1)'
        );
        $entry->execute([MailLane::Bulk->value, MailProvider::LOCAL_ID, $position]);
    }

    /** One relay on the mailing lane, at the given position. */
    private function relay(string $name, int $position): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_providers (name, secret_prefix, batch_size, batch_interval_minutes)
             VALUES (?, ?, 50, 10)'
        );
        $statement->execute([$name, 'mail_provider_' . $name]);
        $id = (int) $this->pdo->lastInsertId();

        $entry = $this->pdo->prepare(
            'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, ?, 1)'
        );
        $entry->execute([MailLane::Bulk->value, $id, $position]);

        return $id;
    }

    private function record(string $address, string $folder, int $times, int $offsetDays = 0): void
    {
        for ($i = 0; $i < $times; $i++) {
            $sent = new \DateTimeImmutable('-' . ($offsetDays + 1) . ' days');
            $run = 'envoi-' . $address . '-' . $folder . '-' . $i . '-' . $offsetDays;
            $this->copies->claim($run, $address, $sent);
            $this->copies->recordLanding($run, $address, $folder, $sent);
        }
    }

    private function readingFor(string $provider): array
    {
        foreach ($this->routing->readings(new \DateTimeImmutable('-30 days')) as $reading) {
            if ($reading['provider'] === $provider) {
                return $reading;
            }
        }

        $this->fail('No reading for ' . $provider);
    }

    /**
     * **The refusal that D13 exists for.** Two mailings filed as spam is
     * two observations, and a recommendation built on two observations is
     * a recommendation built on noise.
     */
    public function testATinySampleConcludesNothingHoweverBadItLooks(): void
    {
        $this->record('t@orange.fr', 'Junk', 2);

        $reading = $this->readingFor('orange.fr');

        $this->assertFalse($reading['enough']);
        $this->assertFalse($reading['troubled'], 'Two observations must move nothing.');
        $this->assertStringContainsString('Pas assez', $reading['verdict']);
        $this->assertFalse($this->routing->hasRecommendation(new \DateTimeImmutable('-30 days')));
    }

    /** With enough evidence, the same pattern is finally worth saying. */
    public function testAProviderFilingMostMailingsAsSpamIsFlaggedOnceTheSampleIsBigEnough(): void
    {
        $this->record('t@orange.fr', 'Junk', DomainRouting::MINIMUM_RUNS);

        $reading = $this->readingFor('orange.fr');

        $this->assertTrue($reading['enough']);
        $this->assertTrue($reading['troubled']);
        $this->assertTrue($this->routing->hasRecommendation(new \DateTimeImmutable('-30 days')));
    }

    /**
     * **A provider that is fine is still listed.** A screen showing only
     * problems leaves a reader unable to tell « rien d'anormal » from
     * « rien de mesuré », and the second is the one that needs acting on.
     */
    public function testAHealthyProviderIsReportedRatherThanOmitted(): void
    {
        $this->record('t@gmail.com', 'INBOX', DomainRouting::MINIMUM_RUNS);

        $reading = $this->readingFor('gmail.com');

        $this->assertTrue($reading['enough']);
        $this->assertFalse($reading['troubled']);
        $this->assertSame('Rien à signaler', $reading['verdict']);
    }

    /** The occasional miss is ordinary and must not raise anything. */
    public function testAnOccasionalSpamFilingDoesNotRaiseARecommendation(): void
    {
        $this->record('t@gmail.com', 'INBOX', 9);
        $this->record('t@gmail.com', 'Junk', 1);

        $this->assertFalse($this->readingFor('gmail.com')['troubled']);
    }

    /**
     * **A copy nobody has answered yet is not evidence.** Counting it
     * would make the ratio move as the sweep runs rather than as delivery
     * changes — a figure that shifts for reasons nobody can see.
     */
    public function testCopiesStillWaitingAreNotCountedEitherWay(): void
    {
        $this->record('t@gmail.com', 'INBOX', DomainRouting::MINIMUM_RUNS);
        // Sent, never answered.
        $this->copies->claim('en-cours', 't@gmail.com', new \DateTimeImmutable('-1 hour'));

        $reading = $this->readingFor('gmail.com');

        $this->assertSame(DomainRouting::MINIMUM_RUNS, $reading['runs']);
        $this->assertFalse($reading['troubled']);
    }

    /**
     * **And the automatism is off until somebody turns it on** — the
     * second lock D13 asks for, beside the minimum sample.
     */
    public function testTheAutomatismIsOffByDefault(): void
    {
        $this->assertFalse($this->routing->isAutomatic());
    }

    public function testTheAutomatismReadsTheSwitch(): void
    {
        $this->settings->setInternal(DomainRouting::SETTING_AUTOMATIC, '1');

        $this->assertTrue($this->routing->isAutomatic());
    }

    /**
     * The sample is counted in MAILINGS, not in copies: five copies of one
     * mailing to five boxes say one thing five times, and counting them as
     * five observations would be the noise the minimum exists to refuse.
     */
    public function testOneMailingToManyBoxesIsNotManyObservations(): void
    {
        $sent = new \DateTimeImmutable('-1 day');
        foreach (['a@orange.fr', 'b@orange.fr', 'c@orange.fr', 'd@orange.fr', 'e@orange.fr'] as $box) {
            $this->copies->claim('un-seul-envoi', $box, $sent);
            $this->copies->recordLanding('un-seul-envoi', $box, 'Junk', $sent);
        }

        // Five copies, but of ONE mailing.
        $this->assertFalse(
            $this->routing->hasRecommendation(new \DateTimeImmutable('-30 days')),
            'Five boxes on one mailing is one observation, not five.'
        );
    }

    // ── Applying, which is a button and not a rule ────────────────────

    /**
     * **A unit with one relay has nowhere to route to**, and that is most
     * units. The screen has to say so rather than offer a button that
     * would explain nothing when it did nothing: the answer to a provider
     * filtering your mail, with one relay, is to change what you send.
     */
    public function testWithASingleRelayThereIsNoAlternativeAndApplyingDoesNothing(): void
    {
        $this->relay('Brevo', 1);
        // The installation as it really is: the local send sits on the
        // mailing lane too, seeded enabled.
        $this->localSend();
        $routing = $this->routingWithChain();

        $this->assertNull($routing->alternativeFor('gmail.com'));
        $this->assertNull($routing->apply('gmail.com'));
        $this->assertNull($this->preferences->forDomain('gmail.com'));
    }

    /** With two, the alternative is the next entry of the operator's own order. */
    public function testTheAlternativeIsTheNextRelayOfTheMailingChain(): void
    {
        $this->relay('Brevo', 1);
        $this->relay('OVH', 2);
        $routing = $this->routingWithChain();

        $this->assertSame('OVH', $routing->alternativeFor('gmail.com')?->name);
    }

    public function testApplyingWritesTheDecisionWhereTheTransportReadsIt(): void
    {
        $this->relay('Brevo', 1);
        $ovh = $this->relay('OVH', 2);
        $routing = $this->routingWithChain();

        $this->assertSame('OVH', $routing->apply('gmail.com')?->name);
        $this->assertSame($ovh, $this->preferences->forDomain('gmail.com'));
    }

    /**
     * **Applying twice comes back rather than walking off the end.** A
     * two-relay unit that applied once must be able to undo it from the
     * same button; a chain that stopped at its last entry would make the
     * button a one-way door.
     */
    public function testApplyingAgainWrapsBackRoundTheChain(): void
    {
        $brevo = $this->relay('Brevo', 1);
        $this->relay('OVH', 2);

        $this->routingWithChain()->apply('gmail.com');

        $this->assertSame('Brevo', $this->routingWithChain()->apply('gmail.com')?->name);
        $this->assertSame($brevo, $this->preferences->forDomain('gmail.com'));
    }

    /** And undoing puts the domain back under the lane's own order. */
    public function testClearingRemovesTheDecisionAltogether(): void
    {
        $this->relay('Brevo', 1);
        $this->relay('OVH', 2);
        $this->routingWithChain()->apply('gmail.com');

        $this->assertTrue($this->routingWithChain()->clear('gmail.com'));
        $this->assertNull($this->preferences->forDomain('gmail.com'));
    }

    /** A disabled entry is not somewhere to route to. */
    public function testADisabledRelayIsNotAnAlternative(): void
    {
        $this->relay('Brevo', 1);
        $ovh = $this->relay('OVH', 2);
        $this->chains->setEnabled(MailLane::Bulk, $ovh, false);

        $this->assertNull($this->routingWithChain()->alternativeFor('gmail.com'));
    }

    /**
     * **The mailing lane, and only it.** A relay configured on the
     * authentication lane alone is not an alternative for a publipostage:
     * D13 routes one lane, and reading another one here would be the
     * quiet way to route all three.
     */
    public function testARelayOnAnotherLaneIsNotAnAlternative(): void
    {
        $this->relay('Brevo', 1);
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_providers (name, secret_prefix, batch_size, batch_interval_minutes)
             VALUES (?, ?, 50, 10)'
        );
        $statement->execute(['OVH', 'mail_provider_ovh']);
        $entry = $this->pdo->prepare(
            'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, 1, 1)'
        );
        $entry->execute([MailLane::Authentication->value, (int) $this->pdo->lastInsertId()]);

        $this->assertNull($this->routingWithChain()->alternativeFor('gmail.com'));
    }

    /** The reading carries both halves, so the screen never computes one. */
    public function testAReadingSaysWhereTheDomainGoesAndWhereApplyingWouldSendIt(): void
    {
        $this->relay('Brevo', 1);
        $this->relay('OVH', 2);
        $this->record('t@gmail.com', 'Junk', DomainRouting::MINIMUM_RUNS);

        $reading = $this->readingFor('gmail.com');
        $this->assertNull($reading['routed_to']);
        $this->assertSame('OVH', $reading['alternative']);

        $this->routingWithChain()->apply('gmail.com');
        $this->routing = $this->routingWithChain();

        $this->assertSame('OVH', $this->readingFor('gmail.com')['routed_to']);
    }

    /**
     * **A decision naming a deleted relay stays readable.** « relais nº 7
     * (supprimé) » is what tells somebody to clear it; a blank cell is
     * what makes them believe there is nothing to clear.
     */
    public function testAPreferenceForADeletedRelayIsShownRatherThanHidden(): void
    {
        $this->relay('Brevo', 1);
        $this->preferences->prefer('gmail.com', 404);
        $this->record('t@gmail.com', 'INBOX', 1);

        $this->assertStringContainsString('404', (string) $this->readingFor('gmail.com')['routed_to']);
    }

    /** Without anywhere to write a decision, the reading is still a reading. */
    public function testAReadingWithoutATransportStillReads(): void
    {
        $this->record('t@gmail.com', 'Junk', DomainRouting::MINIMUM_RUNS);
        $display = new DomainRouting($this->copies, $this->settings);

        $reading = null;
        foreach ($display->readings(new \DateTimeImmutable('-30 days')) as $row) {
            if ($row['provider'] === 'gmail.com') {
                $reading = $row;
            }
        }

        $this->assertNotNull($reading);
        $this->assertTrue($reading['troubled']);
        $this->assertNull($reading['routed_to']);
        $this->assertNull($reading['alternative']);
        $this->assertNull($display->apply('gmail.com'));
    }

    /**
     * **The local send is never an alternative**, and this is the case
     * the first version got wrong in the direction that matters.
     *
     * `TransportSeeder` puts `Envoi local` on every lane, enabled, so the
     * commonest installation — one relay — had a mailing chain of two.
     * The « only one relay » guard never fired, and what a struggling
     * provider was offered was the server's own unauthenticated `mail()`:
     * no relay reputation, no warmed-up sending domain, the transport
     * most likely to be filtered of all. Routing a domain somewhere worse
     * is not routing.
     *
     * With the automatic switch on, that move happened unattended.
     */
    public function testTheLocalSendIsNeverOfferedAsAnAlternative(): void
    {
        $this->relay('Brevo', 1);
        $this->localSend();

        $routing = $this->routingWithChain();

        $this->assertSame([], array_map(
            static fn(MailProvider $provider): int => $provider->id,
            array_filter(
                $routing->bulkChain(),
                static fn(MailProvider $provider): bool => $provider->id === MailProvider::LOCAL_ID
            )
        ), 'the local send is not somewhere to route a struggling provider to.');

        $this->assertNull($routing->alternativeFor('gmail.com'));
        $this->assertNull($routing->apply('gmail.com'));
        $this->assertSame([], $this->preferences->all());
    }

    /** And with two real relays beside it, the alternative is the real one. */
    public function testWithTwoRealRelaysTheLocalSendIsStillSkipped(): void
    {
        $this->relay('Brevo', 1);
        $this->localSend(2);
        $this->relay('OVH', 3);

        $this->assertSame('OVH', $this->routingWithChain()->alternativeFor('gmail.com')?->name);
    }

    /**
     * A decision that names the local send — written before it was
     * excluded — reads as what it is rather than as a deleted relay.
     */
    public function testADecisionNamingTheLocalSendIsStillReadable(): void
    {
        $this->relay('Brevo', 1);
        $this->localSend();
        $this->preferences->prefer('gmail.com', MailProvider::LOCAL_ID);
        $this->record('t@gmail.com', 'INBOX', 1);

        $this->assertSame(MailProvider::LOCAL_NAME, $this->readingFor('gmail.com')['routed_to']);
    }

    /**
     * The column a box's domain is shown in, and how many domains the MX
     * records moved — a count and never the list (issue #422).
     */
    public function testAPersonalDomainIsShownUnderItsMxProvider(): void
    {
        $cache = new \Core\Mail\Transport\MailboxProviderRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $now = new \DateTimeImmutable();
        $known = ['famille.be' => 'gmail.com', 'gmail.com' => 'gmail.com', 'ecole.be' => null];
        foreach ($known as $domain => $provider) {
            $cache->note($domain, $now);
            $cache->recordResolved($domain, $provider, $now);
        }
        $routing = new DomainRouting($this->copies, $this->settings, mailboxProviders: $cache);

        $this->assertSame('gmail.com', $routing->providerOf('Famille.be'));
        $this->assertSame('ecole.be', $routing->providerOf('ecole.be'));
        $this->assertSame('inconnu.be', $routing->providerOf('inconnu.be'));
        $this->assertSame(1, $routing->attributedDomains());
    }

    /** Without the cache the screen behaves exactly as it did before. */
    public function testWithoutTheCacheADomainIsItsOwnProvider(): void
    {
        $this->assertSame('famille.be', $this->routing->providerOf('famille.be'));
        $this->assertSame(0, $this->routing->attributedDomains());
    }
}
