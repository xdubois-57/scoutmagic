<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Modules\Attestations\Service\AttestationPdfSplitter;
use Modules\Attestations\Service\AttestationsException;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * The cut itself, against the same committed fixture the reader replays.
 *
 * What is asserted is the property that matters: an extracted certificate
 * holds ITS pages and nobody else's. Handing a family a document carrying
 * another family's name is the failure this whole module exists to prevent,
 * so the test reads the output back rather than trusting the page count.
 */
class AttestationPdfSplitterTest extends TestCase
{
    private AttestationPdfSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new AttestationPdfSplitter();
    }

    private function textOf(string $pdfBytes): string
    {
        return (new Parser())->parseContent($pdfBytes)->getText();
    }

    public function testAnExtractedCertificateHoldsOnlyItsOwnPages(): void
    {
        $bytes = $this->splitter->extract(AttestationsTestHelper::goldenFixturePath(), 3, 4);

        $this->assertSame(2, count((new Parser())->parseContent($bytes)->getPages()));

        $text = $this->textOf($bytes);
        $this->assertStringContainsString('MEUNIER', $text);
        // The neighbours on either side, which a one-page slip would bring.
        $this->assertStringNotContainsString('VANDENBRANDE', $text);
        $this->assertStringNotContainsString('ROSKAM', $text);
    }

    public function testEveryCertificateOfTheBatchComesOutSeparately(): void
    {
        $expected = ['VANDENBRANDE', 'MEUNIER', 'ROSKAM', 'HERREMANS', 'DELACROIX'];

        foreach ($expected as $index => $surname) {
            $first = $index * 2 + 1;
            $bytes = $this->splitter->extract(AttestationsTestHelper::goldenFixturePath(), $first, $first + 1);
            $text = $this->textOf($bytes);

            $this->assertStringContainsString($surname, $text, "Pages {$first}–" . ($first + 1));

            foreach ($expected as $otherIndex => $other) {
                if ($otherIndex !== $index) {
                    $this->assertStringNotContainsString($other, $text, "Pages {$first}–" . ($first + 1));
                }
            }
        }
    }

    public function testASinglePageCanBeExtracted(): void
    {
        $bytes = $this->splitter->extract(AttestationsTestHelper::goldenFixturePath(), 1, 1);

        $this->assertSame(1, count((new Parser())->parseContent($bytes)->getPages()));
        $this->assertStringContainsString('VANDENBRANDE', $this->textOf($bytes));
    }

    public function testARangeBeyondTheFileIsRefused(): void
    {
        $this->expectException(AttestationsException::class);
        $this->splitter->extract(AttestationsTestHelper::goldenFixturePath(), 9, 12);
    }

    public function testAnInvertedRangeIsRefused(): void
    {
        $this->expectException(AttestationsException::class);
        $this->splitter->extract(AttestationsTestHelper::goldenFixturePath(), 6, 3);
    }

    public function testAPageZeroIsRefused(): void
    {
        // No PDF has a page 0, so a 0 here is the signature of a cast that
        // salvaged something (Core\Service\IntegerInput's own reasoning).
        $this->expectException(AttestationsException::class);
        $this->splitter->extract(AttestationsTestHelper::goldenFixturePath(), 0, 2);
    }

    /**
     * FPDI and FPDF both speak English and name internals. Neither message
     * may reach a chef d'unité (AGENTS.md § Exception messages that reach a
     * visitor); the detail rides on $previous.
     */
    public function testAFileThatCannotBeImportedIsRefusedInFrench(): void
    {
        $path = sys_get_temp_dir() . '/attestations_broken_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, 'pas un PDF du tout');

        try {
            $this->splitter->extract($path, 1, 1);
            $this->fail('A file that cannot be imported must be refused.');
        } catch (AttestationsException $e) {
            $this->assertStringContainsString('fédération', $e->getMessage());
            $this->assertStringNotContainsString('FPDI', $e->getMessage());
            $this->assertStringNotContainsString('Unable', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        } finally {
            @unlink($path);
        }
    }
}
