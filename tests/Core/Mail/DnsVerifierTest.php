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
