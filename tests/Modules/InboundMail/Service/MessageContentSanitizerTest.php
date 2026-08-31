<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Service\MessageContentSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Making an untrusted email body safe (§7.9).
 *
 * The tracking-pixel tests are the ones that matter most: a remote image in
 * a stranger's email is a read receipt, and loading it tells the sender when
 * a manager opened their message and from which network. That is not a
 * theoretical risk — it is what every mass-mailing tool does by default.
 */
class MessageContentSanitizerTest extends TestCase
{
    private MessageContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new MessageContentSanitizer(new HtmlSanitizer());
    }

    public function testScriptsAreRemovedEntirely(): void
    {
        $html = $this->sanitizer->sanitizeHtml('<p>Bonjour</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('Bonjour', $html);
    }

    public function testATrackingPixelIsNeverKept(): void
    {
        $html = $this->sanitizer->sanitizeHtml(
            '<p>Bonjour</p><img src="https://tracker.example/p.gif?u=42" width="1" height="1">'
        );

        $this->assertStringNotContainsString('tracker.example', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testAnOrdinaryRemoteImageIsRemovedToo(): void
    {
        // Not only 1×1 pixels: any remote fetch tells the sender the
        // message was opened. There is no size at which that stops being
        // true, so there is no size threshold here.
        $html = $this->sanitizer->sanitizeHtml('<p><img src="https://example.be/photo.jpg" width="800"></p>');

        $this->assertStringNotContainsString('<img', $html);
    }

    public function testABodyThatWasOnlyAnImageComesBackEmptyRatherThanAnEmptyBox(): void
    {
        // A receipt photographed and sent from a phone: the whole message
        // is one image. Removing it must not leave `<div></div>` behind —
        // the screen renders that as a blank card, which a reader cannot
        // tell from a message that failed to arrive.
        $this->assertSame('', $this->sanitizer->sanitizeHtml('<div><img src="cid:photo1"></div>'));
    }

    public function testTheNonBreakingSpaceAClientLeavesBehindDoesNotCountAsText(): void
    {
        // What a mail client puts in the cell the image used to fill.
        $this->assertSame(
            '',
            $this->sanitizer->sanitizeHtml('<table><tr><td><img src="cid:x">&nbsp;</td></tr></table>')
        );
    }

    public function testAMessageWithOneWordBesideItsImageIsStillKept(): void
    {
        // The rule is "no text at all", not "little text": a body that says
        // « Voici » next to a photo is a body.
        $html = $this->sanitizer->sanitizeHtml('<div><img src="cid:photo1"><p>Voici</p></div>');

        $this->assertStringContainsString('Voici', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testACidImageIsRemovedBecauseABrowserCannotResolveIt(): void
    {
        $html = $this->sanitizer->sanitizeHtml('<p>Cordialement<img src="cid:logo123"></p>');

        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringContainsString('Cordialement', $html);
    }

    public function testABackgroundImageAttributeIsStrippedAsWell(): void
    {
        // Same tracking problem reached through a different attribute —
        // still a remote fetch the moment the message is rendered.
        $html = $this->sanitizer->sanitizeHtml('<p background="https://tracker.example/bg.png">Texte</p>');

        $this->assertStringNotContainsString('tracker.example', $html);
        $this->assertStringContainsString('Texte', $html);
    }

    public function testAJavascriptHrefDoesNotSurvive(): void
    {
        $html = $this->sanitizer->sanitizeHtml('<p><a href="javascript:alert(1)">clic</a></p>');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testOrdinaryFormattingIsKept(): void
    {
        $html = $this->sanitizer->sanitizeHtml('<p>Bonjour <strong>Jeanne</strong>, <em>merci</em>.</p>');

        $this->assertStringContainsString('<strong>Jeanne</strong>', $html);
        $this->assertStringContainsString('<em>merci</em>', $html);
    }

    public function testAnEmptyBodyStaysEmpty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitizeHtml('   '));
    }

    // ── Text ────────────────────────────────────────────────────────────

    public function testControlCharactersAreStrippedFromText(): void
    {
        $text = $this->sanitizer->sanitizeText("Bonjour\x00\x1b[31m rouge");

        $this->assertStringNotContainsString("\x00", $text);
        $this->assertStringNotContainsString("\x1b", $text);
        $this->assertStringContainsString('Bonjour', $text);
    }

    public function testANewlineIsNotAControlCharacterToStrip(): void
    {
        $text = $this->sanitizer->sanitizeText("Ligne un\nLigne deux");

        $this->assertSame("Ligne un\nLigne deux", $text);
    }

    public function testAReferenceInTheTextIsLeftExactlyAsWritten(): void
    {
        // This is what a consumer scans (§7.6, level 1). Normalising it —
        // collapsing whitespace, changing case — is how a reference the
        // sender typed correctly stops matching.
        $text = $this->sanitizer->sanitizeText('Notre dossier [LOC-2027-0042] pour août');

        $this->assertStringContainsString('[LOC-2027-0042]', $text);
    }

    public function testInvalidUtf8DoesNotLoseTheWholeBody(): void
    {
        $text = $this->sanitizer->sanitizeText("Bonjour \xC3\x28 la suite");

        $this->assertStringContainsString('Bonjour', $text);
    }
}
