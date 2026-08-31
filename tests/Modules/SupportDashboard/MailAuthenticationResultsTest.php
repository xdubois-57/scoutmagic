<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Service\MailAuthenticationResults;
use PHPUnit\Framework\TestCase;

/**
 * What a raw header block is read to say (roadmap IT-27).
 *
 * The distinction this file exists to pin is the one a maintainer must
 * not have to make themselves: an ABSENT header is not a failing check.
 * « Non renseigné » and « échec » lead to opposite actions, and reading
 * the first as the second would send somebody chasing a DNS record that
 * is perfectly fine.
 */
class MailAuthenticationResultsTest extends TestCase
{
    public function testAnAuthenticationResultsHeaderIsReadAsWritten(): void
    {
        $parsed = MailAuthenticationResults::parse(
            "Authentication-Results: mx.example.be; spf=pass; dkim=fail; dmarc=none\r\n"
        );

        $this->assertSame('pass', $parsed['spf']);
        $this->assertSame('fail', $parsed['dkim']);
        $this->assertSame('none', $parsed['dmarc']);
    }

    /**
     * The header folds, and a verdict on a continuation line is still
     * that header's verdict.
     */
    public function testAFoldedHeaderIsReadWhole(): void
    {
        $parsed = MailAuthenticationResults::parse(
            "Authentication-Results: mx.example.be;\r\n"
                . "\tspf=pass smtp.mailfrom=unite.be;\r\n"
                . "\tdmarc=pass header.from=unite.be\r\n"
        );

        $this->assertSame('pass', $parsed['spf']);
        $this->assertSame('pass', $parsed['dmarc']);
    }

    /**
     * Plenty of hosts write `Received-SPF` and no `Authentication-Results`
     * at all.
     */
    public function testReceivedSpfIsUsedWhenThereIsNoSummaryHeader(): void
    {
        $parsed = MailAuthenticationResults::parse("Received-SPF: pass (mx.example.be: domain of unite.be)\r\n");

        $this->assertSame('pass', $parsed['spf']);
    }

    /**
     * A signature nobody wrote a verdict for is « signée, verdict
     * inconnu » — deliberately not a pass.
     */
    public function testASignatureWithNoVerdictIsNotAPass(): void
    {
        $parsed = MailAuthenticationResults::parse("DKIM-Signature: v=1; a=rsa-sha256; d=unite.be;\r\n");

        $this->assertSame('unverified', $parsed['dkim']);
    }

    public function testAMissingHeaderIsSaidToBeMissingAndNotFailing(): void
    {
        $parsed = MailAuthenticationResults::parse("Subject: Sonde\r\n");

        $this->assertSame('absent', $parsed['spf']);
        $this->assertSame('absent', $parsed['dkim']);
        $this->assertSame('absent', $parsed['dmarc']);
        $this->assertSame([], $parsed['relays']);
    }

    public function testNoHeadersAtAllIsTheSameCompleteAnswer(): void
    {
        $this->assertSame(
            ['spf' => 'absent', 'dkim' => 'absent', 'dmarc' => 'absent', 'relays' => []],
            MailAuthenticationResults::parse(null)
        );
    }

    public function testTheRelayChainIsKeptInOrderAndBounded(): void
    {
        $headers = '';
        for ($i = 1; $i <= 20; $i++) {
            $headers .= "Received: from relay{$i}.example.be by mx.example.be\r\n";
        }

        $relays = MailAuthenticationResults::parse($headers)['relays'];

        // Bounded: past a dozen hops the chain is noise, and it is stored
        // encrypted precisely because it carries hosts and IP addresses.
        $this->assertCount(12, $relays);
        $this->assertStringContainsString('relay1.example.be', $relays[0]);
        $this->assertStringContainsString('relay12.example.be', $relays[11]);
    }

    public function testAFoldedReceivedLineIsOneRelayAndNotTwo(): void
    {
        $relays = MailAuthenticationResults::parse(
            "Received: from relay.example.be (relay.example.be [198.51.100.7])\r\n"
                . "\tby mx.example.be with ESMTPS id abc123\r\n"
        )['relays'];

        $this->assertCount(1, $relays);
        $this->assertStringContainsString('relay.example.be', $relays[0]);
        $this->assertStringContainsString('mx.example.be', $relays[0]);
    }
}
