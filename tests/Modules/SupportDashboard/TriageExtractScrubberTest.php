<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Service\TriageExtractScrubber;
use PHPUnit\Framework\TestCase;

/**
 * What the extract replaces, and what it must leave alone
 * (ARCHITECTURE.md §8.49sexies).
 *
 * The two halves matter equally. A scrubber that misses an address
 * leaks; one that eats `Foo::bar` or `12:30:45` hands the triage a log
 * it cannot read. Every case here is one of those two failures, seen
 * before it happened.
 */
class TriageExtractScrubberTest extends TestCase
{
    private TriageExtractScrubber $scrubber;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->scrubber = new TriageExtractScrubber();
    }

    public function testAnIpv4AddressBecomesAStableToken(): void
    {
        $out = $this->scrubber->scrub(
            "203.0.113.7 - - [06/Sep/2026] GET /members\n"
            . "198.51.100.2 - - [06/Sep/2026] GET /login\n"
            . "203.0.113.7 - - [06/Sep/2026] POST /login\n"
        );

        $this->assertStringNotContainsString('203.0.113.7', $out);
        $this->assertStringNotContainsString('198.51.100.2', $out);
        $this->assertSame(2, substr_count($out, 'ip-1'), 'the same address must map to the same token');
        $this->assertSame(1, substr_count($out, 'ip-2'));
        $this->assertSame(2, $this->scrubber->count('ip'));
    }

    public function testAThreePartVersionNumberIsNotAnAddress(): void
    {
        $this->assertSame('ScoutMagic 1.0.41 sur PHP 8.4.0', $this->scrubber->scrub('ScoutMagic 1.0.41 sur PHP 8.4.0'));
    }

    public function testAnAddressClosingASentenceIsStillAnAddress(): void
    {
        $this->assertSame('depuis ip-1. Puis (ip-1)', $this->scrubber->scrub('depuis 203.0.113.50. Puis (203.0.113.50)'));
    }

    public function testIpv6AddressesAreReplacedInBothShapes(): void
    {
        $out = $this->scrubber->scrub(
            'full 2001:0db8:85a3:0000:0000:8a2e:0370:7334 compressed 2001:db8::1 loopback ::1 mapped ::ffff:203.0.113.7'
        );

        $this->assertStringNotContainsString('2001:0db8', $out);
        $this->assertStringNotContainsString('2001:db8::1', $out);
        $this->assertStringNotContainsString('::1', $out);
        $this->assertStringNotContainsString('203.0.113.7', $out);
        $this->assertMatchesRegularExpression('/full ip-\d+ compressed ip-\d+ loopback ip-\d+ mapped/', $out);
    }

    public function testATimeAndAClassMethodAreNotIpv6Addresses(): void
    {
        $line = '[2026-09-06 12:30:45] Modules\\Groups\\Service\\ModerationService::isAvailable() face::cafe';

        $this->assertSame($line, $this->scrubber->scrub($line));
    }

    public function testAnEmailAddressBecomesAStableToken(): void
    {
        $out = $this->scrubber->scrub('to chef@unite.be, cc Chef@Unite.be and parent@example.org');

        $this->assertStringNotContainsString('@unite.be', $out);
        $this->assertStringNotContainsString('@example.org', $out);
        $this->assertSame('to email-1, cc email-1 and email-2', $out, 'case must not split one address in two');
    }

    public function testALongHexadecimalIdentifierIsReplacedAndAShortOneKept(): void
    {
        $session = str_repeat('ab12', 8);
        $out = $this->scrubber->scrub("PHPSESSID={$session}; commit 3f2a9c1 file deadbeef01234567");

        $this->assertStringNotContainsString($session, $out);
        $this->assertStringContainsString('PHPSESSID=hex-1', $out);
        $this->assertStringContainsString('commit 3f2a9c1', $out);
        $this->assertStringContainsString('file deadbeef01234567', $out);
    }

    public function testTheValueOfASensitiveQueryParameterIsMasked(): void
    {
        $out = $this->scrubber->scrub('GET /reset?token=abc.def-123&page=2&email=x%40y.be HTTP/1.1');

        $this->assertSame('GET /reset?token=…&page=2&email=… HTTP/1.1', $out);
    }

    public function testATokenIsAllocatedOnceWhateverTheKindLooksLike(): void
    {
        $this->assertSame('user-1', $this->scrubber->token('user', '42'));
        $this->assertSame('user-2', $this->scrubber->token('user', '7'));
        $this->assertSame('user-1', $this->scrubber->token('user', '42'));
        $this->assertSame(2, $this->scrubber->count('user'));
        $this->assertSame(0, $this->scrubber->count('ip'));
    }
}
