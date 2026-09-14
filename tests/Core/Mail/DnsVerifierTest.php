<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DnsVerifier;
use PHPUnit\Framework\TestCase;

class DnsVerifierTest extends TestCase
{
    /**
     * Uses FakeDnsVerifier (no real record for this host) rather than a
     * live lookup on example.com: that domain genuinely has its own SPF/
     * DMARC records in the real world, which this environment can reach —
     * a live assertion here would be both flaky (breaks if example.com's
     * records ever change) and, after the merge-aware rewrite below,
     * simply wrong (the real expected value now depends on what's already
     * there, exactly the behavior being tested deliberately elsewhere).
     */
    public function testCheckSpfReturnsCorrectExpectedValueForSmtpMode(): void
    {
        $result = (new FakeDnsVerifier([]))->checkSpf('example.com', 'smtp', 'smtp.gmail.com');

        $this->assertSame('v=spf1 a:smtp.gmail.com ~all', $result['expected']);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('actual', $result);
    }

    public function testCheckSpfReturnsCorrectExpectedValueForLocalMode(): void
    {
        $result = (new FakeDnsVerifier([]))->checkSpf('example.com', 'local');

        $this->assertSame('v=spf1 a mx ~all', $result['expected']);
    }

    public function testCheckDkimReturnsCorrectExpectedValue(): void
    {
        $publicKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...';
        $result = (new FakeDnsVerifier([]))->checkDkim('example.com', 'mail', $publicKey);

        $this->assertSame("v=DKIM1; k=rsa; p={$publicKey}", $result['expected']);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('actual', $result);
    }

    public function testCheckDmarcReturnsCorrectExpectedValue(): void
    {
        $result = (new FakeDnsVerifier([]))->checkDmarc('example.com', 'dmarc@example.com');

        $this->assertSame('v=DMARC1; p=none; rua=mailto:dmarc@example.com', $result['expected']);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('actual', $result);
    }

    public function testCheckSpfMergesMissingMechanismIntoExistingRecordPreservingQualifier(): void
    {
        $verifier = new FakeDnsVerifier([
            'example.com' => ['v=spf1 mx:example.com a:mail.example.com -all'],
        ]);

        $result = $verifier->checkSpf('example.com', 'smtp', 'smtp.example-relay.com');

        $this->assertSame(
            'v=spf1 mx:example.com a:mail.example.com a:smtp.example-relay.com -all',
            $result['expected']
        );
        $this->assertFalse($result['exists']);
    }

    public function testCheckSpfReturnsExistingRecordUnchangedWhenMechanismAlreadyPresent(): void
    {
        $verifier = new FakeDnsVerifier([
            'example.com' => ['v=spf1 a:smtp.example-relay.com -all'],
        ]);

        $result = $verifier->checkSpf('example.com', 'smtp', 'smtp.example-relay.com');

        $this->assertSame('v=spf1 a:smtp.example-relay.com -all', $result['expected']);
        $this->assertTrue($result['exists']);
    }

    /**
     * Regression: the mechanism must be the exact SMTP host given, never a
     * guessed "include:_spf.<domain>" — that convention only holds for
     * providers that publish their own dedicated _spf.<domain> record
     * (Google, Mailgun, etc.), and fabricating one for an arbitrary host
     * (here, the operator's own mail server on their own domain) points at
     * a TXT record that doesn't exist, which causes SPF PermError — worse
     * than the missing-record warning this is meant to fix. Reported after
     * this exact case (self-hosted mail on the sending domain itself) got
     * suggested as a real DNS edit and rejected by the registrar's own
     * validation before it could do any damage.
     */
    public function testCheckSpfNeverFabricatesAnIncludeMechanism(): void
    {
        $verifier = new FakeDnsVerifier([
            'scoutmagic.be' => ['v=spf1 mx:scoutmagic.be a:mail.scoutmagic.be a:mailphp.lws-hosting.com -all'],
        ]);

        $result = $verifier->checkSpf('scoutmagic.be', 'smtp', 'mail.scoutmagic.be');

        $this->assertStringNotContainsString('include:_spf.', $result['expected']);
        $this->assertSame(
            'v=spf1 mx:scoutmagic.be a:mail.scoutmagic.be a:mailphp.lws-hosting.com -all',
            $result['expected']
        );
        $this->assertTrue($result['exists']);
    }

    public function testCheckSpfLocalModeReturnsExistingRecordUnchanged(): void
    {
        $verifier = new FakeDnsVerifier([
            'example.com' => ['v=spf1 a mx -all'],
        ]);

        $result = $verifier->checkSpf('example.com', 'local');

        $this->assertSame('v=spf1 a mx -all', $result['expected']);
    }

