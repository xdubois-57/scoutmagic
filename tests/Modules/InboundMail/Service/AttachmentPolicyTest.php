<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Client\FetchedAttachment;
use Modules\InboundMail\Service\AttachmentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Which attachments become documents and which are dropped (§7.8).
 *
 * The recurring theme: **what the email claims is never what decides**. A
 * sender writes the filename, the extension and the Content-Type; only the
 * bytes are evidence.
 */
class AttachmentPolicyTest extends TestCase
{
    private AttachmentPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AttachmentPolicy();
    }

    private static function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    }

    private static function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    // ── The allowlist ───────────────────────────────────────────────────

    public function testAPdfIsAccepted(): void
    {
        $attachment = new FetchedAttachment('contrat.pdf', 'application/pdf', self::pdfBytes());

        $this->assertTrue($this->policy->accepts($attachment, ''));
    }

    public function testAnArchiveIsRefusedEvenWhenItClaimsToBeAPdf(): void
    {
        // A zip is a container nobody inspected, and renaming it is the
        // standard way to smuggle everything on the other side of the list.
        $zip = "PK\x03\x04" . str_repeat("\x00", 60);
        $attachment = new FetchedAttachment('contrat.pdf', 'application/pdf', $zip);

        $this->assertFalse($this->policy->accepts($attachment, ''));
    }

    public function testAScriptRenamedAsAnImageIsRefused(): void
    {
        $attachment = new FetchedAttachment('photo.jpg', 'image/jpeg', "#!/bin/sh\nrm -rf /\n");

        $this->assertFalse($this->policy->accepts($attachment, ''));
    }

    public function testTheDeclaredTypeAloneNeverAdmitsAnything(): void
    {
        $attachment = new FetchedAttachment('x.pdf', 'application/pdf', 'ceci n\'est pas un pdf');

        $this->assertFalse($this->policy->accepts($attachment, ''));
    }

    public function testAnEmptyPartIsRefused(): void
    {
        $this->assertFalse($this->policy->accepts(new FetchedAttachment('vide.pdf', 'application/pdf', ''), ''));
    }

    public function testSomethingOversizeIsRefused(): void
    {
        $policy = new AttachmentPolicy(maxSizeBytes: 100);
        $attachment = new FetchedAttachment('gros.pdf', 'application/pdf', self::pdfBytes() . str_repeat('x', 500));

        $this->assertFalse($policy->accepts($attachment, ''));
    }

    // ── Signature filtering, rule 1: Content-Disposition ─────────────────

    public function testAnInlineImageReferencedByTheHtmlIsASignature(): void
    {
        $attachment = new FetchedAttachment(
            'logo.png',
            'image/png',
            self::pngBytes(1200, 800),
            isInline: true,
            contentId: 'logo123'
        );

        $this->assertTrue($this->policy->isSignatureImage($attachment, '<p>Cordialement<img src="cid:logo123"></p>'));
        $this->assertFalse($this->policy->accepts($attachment, '<p>Cordialement<img src="cid:logo123"></p>'));
    }

    public function testAnInlineImageNothingPointsAtIsNotASignature(): void
    {
        // Unusual, but some clients label a genuine attachment `inline`.
        // Treating it as a signature would silently lose a real document.
        $attachment = new FetchedAttachment(
            'photo.png',
            'image/png',
            self::pngBytes(1200, 800),
            isInline: true,
            contentId: 'orphan999'
        );

        $this->assertFalse($this->policy->isSignatureImage($attachment, '<p>Voici la photo.</p>'));
        $this->assertTrue($this->policy->accepts($attachment, '<p>Voici la photo.</p>'));
    }

    public function testARegularAttachmentIsNeverASignatureWhateverTheHtmlSays(): void
    {
        $attachment = new FetchedAttachment('photo.png', 'image/png', self::pngBytes(1200, 800));

        $this->assertFalse($this->policy->isSignatureImage($attachment, '<img src="cid:photo.png">'));
    }

    // ── Signature filtering, rule 2: pixels, not bytes ───────────────────

    public function testATinyImageIsRefusedEvenWhenItIsNotInline(): void
    {
        $attachment = new FetchedAttachment('logo.png', 'image/png', self::pngBytes(120, 60));

        $this->assertFalse($this->policy->accepts($attachment, ''));
    }

    public function testALargeImageIsAccepted(): void
    {
        $attachment = new FetchedAttachment('degat.png', 'image/png', self::pngBytes(1024, 768));

        $this->assertTrue($this->policy->accepts($attachment, ''));
    }

    public function testTheThresholdIsInPixelsNotBytes(): void
    {
        // A blank 1200×800 PNG compresses to almost nothing; a heavily
        // detailed 100×100 one can be larger. Weight says nothing about
        // whether an image is a logo, which is exactly why the rule is in
        // pixels.
        $big = self::pngBytes(1200, 800);
        $small = self::pngBytes(100, 100);

        $this->assertTrue($this->policy->accepts(new FetchedAttachment('a.png', 'image/png', $big), ''));
        $this->assertFalse($this->policy->accepts(new FetchedAttachment('b.png', 'image/png', $small), ''));
    }

    public function testAnImageWhoseSizeCannotBeReadIsKept(): void
    {
        // Failing to measure something is not evidence it is a logo. This
        // one is a PDF, so the pixel rule does not apply at all.
        $attachment = new FetchedAttachment('doc.pdf', 'application/pdf', self::pdfBytes());

        $this->assertTrue($this->policy->accepts($attachment, ''));
    }

    public function testTheMinimumEdgeIsConfigurable(): void
    {
        $lenient = new AttachmentPolicy(minImageEdgePixels: 50);

        $this->assertTrue($lenient->accepts(new FetchedAttachment('a.png', 'image/png', self::pngBytes(100, 100)), ''));
    }

    // ── Hashing ─────────────────────────────────────────────────────────

    public function testTheSameBytesAlwaysHashTheSame(): void
    {
        $bytes = self::pngBytes(300, 300);

        $this->assertSame(
            (new FetchedAttachment('a.png', 'image/png', $bytes))->contentHash(),
            (new FetchedAttachment('autre-nom.png', 'image/png', $bytes))->contentHash(),
            'The hash must be of the content, not of the name the sender chose.'
        );
    }
}
