<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\PdfTextExtractor;
use PHPUnit\Framework\TestCase;

class PdfTextExtractorTest extends TestCase
{
    private PdfTextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PdfTextExtractor();
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/fixtures/pdf/' . $name);
        \assert($content !== false);
        return $content;
    }

    public function testExtractsTextFromAPdfWithATextLayer(): void
    {
        $text = $this->extractor->extractText($this->fixture('text_receipt.pdf'));

        $this->assertNotNull($text);
        $this->assertStringContainsString('Delhaize', $text);
        $this->assertStringContainsString('42.50', $text);
    }

    public function testReturnsNullForMalformedContent(): void
    {
        $this->assertNull($this->extractor->extractText('not a pdf at all'));
    }

    public function testReturnsNullForEmptyContent(): void
    {
        $this->assertNull($this->extractor->extractText(''));
    }

    public function testReturnsNullWhenTextIsBelowTheMeaningfulThreshold(): void
    {
        // A structurally valid PDF whose only content is whitespace —
        // parses fine, but has nothing worth sending to the LLM.
        $pdf = "%PDF-1.4\n"
            . "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n"
            . "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n"
            . "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>endobj\n"
            . "trailer<< /Size 4 /Root 1 0 R >>\n%%EOF";

        $this->assertNull($this->extractor->extractText($pdf));
    }
}