    public function testCheckDmarcAddsMissingRuaToExistingRecordPreservingPolicy(): void
    {
        $verifier = new FakeDnsVerifier([
            '_dmarc.example.com' => ['v=DMARC1; p=quarantine;'],
        ]);

        $result = $verifier->checkDmarc('example.com', 'dmarc@example.com');

        $this->assertSame('v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com', $result['expected']);
        $this->assertFalse($result['exists']);
    }

    public function testCheckDmarcNeverOverridesAnExistingRuaEvenWhenDifferent(): void
    {
        $verifier = new FakeDnsVerifier([
            '_dmarc.example.com' => ['v=DMARC1; p=reject; rua=mailto:other@thirdparty.com'],
        ]);

        $result = $verifier->checkDmarc('example.com', 'dmarc@example.com');

        $this->assertSame('v=DMARC1; p=reject; rua=mailto:other@thirdparty.com', $result['expected']);
        $this->assertFalse($result['exists']);
    }

    // ── a chain of relays, not one (roadmap IT-03) ────────────────────

    /**
     * A chain is only as authorised as its least authorised entry: the
     * day the first relay is down, the message leaves through the second,
     * and an SPF record naming only the first fails on exactly the
     * messages the fallback exists to save.
     */
    public function testEveryRelayOfTheChainHasToBeInTheRecord(): void
    {
        $verifier = new FakeDnsVerifier([
            'unite.be' => ['v=spf1 a:relais-un.example ~all'],
        ]);

        $result = $verifier->checkSpfForHosts('unite.be', ['relais-un.example', 'relais-deux.example']);

        $this->assertFalse($result['exists']);
        $this->assertSame('v=spf1 a:relais-un.example a:relais-deux.example ~all', $result['expected']);
    }

    public function testARecordAlreadyNamingEveryRelayIsLeftExactlyAsItIs(): void
    {
        $published = 'v=spf1 a:relais-un.example a:relais-deux.example -all';
        $verifier = new FakeDnsVerifier(['unite.be' => [$published]]);

        $result = $verifier->checkSpfForHosts('unite.be', ['relais-deux.example', 'relais-un.example']);

        $this->assertTrue($result['exists']);
        $this->assertSame($published, $result['expected']);
    }

    public function testTheSameRelayNamedTwiceIsProposedOnce(): void
    {
        $result = (new FakeDnsVerifier([]))->checkSpfForHosts(
            'unite.be',
            ['relais.example', 'relais.example']
        );

        $this->assertSame('v=spf1 a:relais.example ~all', $result['expected']);
    }

    /**
     * No relay at all leaves the question unanswerable, and this used to
     * answer it « oui ».
     *
     * The old name of this test — « an existing record is enough » —
     * spelled the defect out: with nothing to look for, the loop had
     * nothing to falsify, so ANY `v=spf1` passed. On a site sending from
     * the web server itself, `v=spf1 include:spf.protection.outlook.com
     * -all` (mailboxes at one host, the site at another) hard-fails every
     * message while this reported « en place ». Saying so for real needs
     * the server's own address evaluated against the whole record,
     * `include:` recursion and all — an SPF evaluator, not this.
     *
     * The record is still left exactly as it is: there is nothing to
     * propose adding either.
     */
    public function testWithoutAnyRelayAPublishedRecordIsUnverifiableRatherThanValid(): void
    {
        $published = 'v=spf1 a mx -all';
        $verifier = new FakeDnsVerifier(['unite.be' => [$published]]);

        $result = $verifier->checkSpfForHosts('unite.be', []);

        $this->assertFalse($result['exists']);
        $this->assertTrue($result['unverifiable']);
        $this->assertSame($published, $result['expected']);
    }

    /**
     * And « unverifiable » is reserved for a record that exists. With no
     * record at all, nothing authorises anything, and establishing that
     * needs no relay list — so the answer stays a plain « absent », with
     * `a mx` proposed.
     */
    public function testWithoutAnyRelayAndWithoutAnyRecordTheAnswerIsStillAbsent(): void
    {
        $result = (new FakeDnsVerifier([]))->checkSpfForHosts('unite.be', []);

        $this->assertFalse($result['exists']);
        $this->assertFalse($result['unverifiable']);
        $this->assertSame('v=spf1 a mx ~all', $result['expected']);
    }

