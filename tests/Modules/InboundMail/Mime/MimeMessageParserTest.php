<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Mime;

use Modules\InboundMail\Mime\MimeMessageParser;
use PHPUnit\Framework\TestCase;

/**
 * Parsing what actually arrives (§7.5).
 *
 * Every fixture here is a shape a Belgian scout unit's mailbox really
 * receives: an accented subject from Outlook, a multipart/alternative from
 * Gmail, an attachment named in French from a phone. The parser's job is
 * never to be strict — a malformed message must be salvaged or skipped, but
 * it must never throw and leave the whole synchronisation stuck behind it.
 */
class MimeMessageParserTest extends TestCase
{
    private MimeMessageParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MimeMessageParser();
    }

    private function raw(string ...$lines): string
    {
        return implode("\r\n", $lines);
    }

    // ── Headers ─────────────────────────────────────────────────────────

    public function testAPlainMessageIsParsed(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: Jeanne Martin <jeanne@example.be>',
            'To: locations@unite.be',
            'Subject: Demande de location',
            'Message-ID: <abc123@example.be>',
            'Date: Mon, 12 Jul 2027 09:30:00 +0200',
            '',
            'Bonjour, est-ce libre en août ?'
        ), 42, 'INBOX');

        $this->assertSame(42, $message->uid);
        $this->assertSame('INBOX', $message->folder);
        $this->assertSame('Demande de location', $message->subject);
        $this->assertSame('jeanne@example.be', $message->fromEmail);
        $this->assertSame('Jeanne Martin', $message->fromName);
        $this->assertSame('abc123@example.be', $message->messageId);
        $this->assertSame('2027-07-12', $message->sentAt->format('Y-m-d'));
        $this->assertStringContainsString('libre en août', $message->bodyText);
    }

    public function testAnAccentedSubjectIsDecoded(): void
    {
        // RFC 2047 — what any French subject looks like on the wire, and
        // the form a consumer looking for a reference would otherwise miss.
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Subject: =?UTF-8?B?UsOpc2VydmF0aW9uIFtMT0MtMjAyNy0wMDQyXQ==?=',
            'Message-ID: <a@b>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame('Réservation [LOC-2027-0042]', $message->subject);
    }

    public function testAQuotedPrintableEncodedSubjectIsDecodedToo(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Subject: =?ISO-8859-1?Q?R=E9servation_confirm=E9e?=',
            'Message-ID: <a@b>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame('Réservation confirmée', $message->subject);
    }

    public function testAdjacentEncodedWordsAreJoinedWithoutASpuriousSpace(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Subject: =?UTF-8?Q?R=C3=A9serva?= =?UTF-8?Q?tion?=',
            'Message-ID: <a@b>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame('Réservation', $message->subject);
    }

    public function testAFoldedHeaderIsUnfolded(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Subject: Demande de location pour le week-end',
            '  du 12 au 14 juillet',
            'Message-ID: <a@b>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame('Demande de location pour le week-end du 12 au 14 juillet', $message->subject);
    }

    public function testAngleBracketsAreStrippedFromEveryMessageId(): void
    {
        // Two headers naming the same message must compare equal whether or
        // not the sender wrote the brackets — that is what makes threading
        // work at all.
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <abc@example.be>',
            'In-Reply-To: <parent@unite.be>',
            'References: <root@unite.be> <parent@unite.be>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame('abc@example.be', $message->messageId);
        $this->assertSame('parent@unite.be', $message->inReplyTo);
        $this->assertSame(['root@unite.be', 'parent@unite.be'], $message->references);
    }

    public function testEveryRecipientIsCollectedLowercased(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'To: Locations@Unite.be, Chef <chef@UNITE.be>',
            'Message-ID: <a@b>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame(['locations@unite.be', 'chef@unite.be'], $message->toEmails);
    }

    public function testAMalformedDateDoesNotLoseTheMessage(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Subject: Sujet',
            'Date: pas une date du tout',
            'Message-ID: <a@b>',
            '',
            'Corps'
        ), 1, 'INBOX');

        $this->assertSame('Sujet', $message->subject);
        $this->assertNotSame('', $message->sentAt->format('Y-m-d'));
    }

    public function testAMessageWithNoHeadersAtAllIsSkippedRatherThanFatal(): void
    {
        $message = $this->parser->parse('juste du texte', 1, 'INBOX');

        $this->assertSame('', $message->subject);
        $this->assertSame('', $message->messageId);
    }

    // ── Bodies ──────────────────────────────────────────────────────────

    public function testMultipartAlternativeKeepsBothParts(): void
    {
        // Keeping only one is what makes a message unreadable (no HTML) or
        // unsearchable (no text): a consumer scans the text for a
        // reference, a manager reads the HTML.
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/alternative; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Version texte [LOC-2027-0042]',
            '--frontier',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>Version <strong>HTML</strong></p>',
            '--frontier--'
        ), 1, 'INBOX');

        $this->assertStringContainsString('Version texte [LOC-2027-0042]', $message->bodyText);
        $this->assertStringContainsString('<strong>HTML</strong>', $message->bodyHtml);
    }

    public function testABase64BodyIsDecoded(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('Réservation pour août')
        ), 1, 'INBOX');

        $this->assertSame('Réservation pour août', $message->bodyText);
    }

    public function testAQuotedPrintableBodyIsDecoded(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'R=C3=A9servation'
        ), 1, 'INBOX');

        $this->assertSame('Réservation', $message->bodyText);
    }

    public function testALatin1BodyBecomesUtf8(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: text/plain; charset=ISO-8859-1',
            '',
            (string) mb_convert_encoding('Réservation', 'ISO-8859-1', 'UTF-8')
        ), 1, 'INBOX');

        $this->assertSame('Réservation', $message->bodyText);
    }

    public function testAMultipartWithNoBoundaryIsSalvagedAsText(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/mixed',
            '',
            'Le contenu quand même'
        ), 1, 'INBOX');

        $this->assertStringContainsString('Le contenu quand même', $message->bodyText);
    }

    public function testNestedMultipartsAreWalked(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            '--outer',
            'Content-Type: multipart/alternative; boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain',
            '',
            'Texte imbriqué',
            '--inner',
            'Content-Type: text/html',
            '',
            '<p>HTML imbriqué</p>',
            '--inner--',
            '--outer--'
        ), 1, 'INBOX');

        $this->assertStringContainsString('Texte imbriqué', $message->bodyText);
        $this->assertStringContainsString('HTML imbriqué', $message->bodyHtml);
    }

    // ── Attachments ─────────────────────────────────────────────────────

    public function testAnAttachmentIsExtractedWithItsBytes(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'Voici le document.',
            '--frontier',
            'Content-Type: application/pdf; name="contrat.pdf"',
            'Content-Disposition: attachment; filename="contrat.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 fake'),
            '--frontier--'
        ), 1, 'INBOX');

        $this->assertCount(1, $message->attachments);
        $this->assertSame('contrat.pdf', $message->attachments[0]->filename);
        $this->assertSame('%PDF-1.4 fake', $message->attachments[0]->bytes);
        $this->assertFalse($message->attachments[0]->isInline);
    }

    public function testAnRfc2231EncodedFilenameIsDecoded(): void
    {
        // The ASCII `filename` a sender leaves behind for old clients is a
        // mangled fallback; `filename*` is the real name.
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="etat.pdf"; filename*=UTF-8\'\'%C3%89tat%20des%20lieux.pdf',
            '',
            'contenu',
            '--frontier--'
        ), 1, 'INBOX');

        $this->assertSame('État des lieux.pdf', $message->attachments[0]->filename);
    }

    public function testAnRfc2047EncodedFilenameIsDecodedToo(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="=?UTF-8?B?UsOpc3Vtw6kucGRm?="',
            '',
            'contenu',
            '--frontier--'
        ), 1, 'INBOX');

        $this->assertSame('Résumé.pdf', $message->attachments[0]->filename);
    }

    public function testAnInlineImageKeepsItsContentIdSoItCanBeRecognisedAsASignature(): void
    {
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/related; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/html',
            '',
            '<p>Cordialement<img src="cid:logo123"></p>',
            '--frontier',
            'Content-Type: image/png; name="logo.png"',
            'Content-Disposition: inline; filename="logo.png"',
            'Content-ID: <logo123>',
            '',
            'binaire',
            '--frontier--'
        ), 1, 'INBOX');

        $this->assertCount(1, $message->attachments);
        $this->assertTrue($message->attachments[0]->isInline);
        $this->assertSame('logo123', $message->attachments[0]->contentId);
    }

    public function testAnInlinePartWithNoFilenameIsBodyNotAnAttachment(): void
    {
        // A `related` message's HTML arrives exactly this way; treating it
        // as an attachment would lose the body and store a stray file.
        $message = $this->parser->parse($this->raw(
            'From: jeanne@example.be',
            'Message-ID: <a@b>',
            'Content-Type: multipart/related; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/html',
            'Content-Disposition: inline',
            '',
            '<p>Le corps</p>',
            '--frontier--'
        ), 1, 'INBOX');

        $this->assertSame([], $message->attachments);
        $this->assertStringContainsString('<p>Le corps</p>', $message->bodyHtml);
    }

    public function testADeeplyNestedMessageStopsRatherThanRecursingForever(): void
    {
        $raw = "From: a@b\r\nMessage-ID: <a@b>\r\n";
        for ($level = 0; $level < 30; $level++) {
            $raw .= "Content-Type: multipart/mixed; boundary=\"b{$level}\"\r\n\r\n--b{$level}\r\n";
        }

        $message = $this->parser->parse($raw, 1, 'INBOX');

        $this->assertSame('a@b', $message->messageId);
    }
}
