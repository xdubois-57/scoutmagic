<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Client\FetchedAttachment;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Api\AttachmentOmission;
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

    /**
     * Issue #1121: the binary Office formats of before 2007 carry macros in
     * the same file as the text. The list is the boundary twice over — the
     * sniffed type is checked against it here, and UploadHandler is handed
     * the same list — so it is the list that must not hold them.
     */
    public function testTheLegacyBinaryOfficeFormatsAreNotAdmitted(): void
    {
        foreach (['application/msword', 'application/vnd.ms-excel'] as $legacy) {
            $this->assertNotContains($legacy, $this->policy->allowedMimeTypes());
        }
        // Their successors still are.
        $this->assertContains(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $this->policy->allowedMimeTypes()
        );
        $this->assertContains('application/vnd.oasis.opendocument.spreadsheet', $this->policy->allowedMimeTypes());
    }

    /**
     * finfo reports a macro-enabled `.docm` as a plain `.docx` — it looks
     * for a `word/` entry, not for the macro project beside it. The package
     * is opened, and a VBA part refuses it (issue #1121).
     */
    public function testAWordPackageCarryingMacrosIsRefusedWhileAPlainOneIsKept(): void
    {
        $plain = new FetchedAttachment('contrat.docx', 'application/octet-stream', self::ooxml(withMacros: false));
        $macro = new FetchedAttachment('contrat.docx', 'application/octet-stream', self::ooxml(withMacros: true));

        $this->assertNull($this->policy->omissionFor($plain));
        $this->assertSame(AttachmentOmission::MIME_REJECTED, $this->policy->omissionFor($macro));
    }

    /**
     * The three ways a package could make the content-type check look the
     * other way, each refused (issue #1121, review of #1195): no content
     * types at all, content types too large to read whole, and a macro
     * part renamed but still declared.
     */
    public function testAPackageTheContentTypesCannotClearIsRefused(): void
    {
        $noTypes = self::ooxml(withMacros: false, contentTypes: null);
        $padded = self::ooxml(
            withMacros: false,
            contentTypes: '<Types>' . str_repeat('<Default Extension="x" ContentType="a/b"/>', 8000)
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.'
                . 'macroEnabled.main+xml"/></Types>'
        );
        $renamed = self::ooxml(
            withMacros: false,
            contentTypes: '<Types><Override PartName="/word/innocent.bin"'
                . ' ContentType="application/vnd.ms-office.vbaProject"/></Types>',
            extraPart: 'word/innocent.bin'
        );

        $cases = ['no content types' => $noTypes, 'padded' => $padded, 'renamed macro part' => $renamed];
        foreach ($cases as $case => $bytes) {
            $this->assertSame(
                AttachmentOmission::MIME_REJECTED,
                $this->policy->omissionFor(new FetchedAttachment('contrat.docx', 'application/octet-stream', $bytes)),
                $case
            );
        }
    }

    private static function ooxml(
        bool $withMacros,
        ?string $contentTypes = '',
        ?string $extraPart = null
    ): string {
        $path = (string) tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        if ($contentTypes !== null) {
            $zip->addFromString('[Content_Types].xml', $contentTypes !== '' ? $contentTypes : (
                '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.'
                . 'wordprocessingml.document.main+xml"/></Types>'
            ));
        }
        // The layout of a real Word file: libmagic recognises a package
        // by its entries, and reports a two-entry zip as a plain zip.
        $zip->addFromString('_rels/.rels', '<Relationships/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->addFromString('word/styles.xml', '<w:styles/>');
        if ($withMacros) {
            $zip->addFromString('word/vbaProject.bin', 'VBA');
        }
        if ($extraPart !== null) {
            $zip->addFromString($extraPart, 'VBA');
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
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
