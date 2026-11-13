<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\MailAuthenticationResults;
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
    /**
     * Every hop that checks anything prepends an `Authentication-Results`
     * of its own, and plenty of providers write one header per mechanism.
     * Reading only the first reported « non renseigné » for a verdict
     * that was written two lines further down — which, next to an IMAP
     * client that passed no headers at all, is the second reason a
     * correctly-configured domain read as unconfigured.
     */
    public function testAVerdictIsFoundInAnyAuthenticationResultsHeaderAndNotOnlyTheFirst(): void
    {
        $results = MailAuthenticationResults::parse(
            "Authentication-Results: mx2.receveur.be; dmarc=pass header.from=example.be\r\n"
                . "Authentication-Results: mx1.receveur.be; spf=pass smtp.mailfrom=example.be\r\n"
                . "Authentication-Results: mx1.receveur.be; dkim=pass header.d=example.be\r\n"
        );

        $this->assertSame('pass', $results['spf']);
        $this->assertSame('pass', $results['dkim']);
        $this->assertSame('pass', $results['dmarc']);
    }

    /**
     * The topmost header naming a mechanism wins, because headers are
     * prepended: the top one is the last server to have checked.
     */
    public function testTheTopmostVerdictForOneMechanismIsTheOneReported(): void
    {
        $results = MailAuthenticationResults::parse(
            "Authentication-Results: mx2.receveur.be; spf=fail smtp.mailfrom=example.be\r\n"
                . "Authentication-Results: mx1.receveur.be; spf=pass smtp.mailfrom=example.be\r\n"
        );

        $this->assertSame('fail', $results['spf']);
    }

    // --- senderVerified(): the trust decision (issue #1121) ---

    public function testADmarcPassFromTheReceivingServerVerifiesTheSender(): void
    {
        $headers = "Authentication-Results: mx.google.com;\r\n"
            . "       dkim=pass header.i=@exemple.be;\r\n"
            . "       spf=pass smtp.mailfrom=locataire@exemple.be;\r\n"
            . "       dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=exemple.be\r\n"
            . "Received: from mail.exemple.be\r\n";

        $this->assertTrue(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    public function testADmarcFailIsNotVerifiedWhateverSpfSays(): void
    {
        $headers = "Authentication-Results: mx.google.com; spf=pass smtp.mailfrom=bounce@exemple.be;"
            . " dmarc=fail header.from=exemple.be\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    public function testADmarcPassForAnotherFromDomainDoesNotVerify(): void
    {
        $headers = "Authentication-Results: mx.google.com; dmarc=pass header.from=attaquant.be\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    public function testWithoutDmarcOnlyAnAlignedPassCounts(): void
    {
        $aligned = "Authentication-Results: mx.ovh.net; spf=pass smtp.mailfrom=bounces@mail.exemple.be\r\n";
        $unaligned = "Authentication-Results: mx.ovh.net; spf=pass smtp.mailfrom=x@attaquant.be;"
            . " dkim=pass header.d=attaquant.be\r\n";
        $dkimAligned = "Authentication-Results: mx.ovh.net; spf=softfail smtp.mailfrom=x@relais.net;"
            . " dkim=pass header.d=exemple.be\r\n";

        $this->assertTrue(MailAuthenticationResults::senderVerified($aligned, 'locataire@exemple.be'));
        $this->assertFalse(MailAuthenticationResults::senderVerified($unaligned, 'locataire@exemple.be'));
        $this->assertTrue(MailAuthenticationResults::senderVerified($dkimAligned, 'locataire@exemple.be'));
    }

    public function testNoVerdictAtAllIsNotVerified(): void
    {
        $this->assertFalse(MailAuthenticationResults::senderVerified(null, 'locataire@exemple.be'));
        $this->assertFalse(MailAuthenticationResults::senderVerified("Subject: x\r\n", 'locataire@exemple.be'));
        $this->assertFalse(MailAuthenticationResults::senderVerified(
            "Authentication-Results: mx.google.com; dmarc=none header.from=exemple.be\r\n",
            'locataire@exemple.be'
        ));
    }

    /**
     * The audited forgery: the sender writes its own passing header, and
     * the receiving server prepends its real (failing) one above it. Only
     * the topmost is believed — parse(), a diagnosis, still reads them all.
     */
    public function testAForgedHeaderBelowTheReceivingServersIsIgnored(): void
    {
        $headers = "Authentication-Results: mx.google.com; spf=fail smtp.mailfrom=x@attaquant.be;"
            . " dmarc=fail header.from=exemple.be\r\n"
            . "Received: from attaquant.be\r\n"
            . "Authentication-Results: mx.google.com; dmarc=pass header.from=exemple.be\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    public function testCommentsCannotSmuggleAVerdict(): void
    {
        $headers = "Authentication-Results: mx.google.com;"
            . " spf=pass smtp.mailfrom=x@attaquant.be (relayed for smtp.mailfrom=locataire@exemple.be )\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    /** Comments nest (RFC 5322): the outer one must not survive the inner one's removal. */
    public function testANestedCommentCannotSmuggleAProperty(): void
    {
        $headers = "Authentication-Results: mx.example; spf=pass smtp.mailfrom=x@attaquant.be"
            . " (outer (inner) smtp.mailfrom=locataire@exemple.be )\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    /** An escaped parenthesis inside a comment neither opens nor closes it. */
    public function testAnEscapedParenthesisDoesNotEndAComment(): void
    {
        $headers = "Authentication-Results: mx.example; spf=pass smtp.mailfrom=x@attaquant.be"
            . " (note \\) smtp.mailfrom=locataire@exemple.be )\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    /** A `;` inside a quoted `reason=` is text, not the start of another result. */
    public function testAQuotedReasonCannotInventAResult(): void
    {
        $headers = "Authentication-Results: mx.example; spf=fail"
            . " reason=\"diagnostic; dkim=pass header.d=exemple.be ;\"\r\n";

        $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    /** And a genuine header that quotes a reason still verifies. */
    public function testAGenuineHeaderWithAQuotedReasonStillVerifies(): void
    {
        $headers = "Authentication-Results: mx.example; dkim=pass reason=\"2048-bit key; ok\""
            . " header.d=exemple.be; dmarc=pass (p=none) header.from=exemple.be\r\n";

        $this->assertTrue(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'));
    }

    /**
     * Microsoft 365 writes no authserv-id: the header starts with a result.
     * That first result counts — a pass verifies, and a failing DMARC there
     * still vetoes an aligned SPF pass after it.
     */
    public function testAHeaderWithoutAuthservIdIsReadFromItsFirstPart(): void
    {
        $pass = "Authentication-Results: dmarc=pass action=none header.from=exemple.be;"
            . " spf=pass (sender IP is 192.0.2.1) smtp.mailfrom=exemple.be; compauth=pass reason=100\r\n";
        $vetoed = "Authentication-Results: dmarc=fail action=quarantine header.from=exemple.be;"
            . " spf=pass smtp.mailfrom=exemple.be\r\n";

        $this->assertTrue(MailAuthenticationResults::senderVerified($pass, 'locataire@exemple.be'));
        $this->assertFalse(MailAuthenticationResults::senderVerified($vetoed, 'locataire@exemple.be'));
    }

    /** A DMARC pass whose header.from cannot be read is no alignment — absent or quoted. */
    public function testADmarcPassWithoutAReadableHeaderFromIsNotVerified(): void
    {
        foreach ([
            "Authentication-Results: mx.example; dmarc=pass\r\n",
            "Authentication-Results: mx.example; dmarc=pass header.from=\"attaquant.be\"\r\n",
        ] as $headers) {
            $this->assertFalse(MailAuthenticationResults::senderVerified($headers, 'locataire@exemple.be'), $headers);
        }
    }
}
