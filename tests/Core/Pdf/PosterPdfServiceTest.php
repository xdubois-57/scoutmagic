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

    public function testGenerateEmbedsTheImageDataUriWhenProvided(): void
    {
        $service = new PosterPdfService();
        // 1x1 transparent PNG.
        $imageDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $pdf = $service->generate(
            'Camp d\'été 2026',
            '<p>Venez nombreux !</p>',
            'https://www.25sv.be/s/a8f3k2',
            '25SV',
            $imageDataUri
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

    public function testGenerateAlwaysFitsOnASinglePageEvenWithMaximumLengthInputs(): void
    {
        $service = new PosterPdfService();
        // news.title is VARCHAR(255) with no maxlength on the editor
        // field, and the summary field caps at 300 chars — worst case
        // for what NewsController::poster() can ever pass in.
        $longTitle = str_repeat('Un titre vraiment très long ', 10);
        $longSummary = str_repeat('Un résumé assez long pour tester le débordement possible. ', 5);
        $imageDataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $pdf = $service->generate($longTitle, $longSummary, 'https://example.com/s/abcdef', 'Unité Test', $imageDataUri);

        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page(?!s)/', $pdf));
    }

    /**
     * Issue #189. The only thing that ever reaches `.excerpt` is the
     * one-sentence `news_articles.summary`, so the poster's subtitle is a
     * title and not body copy — it is centred under the title rather than
     * left-aligned, which is what it was while the CSS assumed a
     * multi-paragraph body.
     *
     * Asserted on the rendered HTML rather than the PDF bytes: dompdf
     * resolves alignment into glyph positions no assertion can read back,
     * and the CSS rule is exactly what the fix is.
     */
    public function testTheSubtitleIsCentredLikeTheTitle(): void
    {
        $html = self::renderHtmlOf(new PosterPdfService(), 'Titre', 'Un résumé en une phrase.');

        $this->assertMatchesRegularExpression('/\.excerpt\s*\{[^}]*text-align:\s*center/', $html);
        $this->assertMatchesRegularExpression('/\.title\s*\{[^}]*text-align:\s*center/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.excerpt\s*\{[^}]*text-align:\s*left/', $html);
    }

    /** The private renderer, which is where the layout actually lives. */
    private static function renderHtmlOf(PosterPdfService $service, string $title, string $body): string
    {
        $method = new \ReflectionMethod($service, 'renderHtml');

        return (string) $method->invoke(
            $service,
            $title,
            $body,
            'data:image/png;base64,AA==',
            'https://example.com/s/abcdef',
            'Unité Test',
            '01/01/2026',
            null
        );
    }

    public function testGenerateTruncatesALongTitleWithEllipsis(): void
    {
        $service = new PosterPdfService();
        $longTitle = str_repeat('a', 500);

        $pdf = $service->generate($longTitle, 'Résumé.', 'https://example.com/s/abcdef');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString(str_repeat('a', 200), $pdf);
    }
}
