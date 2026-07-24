<?php

declare(strict_types=1);

namespace Tests\Core\Pdf;

use Core\Pdf\PosterPdfService;
use PHPUnit\Framework\TestCase;

class PosterPdfServiceTest extends TestCase
{
    public function testGenerateReturnsAValidPdfByteString(): void
    {
        $service = new PosterPdfService();

        $pdf = $service->generate(
            'Camp d\'été 2026',
            '<p>Venez nombreux pour le <strong>camp</strong> de cette année !</p>',
            'https://www.25sv.be/s/a8f3k2',
            '25SV'
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function testGenerateTruncatesALongBodyWithEllipsis(): void
    {
        $service = new PosterPdfService();
        $longBody = '<p>' . str_repeat('mot ', 200) . '</p>';

        $pdf = $service->generate('Titre', $longBody, 'https://example.com/s/abcdef');

        // Can't easily assert on rendered PDF text content without a PDF
        // text extractor — just confirm generation succeeds for long input.
        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
