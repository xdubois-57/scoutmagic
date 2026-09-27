<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Dmarc;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Feedback\Dmarc\SpfCoverage;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Naming a DMARC source from the unit's own SPF chain (roadmap IT-06,
 * issue #421).
 *
 * **Every case goes through the stored reading**, refresh then
 * `remembered()`, because that is the only path the page has — it renders
 * what the « Vérifier les enregistrements » action left behind and never
 * resolves for itself. `KnownSendersTest` says the same thing for the same
 * reason, having learnt it from a review.
 *
 * **The zone is a closure and the lookups are counted.** The count is not
 * decoration: it is the only way the skip-what-we-already-read rule and the
 * RFC 7208 lookup ceiling are observable at all, and without it a mutation
 * removing either passes.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SpfCoverageTest extends TestCase
{
    private SettingService $settings;

    /** @var list<string> */
    private array $lookups = [];

    protected function setUp(): void
    {
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        $this->settings->register(
            SpfCoverage::SETTING_KEY,
            '',
            'text',
            'Plages autorisées par le SPF',
            '',
            null,
            null,
            null,
            false,
            58
        );
        $this->lookups = [];
    }

    /**
     * @param array<string, string> $zone host => its TXT record
     */
    private function coverage(array $zone, string $domain = 'exemple.be', ?\DateTimeImmutable $at = null): SpfCoverage
    {
        SpfCoverage::refresh(
            $this->settings,
            $domain,
            function (string $host) use ($zone): array {
                $this->lookups[] = $host;

                return isset($zone[$host]) ? [$zone[$host]] : [];
            },
            $at
        );

        // Read back rather than reusing what `refresh()` returned: the page
        // holds whatever survived the round trip through the setting, never
        // the object the action built.
        return SpfCoverage::remembered($this->settings);
    }

    public function testASourceInARangePublishedByAnIncludeIsNamedByThatInclude(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:_spf.google.com -all',
            '_spf.google.com' => 'v=spf1 ip4:209.85.128.0/17 -all',
        ]);

        $this->assertSame('_spf.google.com', $coverage->viaFor('209.85.200.7'));
        $this->assertFalse($coverage->isOwnDomain('_spf.google.com'));
    }

    /**
     * The address is three records deep, where the operator cannot see it.
     * Naming `_netblocks3.google.com` would send them looking for a string
     * their own zone does not contain.
     */
    public function testARangeFoundDeeperIsStillAttributedToTheTopLevelInclude(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:_spf.google.com -all',
            '_spf.google.com' => 'v=spf1 include:_netblocks.google.com -all',
            '_netblocks.google.com' => 'v=spf1 include:_netblocks3.google.com -all',
            '_netblocks3.google.com' => 'v=spf1 ip4:172.217.0.0/19 -all',
        ]);

        $this->assertSame('_spf.google.com', $coverage->viaFor('172.217.10.1'));
    }

    public function testARangeTheRecordStatesItselfIsAttributedToTheUnitsDomain(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.100.0/24 -all']);

        $via = $coverage->viaFor('198.51.100.7');

        $this->assertSame('exemple.be', $via);
        $this->assertNotNull($via);
        $this->assertTrue($coverage->isOwnDomain($via));
    }

    public function testAnAddressTheRecordDoesNotCoverIsNotNamed(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.100.0/24 -all']);

        $this->assertNull($coverage->viaFor('203.0.113.77'));
    }

    /**
     * The prefix on a boundary that is not a whole byte — the one shape a
     * comparison of the first N bytes gets wrong while passing every /8,
     * /16 and /24 test written next to it.
     */
    public function testAPrefixThatIsNotAWholeNumberOfBytesIsHonoured(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.96.0/20 -all']);

        // 198.51.96.0/20 spans 198.51.96.0 through 198.51.111.255.
        $this->assertSame('exemple.be', $coverage->viaFor('198.51.96.1'));
        $this->assertSame('exemple.be', $coverage->viaFor('198.51.111.254'));
        $this->assertNull($coverage->viaFor('198.51.95.255'));
        $this->assertNull($coverage->viaFor('198.51.112.0'));
    }

    public function testTheBoundariesOfAByteAlignedRange(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.100.0/24 -all']);

        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.0'));
        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.255'));
        $this->assertNull($coverage->viaFor('198.51.99.255'));
        $this->assertNull($coverage->viaFor('198.51.101.0'));
    }

    /**
     * A reporter has no reason to spell an address the way the record does.
     */
    public function testAnIpv6SourceIsPlacedWhateverSpellingItArrivesIn(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:spf.brevo.com -all',
            'spf.brevo.com' => 'v=spf1 ip6:2001:db8:abcd::/48 -all',
        ]);

        $this->assertSame('spf.brevo.com', $coverage->viaFor('2001:0db8:abcd:0000:0000:0000:0000:0001'));
        $this->assertSame('spf.brevo.com', $coverage->viaFor('2001:db8:abcd::1'));
        $this->assertNull($coverage->viaFor('2001:db8:abce::1'));
    }

    /**
     * Both records below authorise everything IN THEIR OWN FAMILY, so
     * anything that still comes back null crossed a family boundary and
     * nothing else.
     */
    public function testAnIpv4RangeDoesNotContainAnIpv6SourceOrTheReverse(): void
    {
        $v4Only = $this->coverage(['exemple.be' => 'v=spf1 ip4:0.0.0.0/0 -all']);

        $this->assertSame('exemple.be', $v4Only->viaFor('203.0.113.77'));
        $this->assertNull($v4Only->viaFor('2001:db8::1'));

        $v6Only = $this->coverage(['exemple.be' => 'v=spf1 ip6:::/0 -all']);

        $this->assertSame('exemple.be', $v6Only->viaFor('2001:db8::1'));
        $this->assertNull($v6Only->viaFor('203.0.113.77'));
    }

    /**
     * `inet_pton()` does not care which mechanism asked, so a record that
     * declares an IPv4 block under `ip6:` would be honoured as IPv4 — and
     * no receiver honours it at all.
     */
    public function testARangeWhoseFamilyContradictsItsMechanismIsDropped(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 ip6:198.51.100.0/24 ip4:2001:db8::/32 -all',
        ]);

        $this->assertSame(0, $coverage->rangeCount());
        $this->assertNull($coverage->viaFor('198.51.100.7'));
        $this->assertNull($coverage->viaFor('2001:db8::1'));
    }

    /**
     * `-ip4:` is a range the operator wrote down in order to REFUSE it.
     */
    public function testARangeCarryingARefusingQualifierIsNotReported(): void
    {
        foreach (['-', '~', '?'] as $qualifier) {
            $coverage = $this->coverage([
                'exemple.be' => 'v=spf1 ' . $qualifier . 'ip4:198.51.100.0/24 -all',
            ]);

            $this->assertNull(
                $coverage->viaFor('198.51.100.7'),
                'A "' . $qualifier . '" qualifier vouches for nothing.'
            );
        }
    }

    public function testAnExplicitPlusQualifierIsReported(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 +ip4:198.51.100.0/24 -all']);

        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.7'));
    }

    public function testAnIncludeBehindARefusingQualifierIsNotFollowed(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 -include:spf.ancien.example -all',
            'spf.ancien.example' => 'v=spf1 ip4:198.51.100.0/24 -all',
        ]);

        $this->assertNull($coverage->viaFor('198.51.100.7'));
        $this->assertNotContains('spf.ancien.example', $this->lookups);
    }

    /** SPF names are case-insensitive; a reporter's zone need not shout. */
    public function testMechanismNamesAreReadWhateverTheirCase(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 INCLUDE:Spf.Exemple.Net -all',
            'spf.exemple.net' => 'v=spf1 IP4:198.51.100.0/24 -all',
        ]);

        $this->assertSame('Spf.Exemple.Net', $coverage->viaFor('198.51.100.7'));
    }

    /**
     * `redirect=` names another record just as `include:` does, and an
     * operator reads it in their own zone the same way.
     */
    public function testARedirectModifierIsFollowedAndNamed(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 redirect=_spf.hebergeur.example',
            '_spf.hebergeur.example' => 'v=spf1 ip4:198.51.100.0/24 -all',
        ]);

        $this->assertSame('_spf.hebergeur.example', $coverage->viaFor('198.51.100.7'));
    }

    /**
     * **`all` makes `redirect=` dead text** — RFC 7208 §6.1 says the modifier
     * MUST be ignored when the record carries an `all` mechanism, whatever
     * the order of the terms. Following it anyway named a target that
     * supplied no part of the record's verdict (found in review on #571).
     */
    public function testARedirectIsIgnoredWhenTheRecordCarriesAnAll(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 ip4:192.0.2.0/24 -all redirect=_spf.hebergeur.example',
            '_spf.hebergeur.example' => 'v=spf1 ip4:198.51.100.0/24 -all',
        ]);

        // The record's own range still counts; the redirect's does not.
        $this->assertSame('exemple.be', $coverage->viaFor('192.0.2.7'));
        $this->assertNull($coverage->viaFor('198.51.100.7'));
        $this->assertNotContains('_spf.hebergeur.example', $this->lookups);
    }

    /** And an `include:` is unaffected: `all` only ends the list after it. */
    public function testAnIncludeIsStillFollowedWhenTheRecordCarriesAnAll(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:_spf.exemple.net -all',
            '_spf.exemple.net' => 'v=spf1 ip4:198.51.100.0/24 -all',
        ]);

        $this->assertSame('_spf.exemple.net', $coverage->viaFor('198.51.100.7'));
    }

    /**
     * **« Nothing published » and « nobody answered » are opposite readings.**
     * A resolver that fails midway used to leave the chain short while the
     * stored relevé looked complete: a source that IS covered then reads as
     * « Autre » with nothing on screen saying the reading was partial. Found
     * in review on #571, and it is the failure `DnsRecordReader` names a
     * skipped type to avoid.
     */
    public function testAResolverThatCouldNotBeAskedMakesTheReadingPartial(): void
    {
        SpfCoverage::refresh(
            $this->settings,
            'exemple.be',
            function (string $host): ?array {
                $this->lookups[] = $host;

                // The unit's own record reads; the include's host is the one
                // nobody could answer for — null, not an empty list.
                return $host === 'exemple.be' ? ['v=spf1 include:_spf.injoignable.test -all'] : null;
            }
        );

        $coverage = SpfCoverage::remembered($this->settings);

        $this->assertSame(SpfCoverage::PARTIAL_UNREADABLE, $coverage->partial);
        $this->assertSame(0, $coverage->rangeCount());
    }

    /** A host that answers « I publish nothing » is not a failure. */
    public function testAHostThatPublishesNothingLeavesTheReadingComplete(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:_spf.vide.example ip4:192.0.2.0/24 -all',
        ]);

        $this->assertNull($coverage->partial);
        $this->assertSame('exemple.be', $coverage->viaFor('192.0.2.7'));
    }

    public function testMechanismsThisClassDoesNotResolveNameNothing(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 a mx a:relais.exemple.be mx:mx.exemple.be exists:%{i}.exemple.be ptr -all',
        ]);

        $this->assertSame(0, $coverage->rangeCount());
        $this->assertNull($coverage->viaFor('198.51.100.7'));
        // The record was read; nothing in it led anywhere else.
        $this->assertSame(['exemple.be'], $this->lookups);
    }

    /** The one input in this file that is not a cache: the record itself. */
    public function testTheWholeInternetIsReportedWhenTheRecordSaysSo(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:0.0.0.0/0 -all']);

        $this->assertSame('exemple.be', $coverage->viaFor('203.0.113.77'));
        $this->assertSame('exemple.be', $coverage->viaFor('8.8.8.8'));
    }

    public function testAHostReadOnceIsNotReadAgain(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:a.example include:b.example -all',
            'a.example' => 'v=spf1 include:partage.example -all',
            'b.example' => 'v=spf1 include:partage.example -all',
            'partage.example' => 'v=spf1 ip4:198.51.100.0/24 -all',
        ]);

        $this->assertSame('a.example', $coverage->viaFor('198.51.100.7'));
        $this->assertSame(
            ['exemple.be', 'a.example', 'b.example', 'partage.example'],
            $this->lookups,
            'The shared record is read once, not once per branch.'
        );
    }

    public function testACyclicChainTerminatesWithoutSpendingTheBudgetOnIt(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:a.example -all',
            'a.example' => 'v=spf1 include:b.example -all',
            'b.example' => 'v=spf1 include:a.example ip4:198.51.100.0/24 -all',
        ]);

        $this->assertSame('a.example', $coverage->viaFor('198.51.100.7'));
        $this->assertSame(['exemple.be', 'a.example', 'b.example'], $this->lookups);
        $this->assertNull($coverage->partial);
    }

    /**
     * Past RFC 7208 §4.6.4's ten lookups a receiver gives up too, so a
     * range hiding behind an eleventh authorises nothing in practice.
     */
    public function testTheChainStopsAtTheLookupCeilingAndSaysSo(): void
    {
        $zone = ['exemple.be' => 'v=spf1 include:h1.example -all'];
        for ($i = 1; $i <= 11; $i++) {
            $zone['h' . $i . '.example'] = 'v=spf1 include:h' . ($i + 1) . '.example -all';
        }
        $zone['h12.example'] = 'v=spf1 ip4:198.51.100.0/24 -all';

        $coverage = $this->coverage($zone);

        $this->assertSame(SpfCoverage::PARTIAL_LOOKUPS, $coverage->partial);
        $this->assertNull($coverage->viaFor('198.51.100.7'));
        $this->assertCount(SpfCoverage::MAX_LOOKUPS + 1, $this->lookups);
    }

    public function testTheRangeCeilingIsReportedRatherThanSilentlyShortening(): void
    {
        $mechanisms = [];
        for ($i = 0; $i <= SpfCoverage::MAX_RANGES; $i++) {
            // 257 distinct /32 addresses, spread so none repeats.
            $mechanisms[] = 'ip4:10.' . intdiv($i, 256) . '.' . ($i % 256) . '.1';
        }

        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ' . implode(' ', $mechanisms) . ' -all']);

        $this->assertSame(SpfCoverage::MAX_RANGES, $coverage->rangeCount());
        $this->assertSame(SpfCoverage::PARTIAL_RANGES, $coverage->partial);
        $this->assertSame('exemple.be', $coverage->viaFor('10.0.0.1'));
        $this->assertNull($coverage->viaFor('10.1.0.1'));
    }

    /**
     * **Where the two prefix checks stop being the same check.**
     * `parseRange()` refuses a prefix longer than its address, and so does
     * `decodeRanges()` on the way back out — and because every test here
     * goes through the stored reading, the second one hides the first: a
     * mutation removing the parser's check passed every assertion above.
     *
     * The budget is where only the parser can act. A range it lets through
     * takes one of `MAX_RANGES` slots and is then dropped on read, so the
     * page reports « relevé incomplet » while holding a reading that is not
     * short at all.
     */
    public function testAMalformedRangeDoesNotSpendASlotOnItsWayToBeingDropped(): void
    {
        $mechanisms = [];
        for ($i = 0; $i < SpfCoverage::MAX_RANGES; $i++) {
            $mechanisms[] = 'ip4:10.' . intdiv($i, 256) . '.' . ($i % 256) . '.1';
        }
        $mechanisms[] = 'ip4:198.51.100.0/33';

        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ' . implode(' ', $mechanisms) . ' -all']);

        $this->assertSame(SpfCoverage::MAX_RANGES, $coverage->rangeCount());
        $this->assertNull(
            $coverage->partial,
            'A range the reader refuses must not have spent a slot on its way there.'
        );
    }

    public function testOneRangePublishedTwiceInAChainCostsOneSlot(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:a.example -all',
            'a.example' => 'v=spf1 ip4:198.51.100.0/24 include:b.example -all',
            'b.example' => 'v=spf1 ip4:198.51.100.0/24 -all',
        ]);

        $this->assertSame(1, $coverage->rangeCount());
        $this->assertSame('a.example', $coverage->viaFor('198.51.100.7'));
    }

    public function testAMalformedRangeIsDroppedWithoutTakingTheRestOfTheRecordWithIt(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 ip4:pas-une-adresse ip4:198.51.100.0/ '
                . 'ip6:198.51.100.0/24 ip4:203.0.113.0/24 -all',
        ]);

        $this->assertSame('exemple.be', $coverage->viaFor('203.0.113.7'));
        $this->assertNull($coverage->viaFor('198.51.100.7'));
    }

    /**
     * **The address the range itself names is the one that betrays a
     * prefix too long for it.** `/33` on four bytes is rejected at every
     * OTHER address by the comparison of whole bytes, so a test that only
     * asked about a neighbour passes with the guard removed — mine did.
     * At the range's own address the whole bytes match, and the comparison
     * then reaches for a fifth byte the address does not have.
     */
    public function testAPrefixLongerThanTheAddressItQualifiesIsDropped(): void
    {
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 ip4:198.51.100.0/33 ip6:2001:db8::/129 -all',
        ]);

        $this->assertSame(0, $coverage->rangeCount());
        $this->assertNull($coverage->viaFor('198.51.100.0'));
        $this->assertNull($coverage->viaFor('2001:db8::'));
    }

    public function testADomainWithNoRecordPlacesNothingAndIsNotAnError(): void
    {
        $coverage = $this->coverage(['autre.example' => 'v=spf1 ip4:198.51.100.0/24 -all']);

        $this->assertFalse($coverage->isEmpty());
        $this->assertSame(0, $coverage->rangeCount());
        $this->assertNull($coverage->viaFor('198.51.100.7'));
    }

    public function testATxtRecordThatIsNotSpfIsIgnored(): void
    {
        SpfCoverage::refresh(
            $this->settings,
            'exemple.be',
            static fn(string $host): array => $host === 'exemple.be'
                ? ['google-site-verification=abc', 'v=spf1 ip4:198.51.100.0/24 -all']
                : []
        );

        $coverage = SpfCoverage::remembered($this->settings);

        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.7'));
    }

    public function testAnEmptyDomainAsksTheResolverNothing(): void
    {
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.100.0/24 -all'], '');

        $this->assertSame([], $this->lookups);
        $this->assertSame(0, $coverage->rangeCount());
        $this->assertFalse($coverage->isEmpty());
    }

    // ── L'âge du relevé ───────────────────────────────────────────────

    public function testAReadingWithinItsAgeStillPlaces(): void
    {
        $at = new \DateTimeImmutable('2026-03-01 10:00:00');
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.100.0/24 -all'], 'exemple.be', $at);

        $later = $at->add(new \DateInterval('P' . (SpfCoverage::MAX_AGE_DAYS - 1) . 'D'));

        $this->assertFalse($coverage->isStale($later));
        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.7', $later));
    }

    /**
     * A provider's published ranges move without anybody at the unit
     * touching anything, so an old reading names an address its provider
     * may have handed back.
     */
    public function testAReadingPastItsAgePlacesNothing(): void
    {
        $at = new \DateTimeImmutable('2026-03-01 10:00:00');
        $coverage = $this->coverage(['exemple.be' => 'v=spf1 ip4:198.51.100.0/24 -all'], 'exemple.be', $at);

        $later = $at->add(new \DateInterval('P' . (SpfCoverage::MAX_AGE_DAYS + 1) . 'D'));

        $this->assertTrue($coverage->isStale($later));
        $this->assertNull($coverage->viaFor('198.51.100.7', $later));
    }

    public function testAnAbsentReadingIsNotStaleBecauseItIsAbsent(): void
    {
        $coverage = SpfCoverage::remembered($this->settings);

        $this->assertTrue($coverage->isEmpty());
        $this->assertFalse($coverage->isStale());
    }

    // ── Ce qui survit au passage par le réglage ───────────────────────

    public function testTheStoredReadingCarriesTheDateTheDomainAndTheRanges(): void
    {
        $at = new \DateTimeImmutable('2026-03-01 10:00:00');
        $coverage = $this->coverage([
            'exemple.be' => 'v=spf1 include:spf.exemple.net ip4:198.51.100.0/24 -all',
            'spf.exemple.net' => 'v=spf1 ip6:2001:db8::/32 -all',
        ], 'exemple.be', $at);

        $this->assertNotNull($coverage->takenAt);
        $this->assertSame('2026-03-01 10:00:00', $coverage->takenAt->format('Y-m-d H:i:s'));
        $this->assertSame('exemple.be', $coverage->domain);
        $this->assertSame(2, $coverage->rangeCount());
        // **The clock is named, and it is not ceremony.** Left to « now »,
        // these two assertions stop testing the round trip the day the
        // fixture's date passes `MAX_AGE_DAYS` — they then pass for the
        // staleness rule instead, quietly, for ever.
        $now = $at->add(new \DateInterval('P1D'));
        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.7', $now));
        $this->assertSame('spf.exemple.net', $coverage->viaFor('2001:db8::1', $now));
    }

    public function testASettingThatIsNotJsonReadsAsNoReadingAtAll(): void
    {
        $this->settings->setInternal(SpfCoverage::SETTING_KEY, 'pas du JSON');

        $coverage = SpfCoverage::remembered($this->settings);

        $this->assertTrue($coverage->isEmpty());
        $this->assertNull($coverage->viaFor('198.51.100.7'));
    }

    /**
     * A reading with no date could never be aged out, which is the one
     * failure `MAX_AGE_DAYS` exists to prevent.
     */
    public function testAStoredReadingWithNoDateIsDiscarded(): void
    {
        $this->settings->setInternal(
            SpfCoverage::SETTING_KEY,
            (string) json_encode([
                'domain' => 'exemple.be',
                'ranges' => [['v' => 'exemple.be', 'b' => bin2hex((string) inet_pton('198.51.100.0')), 'p' => 24]],
            ])
        );

        $coverage = SpfCoverage::remembered($this->settings);

        $this->assertTrue($coverage->isEmpty());
        $this->assertNull($coverage->viaFor('198.51.100.7'));
    }

    public function testAStoredRangeThatCannotBeReadIsDroppedNotTrusted(): void
    {
        $this->settings->setInternal(
            SpfCoverage::SETTING_KEY,
            (string) json_encode([
                'at' => '2026-03-01 10:00:00',
                'domain' => 'exemple.be',
                'vias' => ['exemple.be'],
                'ranges' => [
                    ['v' => 0, 'b' => 'pas du hex', 'p' => 24],
                    // **An index into no name.** Attributing it to whatever
                    // sits at index zero would put a range under a provider
                    // that never published it.
                    ['v' => 7, 'b' => bin2hex((string) inet_pton('203.0.113.0')), 'p' => 24],
                    // A prefix longer than the address it qualifies.
                    ['v' => 0, 'b' => bin2hex((string) inet_pton('192.0.2.0')), 'p' => 40],
                    // An attribution that is not an index at all.
                    ['v' => 'exemple.be', 'b' => bin2hex((string) inet_pton('10.0.0.0')), 'p' => 8],
                    ['v' => 0, 'b' => bin2hex((string) inet_pton('198.51.100.0')), 'p' => 24],
                ],
            ])
        );

        $coverage = SpfCoverage::remembered($this->settings);
        $now = new \DateTimeImmutable('2026-03-02 10:00:00');

        $this->assertSame(1, $coverage->rangeCount());
        $this->assertSame('exemple.be', $coverage->viaFor('198.51.100.7', $now));
        $this->assertNull($coverage->viaFor('203.0.113.7', $now));
        $this->assertNull($coverage->viaFor('192.0.2.7', $now));
        $this->assertNull($coverage->viaFor('10.0.0.1', $now));
    }

    /**
     * **The stored reading names each attribution once.** Repeating it in
     * every range is what let an encoded reading outgrow the `TEXT` column it
     * lives in — 256 ranges under a 253-character `include:` target came to
     * some 80 KB (review of #571). The assertion is on the stored bytes,
     * because that is the thing that has to fit.
     */
    public function testTheStoredReadingNamesEachAttributionOnce(): void
    {
        $long = str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.exemple.net';
        $mechanisms = [];
        for ($i = 0; $i < 100; $i++) {
            $mechanisms[] = 'ip4:10.0.' . $i . '.0/24';
        }

        $this->coverage([
            'exemple.be' => 'v=spf1 include:' . $long . ' -all',
            $long => 'v=spf1 ' . implode(' ', $mechanisms) . ' -all',
        ]);

        $stored = (string) $this->settings->get(SpfCoverage::SETTING_KEY);

        $this->assertSame(
            1,
            substr_count($stored, $long),
            'the attribution is written once and referred to by index, never repeated per range.'
        );
        $this->assertLessThan(65535, strlen($stored), 'the reading has to fit in a TEXT column.');
    }

    public function testAnUnknownPartialReasonIsNotCarriedOntoThePage(): void
    {
        $this->settings->setInternal(
            SpfCoverage::SETTING_KEY,
            (string) json_encode(['at' => '2026-03-01 10:00:00', 'domain' => 'exemple.be', 'partial' => 'inconnu'])
        );

        $this->assertNull(SpfCoverage::remembered($this->settings)->partial);
    }

    public function testIsOwnDomainSaysNoWhenNoReadingWasEverTaken(): void
    {
        $this->assertFalse(SpfCoverage::remembered($this->settings)->isOwnDomain(''));
    }
}
