<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\SupportDashboard\Service\MailAuthenticationResults;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * The header block on the **wire** path, and the reason it went missing.
 *
 * `ImapMailboxClient::toFetchedMessage()` simply never passed one, so
 * `FetchedMessage::$rawHeaders` fell back to its `''` default for every
 * message this site has ever received over IMAP. That is the whole of
 * `Api\MessageRetentionPreference::wantsRawHeaders()` (roadmap IT-22)
 * feeding on an empty string, and it is why the diagnostic mail probe
 * (IT-27) reported « SPF non renseigné, DKIM non renseigné, DMARC non
 * renseigné » on a message whose headers said `pass` three times.
 *
 * It was invisible from the tests, which is the part worth pinning:
 * `Client\FakeMailboxClient` parses real MIME through
 * `Mime\MimeMessageParser`, which fills the field, so every existing test
 * about headers exercised a path production does not take.
 */
class ImapRawHeadersTest extends TestCase
{
    /**
     * A probe as a real relay hands it over: several `Received` lines, an
     * `Authentication-Results` folded across four lines, a DKIM
     * signature.
     */
    private const RAW = "Return-Path: <unite@example.be>\r\n"
        . "Received: from mail.example.be (mail.example.be [203.0.113.7])\r\n"
        . "\tby mx.receveur.be with ESMTPS id abc123;\r\n"
        . "\tMon, 1 Sep 2026 10:00:00 +0200\r\n"
        . "Authentication-Results: mx.receveur.be;\r\n"
        . "\tspf=pass smtp.mailfrom=example.be;\r\n"
        . "\tdkim=pass header.d=example.be;\r\n"
        . "\tdmarc=pass header.from=example.be\r\n"
        . "DKIM-Signature: v=1; a=rsa-sha256; d=example.be; s=mail; b=abcd\r\n"
        . "From: Unite <unite@example.be>\r\n"
        . "To: support@scoutmagic.be\r\n"
        . "Subject: [25SV] Sonde de diagnostic SMP-ABCDEFGHJK\r\n"
        . "Message-ID: <probe-1@example.be>\r\n"
        . "Date: Mon, 1 Sep 2026 10:00:00 +0200\r\n"
        . "\r\n"
        . "Corps\r\n";

    private function fetch(string $raw): \Modules\InboundMail\Client\FetchedMessage
    {
        $method = new \ReflectionMethod(ImapMailboxClient::class, 'toFetchedMessage');
        $client = (new \ReflectionClass(ImapMailboxClient::class))->newInstanceWithoutConstructor();

        return $method->invoke($client, Message::fromString($raw), 'INBOX', 42);
    }

    public function testTheHeaderBlockReachesTheFetchedMessage(): void
    {
        $fetched = $this->fetch(self::RAW);

        $this->assertNotSame('', $fetched->rawHeaders, 'le client IMAP ne transmet aucun en-tête');
        $this->assertStringContainsString('Authentication-Results:', $fetched->rawHeaders);
        $this->assertStringContainsString('DKIM-Signature:', $fetched->rawHeaders);
    }

    /**
     * The end of the chain, which is what the bug report was actually
     * about: three `pass` in the headers reading as three « non
     * renseigné » on the ticket page.
     */
    public function testWhatTheProbeReadsFromItIsWhatTheServerWroteDown(): void
    {
        $results = MailAuthenticationResults::parse($this->fetch(self::RAW)->rawHeaders);

        $this->assertSame('pass', $results['spf']);
        $this->assertSame('pass', $results['dkim']);
        $this->assertSame('pass', $results['dmarc']);
        $this->assertNotEmpty($results['relays']);
    }

    /**
     * The body is not in it. A header block that carried the message
     * would be a copy of the message in a column that exists so the
     * message does NOT have to be kept.
     */
    public function testTheBodyIsNotPartOfTheHeaderBlock(): void
    {
        $this->assertStringNotContainsString('Corps', $this->fetch(self::RAW)->rawHeaders);
    }
}
