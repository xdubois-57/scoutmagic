<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Pdf;

use Core\File\PdfTextExtractor;
use Core\Pdf\DocumentPdfService;
use PHPUnit\Framework\TestCase;

/**
 * The payment QR a document carries outside its sanitised body (issue
 * #980): printed when it is a PNG data URI, and nothing else ever is.
 */
final class DocumentPdfServiceTest extends TestCase
{
    /** 1×1 transparent PNG. */
    private const PNG = 'data:image/png;base64,'
        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function render(?string $qr): string
    {
        return (new DocumentPdfService())->generate(
            'Facture complémentaire — LOC-K7Q2M4',
            '<p>Restant dû : 30,00 €, à verser au plus tard le 20/11/2027.</p>',
            'Unité Saint-Georges',
            ['Bien : Local'],
            'Exemption de TVA',
            $qr
        );
    }

    public function testAPngDataUriIsPrintedUnderTheBodyWithWhatItIsFor(): void
    {
        $pdf = $this->render(self::PNG);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(0, substr_count($pdf, '/Subtype /Image'));
        $this->assertStringContainsString(
            "Scannez ce code avec l'application de",
            (string) (new PdfTextExtractor())->extractText($pdf)
        );
    }

    public function testWithoutAQrNothingIsPrinted(): void
    {
        $pdf = $this->render(null);

        $this->assertSame(0, substr_count($pdf, '/Subtype /Image'));
        $this->assertStringNotContainsString('Scannez', (string) (new PdfTextExtractor())->extractText($pdf));
    }

    /**
     * The parameter is no way past the sanitised body: a remote address,
     * another image type and an attribute break-out are all ignored.
     */
    public function testAnythingButAPngDataUriIsIgnored(): void
    {
        foreach ([
            'https://example.com/qr.png',
            'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
            self::PNG . '" onload="alert(1)',
            '<img src="x">',
        ] as $refused) {
            $pdf = $this->render($refused);

            $this->assertSame(0, substr_count($pdf, '/Subtype /Image'), $refused);
            $this->assertStringNotContainsString(
                'Scannez',
                (string) (new PdfTextExtractor())->extractText($pdf),
                $refused
            );
        }
    }
}
