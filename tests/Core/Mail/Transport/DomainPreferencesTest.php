<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Transport\DomainPreferences;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Which relay a recipient domain's mailings try first (roadmap IT-07,
 * D13).
 *
 * **Most of this file is about what a preference may NOT do.** It sits on
 * the path every message of every mailing takes, so the interesting
 * failures are not « the wrong relay was preferred » — they are « a
 * message did not leave » and « a login link took a different road
 * because of who was receiving it ».
 */
#[Group('database')]
class DomainPreferencesTest extends TestCase
{
    private SettingService $settings;
    private DomainPreferences $preferences;

    protected function setUp(): void
    {
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
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
    }

    private function provider(int $id, string $name): MailProvider
    {
        return new MailProvider(
            id: $id,
            name: $name,
            host: 'smtp.' . strtolower($name) . '.test',
            port: 587,
            username: 'unite',
            dailyQuota: null,
            batchSize: 50,
            batchIntervalMinutes: 10
        );
    }

    /** @return array<int, MailProvider> */
    private function chain(): array
    {
        return [$this->provider(1, 'Brevo'), $this->provider(2, 'OVH'), $this->provider(0, 'Envoi local')];
    }

    /** @param array<int, MailProvider> $candidates @return list<string> */
    private function names(array $candidates): array
    {
        return array_map(static fn(MailProvider $p): string => $p->name, $candidates);
    }

    public function testADomainWithNoPreferenceHasNone(): void
    {
        $this->assertNull($this->preferences->forDomain('gmail.com'));
    }

    public function testAPreferenceSurvivesTheRoundTripThroughTheSetting(): void
    {
        $this->assertTrue($this->preferences->prefer('gmail.com', 2));

        $this->assertSame(2, (new DomainPreferences($this->settings))->forDomain('gmail.com'));
    }

    /** Writing the same decision twice is not a change, so nothing journals it. */
    public function testWritingTheSameDecisionTwiceReportsNoChange(): void
    {
        $this->preferences->prefer('gmail.com', 2);

        $this->assertFalse($this->preferences->prefer('gmail.com', 2));
    }

    public function testForgettingADomainReportsWhetherThereWasAnything(): void
    {
        $this->assertFalse($this->preferences->forget('gmail.com'));

        $this->preferences->prefer('gmail.com', 2);

        $this->assertTrue($this->preferences->forget('gmail.com'));
        $this->assertNull($this->preferences->forDomain('gmail.com'));
    }

    /** « Gmail.COM » and « gmail.com » are one domain, on both ends. */
    public function testCaseAndSpacingAreNotTwoDomains(): void
    {
        $this->preferences->prefer('  Gmail.COM ', 2);

        $this->assertSame(2, $this->preferences->forDomain('gmail.com'));
        $this->assertSame(2, $this->preferences->forDomain('GMAIL.com'));
    }

    /**
     * **A setting somebody edited by hand is « no preference », never a
     * crash.** This value is read on every message of every mailing, and
     * an exception here would stop a whole publipostage over a routing
     * nicety nobody would have missed.
     */
    public function testAnUnreadableSettingIsNoPreference(): void
    {
        $this->settings->setInternal(DomainPreferences::SETTING_KEY, 'pas du JSON');

        $this->assertSame([], $this->preferences->all());
        $this->assertNull($this->preferences->forDomain('gmail.com'));
    }

    /** And so is one holding the right JSON with the wrong shapes in it. */
    public function testEntriesThatAreNotProviderIdsAreIgnored(): void
    {
        $this->settings->setInternal(
            DomainPreferences::SETTING_KEY,
            (string) json_encode(['gmail.com' => 'Brevo', 'orange.fr' => -1, 'laposte.net' => 3])
        );

        $this->assertSame(['laposte.net' => 3], $this->preferences->all());
    }

    /**
     * **The map has a ceiling**, because it is read on the send path: one
     * entry per recipient domain a unit has ever written to would turn a
     * cheap lookup into a payload.
     */
    public function testTheMapStopsGrowingAtItsCeiling(): void
    {
        for ($i = 0; $i < DomainPreferences::MAXIMUM + 10; $i++) {
            $this->preferences->prefer('domaine-' . $i . '.test', 2);
        }

        $this->assertCount(DomainPreferences::MAXIMUM, $this->preferences->all());
        $this->assertNull($this->preferences->forDomain('domaine-0.test'), 'the oldest made way');
        $this->assertSame(
            2,
            $this->preferences->forDomain('domaine-' . (DomainPreferences::MAXIMUM + 9) . '.test'),
            'and the newest is in.'
        );
    }

    /** A domain decided again is decided again, not dropped as the oldest. */
    public function testRedecidingADomainMovesItToTheEndOfTheQueue(): void
    {
        for ($i = 0; $i < DomainPreferences::MAXIMUM; $i++) {
            $this->preferences->prefer('domaine-' . $i . '.test', 2);
        }
        $this->preferences->prefer('domaine-0.test', 1);
        $this->preferences->prefer('un-de-plus.test', 2);

        $this->assertSame(1, $this->preferences->forDomain('domaine-0.test'));
        $this->assertNull($this->preferences->forDomain('domaine-1.test'), 'the next oldest made way instead.');
    }

    // ── Reordering ────────────────────────────────────────────────────

