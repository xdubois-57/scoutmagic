<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DnsVerifier;
use PHPUnit\Framework\TestCase;

class DnsVerifierTest extends TestCase
{
    private DnsVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new DnsVerifier();
    }

    public function testCheckSpfReturnsCorrectExpectedValueForSmtpMode(): void
    {
        $result = $this->verifier->checkSpf('example.com', 'smtp', 'gmail.com');

        $this->assertSame('v=spf1 include:_spf.gmail.com ~all', $result['expected']);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('actual', $result);
    }

    public function testCheckSpfReturnsCorrectExpectedValueForLocalMode(): void
    {
        $result = $this->verifier->checkSpf('example.com', 'local');

        $this->assertSame('v=spf1 a mx ~all', $result['expected']);
    }

    public function testCheckDkimReturnsCorrectExpectedValue(): void
    {
        $publicKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...';
        $result = $this->verifier->checkDkim('example.com', 'mail', $publicKey);

        $this->assertSame("v=DKIM1; k=rsa; p={$publicKey}", $result['expected']);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('actual', $result);
    }

    public function testCheckDmarcReturnsCorrectExpectedValue(): void
    {
        $result = $this->verifier->checkDmarc('example.com', 'dmarc@example.com');

        $this->assertSame('v=DMARC1; p=none; rua=mailto:dmarc@example.com', $result['expected']);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('actual', $result);
    }
}