    /**
     * A substring test is satisfied by a longer host on a domain the
     * operator may not even control: `a:relais.example` is inside
     * `a:relais.example.net`. The reading would then say « en place » and
     * propose nothing, leaving the relay that is actually missing
     * unauthorised with the screen saying it was fine.
     */
    public function testALongerHostNameDoesNotSatisfyAShorterOne(): void
    {
        $verifier = new FakeDnsVerifier([
            'unite.be' => ['v=spf1 a:relais.example.net ~all'],
        ]);

        $result = $verifier->checkSpfForHosts('unite.be', ['relais.example']);

        $this->assertFalse($result['exists']);
        $this->assertSame('v=spf1 a:relais.example.net a:relais.example ~all', $result['expected']);
    }

    public function testAMechanismInsideAnotherTokenIsNotAMatchEither(): void
    {
        // `include:_spf.relais.example` carries the host as a substring
        // and authorises nothing of the sort.
        $verifier = new FakeDnsVerifier([
            'unite.be' => ['v=spf1 include:_spf.a:relais.example -all'],
        ]);

        $this->assertFalse($verifier->checkSpfForHosts('unite.be', ['relais.example'])['exists']);
    }

    public function testAnEmptyHostNameNeverBecomesABareMechanism(): void
    {
        // `mode=smtp` with an empty smtp_host used to make the reading
        // search the record for the string « a: », which any record with
        // a single `a:` mechanism satisfies — a green light on a host
        // nobody had named.
        $verifier = new FakeDnsVerifier(['unite.be' => ['v=spf1 a:autre.example ~all']]);

        $result = $verifier->checkSpf('unite.be', 'smtp', '');

        // Unverifiable rather than « en place »: an empty host leaves no
        // mechanism to look for, which is the no-relay case above. What
        // this test guards is that it never becomes a bare « a: » search
        // either, which the single `a:autre.example` would have satisfied.
        $this->assertFalse($result['exists']);
        $this->assertTrue($result['unverifiable']);
        $this->assertSame('v=spf1 a:autre.example ~all', $result['expected']);
    }

    /**
     * SPF mechanism names and domain-specs are case-insensitive
     * (RFC 7208 §4.6.1). A record written by hand, or by a registrar's
     * form that title-cases what it is given, authorises exactly what the
     * lowercase form authorises — and a reading that calls it « manquant »
     * sends a correctly configured operator to fix a record that is
     * already right.
     */
    public function testTheCaseOfAPublishedMechanismDoesNotMatter(): void
    {
        $verifier = new FakeDnsVerifier([
            'unite.be' => ['v=spf1 A:Relais.Example.COM ~all'],
        ]);

        $result = $verifier->checkSpfForHosts('unite.be', ['relais.example.com']);

        $this->assertTrue($result['exists']);
        $this->assertSame('v=spf1 A:Relais.Example.COM ~all', $result['expected'], 'The record is left as it is.');
    }

    /**
     * `+` IS the default qualifier: `+a:host` and `a:host` are the same
     * mechanism, and cPanel/WHM-generated records write the explicit form
     * — exactly the shared-hosting relay this file's own fixtures target.
     * Proposing to add the bare form next to it would push the record one
     * DNS lookup closer to the ten RFC 7208 §4.6.4 allows, for nothing.
     */
    public function testAnExplicitPlusQualifierIsTheSameMechanism(): void
    {
        $verifier = new FakeDnsVerifier([
            'unite.be' => ['v=spf1 +a:mailphp.lws-hosting.com ~all'],
        ]);

        $result = $verifier->checkSpfForHosts('unite.be', ['mailphp.lws-hosting.com']);

        $this->assertTrue($result['exists']);
        $this->assertSame('v=spf1 +a:mailphp.lws-hosting.com ~all', $result['expected']);
    }

    /**
     * And the three other qualifiers are NOT dropped: `-a:host` says the
     * opposite of `a:host`. Treating them as the same would report a
     * record that explicitly refuses the relay as authorising it — the
     * one reading worse than no reading at all.
     */
    public function testAMechanismExplicitlyRefusedIsNotAMechanismInPlace(): void
    {
        $verifier = new FakeDnsVerifier([
            'unite.be' => ['v=spf1 -a:relais.example ~all'],
        ]);

        $result = $verifier->checkSpfForHosts('unite.be', ['relais.example']);

        $this->assertFalse($result['exists']);
        $this->assertSame('v=spf1 -a:relais.example a:relais.example ~all', $result['expected']);
    }
}

/**
 * getTxtRecords() is explicitly documented as "overridable for testing" —
 * this fixture stubs it with canned per-host records instead of a real
 * dns_get_record() call, so the merge logic above is deterministic.
 */
final class FakeDnsVerifier extends DnsVerifier
{
    /**
     * @param array<string, array<string>> $recordsByHost
     */
    public function __construct(private array $recordsByHost)
    {
    }

    protected function getTxtRecords(string $host): array
    {
        return $this->recordsByHost[$host] ?? [];
    }
}