    public function testThePreferredRelayIsTriedFirst(): void
    {
        $this->preferences->prefer('gmail.com', 2);

        $this->assertSame(
            ['OVH', 'Brevo', 'Envoi local'],
            $this->names($this->preferences->reorder($this->chain(), MailLane::Bulk, 'famille@gmail.com'))
        );
    }

    /**
     * **The fallbacks keep their own order behind it.** A preferred relay
     * that refuses the message must fall through to exactly the chain it
     * would have had, or a preference would quietly cost a unit its
     * second and third chances.
     */
    public function testTheRestOfTheChainKeepsItsOrder(): void
    {
        $this->preferences->prefer('gmail.com', 0);

        $this->assertSame(
            ['Envoi local', 'Brevo', 'OVH'],
            $this->names($this->preferences->reorder($this->chain(), MailLane::Bulk, 'famille@gmail.com'))
        );
    }

    /**
     * **The rule of D13 that nothing may relax: only the mailing lane.**
     *
     * A magic link lives fifteen minutes. A login path that varies with
     * the recipient's provider is a login path nobody can reason about,
     * and « delivered tomorrow through the relay gmail.com prefers » is
     * indistinguishable from not delivered at all.
     */
    public function testAuthenticationAndTransactionalMailIgnoreThePreferenceEntirely(): void
    {
        $this->preferences->prefer('gmail.com', 2);

        foreach ([MailLane::Authentication, MailLane::Transactional] as $lane) {
            $this->assertSame(
                ['Brevo', 'OVH', 'Envoi local'],
                $this->names($this->preferences->reorder($this->chain(), $lane, 'famille@gmail.com')),
                $lane->value . ' must not be routed by recipient.'
            );
        }
    }

    /**
     * **A preference never conjures a relay back.** The lane already
     * dropped the ones that are disabled, out of quota or behind an open
     * breaker, and every one of those reasons outranks a preference.
     */
    public function testAPreferenceForARelayTheLaneDidNotOfferChangesNothing(): void
    {
        $this->preferences->prefer('gmail.com', 7);

        $this->assertSame(
            ['Brevo', 'OVH', 'Envoi local'],
            $this->names($this->preferences->reorder($this->chain(), MailLane::Bulk, 'famille@gmail.com'))
        );
    }

    public function testADomainWithoutAPreferenceKeepsTheLanesOwnOrder(): void
    {
        $this->preferences->prefer('gmail.com', 2);

        $this->assertSame(
            ['Brevo', 'OVH', 'Envoi local'],
            $this->names($this->preferences->reorder($this->chain(), MailLane::Bulk, 'famille@laposte.net'))
        );
    }

    /** Nothing is dropped, ever: the reordered chain is the same chain. */
    public function testReorderingNeverLosesACandidate(): void
    {
        $this->preferences->prefer('gmail.com', 2);

        $reordered = $this->preferences->reorder($this->chain(), MailLane::Bulk, 'famille@gmail.com');

        $this->assertCount(3, $reordered);
    }

    public function testAnAddressWithoutADomainMatchesNoPreference(): void
    {
        $this->preferences->prefer('gmail.com', 2);

        $this->assertSame(
            ['Brevo', 'OVH', 'Envoi local'],
            $this->names($this->preferences->reorder($this->chain(), MailLane::Bulk, 'pas-une-adresse'))
        );
    }

    public function testTheDomainIsReadFromTheLastAtSign(): void
    {
        $this->assertSame('gmail.com', DomainPreferences::domainOf('pré.nom@gmail.com'));
        $this->assertSame('gmail.com', DomainPreferences::domainOf('"a@b"@Gmail.com'));
        $this->assertSame('', DomainPreferences::domainOf('pas-une-adresse'));
    }

    /**
     * The empty domain is never written, so the two ends agree without
     * either trusting the other: a malformed address reads `''`, and
     * `''` is a key nothing can have stored.
     */
    public function testTheEmptyDomainCannotBeStored(): void
    {
        $this->assertFalse($this->preferences->prefer('   ', 2));
        $this->assertSame([], $this->preferences->all());
    }

    /**
     * **Only something that could be the right-hand side of an address.**
     *
     * The decision arrives from a form, is journalled, is printed into
     * the support archive a third party reads, and is matched against
     * every mailing's recipient. A newline or a hundred lines of text
     * reaching any of those is a defect, and the guard sits at the single
     * writer rather than at each of the four readers.
     */
    public function testSomethingThatIsNotADomainIsNotStored(): void
    {
        foreach (
            [
                'gmail',
                'deux lignes' . "\n" . 'gmail.com',
                '-gmail.com',
                'gmail-.com',
                'gmail.com/chemin',
                'famille@gmail.com',
                str_repeat('a', 64) . '.com',
                str_repeat('a.', 200) . 'com',
            ] as $notADomain
        ) {
            $this->assertFalse(
                $this->preferences->prefer($notADomain, 2),
                var_export($notADomain, true) . ' is not a domain.'
            );
        }

        $this->assertSame([], $this->preferences->all());
    }

    /** And the ordinary ones are, including the awkward-looking ones. */
    public function testRealDomainsAreStored(): void
    {
        foreach (['gmail.com', 'sous.domaine.co.uk', 'xn--dmain-0sa.be', 'a-b.fr'] as $domain) {
            $this->assertTrue($this->preferences->prefer($domain, 2), $domain);
        }

        $this->assertCount(4, $this->preferences->all());
    }
}
