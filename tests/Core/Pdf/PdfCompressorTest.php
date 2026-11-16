<?php

declare(strict_types=1);

namespace Tests\Core\Pdf;

use Core\Pdf\PdfCompressor;
use PHPUnit\Framework\TestCase;

class PdfCompressorTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . '/pdf_compressor_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDirectory)) {
            foreach (scandir($this->tempDirectory) as $item) {
                if ($item !== '.' && $item !== '..') {
                    @unlink($this->tempDirectory . '/' . $item);
                }
            }
            @rmdir($this->tempDirectory);
        }
    }

    public function testCompressReturnsNullWhenBackendIsNone(): void
    {
        $compressor = new PdfCompressor($this->tempDirectory);

        $result = $compressor->compress('%PDF-1.4 anything', PdfCompressor::BACKEND_NONE, PdfCompressor::QUALITY_BALANCED);

        $this->assertNull($result);
    }

    /**
     * Definition of done: "the compressor's 'output is bigger → keep
     * original' path" — a fake backend that succeeds but produces a
     * larger valid PDF must still be rejected.
     */
    public function testCompressReturnsNullWhenTheOutputIsNotSmallerThanTheInput(): void
    {
        $compressor = $this->fakeCompressor(fn(string $out) => file_put_contents($out, '%PDF-1.4' . str_repeat('X', 1000)) !== false);

        $result = $compressor->compress('%PDF-1.4 small', PdfCompressor::BACKEND_GHOSTSCRIPT, PdfCompressor::QUALITY_BALANCED);

        $this->assertNull($result);
    }

    public function testCompressReturnsTheOutputWhenSmallerAndAValidPdf(): void
    {
        $compressor = $this->fakeCompressor(fn(string $out) => file_put_contents($out, '%PDF-1.4x') !== false);

        $result = $compressor->compress('%PDF-1.4 ' . str_repeat('padding ', 100), PdfCompressor::BACKEND_GHOSTSCRIPT, PdfCompressor::QUALITY_BALANCED);

        $this->assertSame('%PDF-1.4x', $result);
    }

    public function testCompressReturnsNullWhenTheOutputIsNotAValidPdf(): void
    {
        $compressor = $this->fakeCompressor(fn(string $out) => file_put_contents($out, 'not a pdf') !== false);

        $result = $compressor->compress('%PDF-1.4 ' . str_repeat('padding ', 100), PdfCompressor::BACKEND_GHOSTSCRIPT, PdfCompressor::QUALITY_BALANCED);

        $this->assertNull($result);
    }

    public function testCompressReturnsNullWhenTheBackendProducesNoOutputAtAll(): void
    {
        $compressor = $this->fakeCompressor(fn(string $out) => false);

        $result = $compressor->compress('%PDF-1.4 ' . str_repeat('padding ', 100), PdfCompressor::BACKEND_GHOSTSCRIPT, PdfCompressor::QUALITY_BALANCED);

        $this->assertNull($result);
    }

    public function testCompressCleansUpTempFiles(): void
    {
        $compressor = $this->fakeCompressor(fn(string $out) => file_put_contents($out, '%PDF-1.4x') !== false);

        $compressor->compress('%PDF-1.4 ' . str_repeat('padding ', 100), PdfCompressor::BACKEND_GHOSTSCRIPT, PdfCompressor::QUALITY_BALANCED);

        $remaining = array_diff(scandir($this->tempDirectory) ?: [], ['.', '..']);
        $this->assertSame([], array_values($remaining));
    }

    /**
     * Definition of done: "the 'no backend' path" — compress() with
     * BACKEND_NONE (what detectBackend() returns on a server with no
     * Ghostscript/qpdf/pdftocairo on PATH, or with proc_open disabled)
     * must short-circuit before touching the filesystem at all, never
     * throw.
     */
    public function testCompressWithNoBackendNeverTouchesTheFilesystem(): void
    {
        $compressor = new PdfCompressor($this->tempDirectory);

        $this->assertNull($compressor->compress('%PDF-1.4', PdfCompressor::BACKEND_NONE, PdfCompressor::QUALITY_BALANCED));
        $this->assertDirectoryDoesNotExist($this->tempDirectory);
    }

    public function testDetectBackendFindsGhostscriptWhenAvailableOnThisMachine(): void
    {
        exec('which gs 2>/dev/null', $out, $exit);
        if ($exit !== 0) {
            $this->markTestSkipped('Ghostscript not available in this environment.');
        }

        $compressor = new PdfCompressor($this->tempDirectory);
        $this->assertSame(PdfCompressor::BACKEND_GHOSTSCRIPT, $compressor->detectBackend());
    }

    /**
     * A PDF written with compressed cross-references — what a phone's
     * scanner produces, and what FPDI's free parser refuses — comes back
     * as a classic PDF 1.4 FPDI reads (#708, IT-16). Skipped without
     * Ghostscript, which is the only thing that can rewrite it.
     */
    public function testRewriteForImportProducesAPdfFpdiReads(): void
    {
        exec('which gs 2>/dev/null', $out, $exit);
        if ($exit !== 0) {
            $this->markTestSkipped('Ghostscript not available in this environment.');
        }

        // Ghostscript itself, asked for PDF 1.7 with object streams, is
        // the simplest way to get a file the free parser refuses.
        $source = $this->tempDirectory . '/source.pdf';
        $modern = $this->tempDirectory . '/modern.pdf';
        @mkdir($this->tempDirectory, 0755, true);
        $pdf = new \FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(20, 20, 'Contrat signe');
        file_put_contents($source, $pdf->Output('S'));
        exec(sprintf(
            'gs -dSAFER -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 -dWriteObjStms=true -dWriteXRefStm=true -sOutputFile=%s %s',
            escapeshellarg($modern),
            escapeshellarg($source)
        ));
        $bytes = (string) file_get_contents($modern);
        @unlink($source);
        @unlink($modern);

        $rewritten = (new PdfCompressor($this->tempDirectory))->rewriteForImport($bytes);

        $this->assertIsString($rewritten);
        $this->assertStringStartsWith('%PDF-1.4', $rewritten);
        $path = $this->tempDirectory . '/rewritten.pdf';
        file_put_contents($path, $rewritten);
        $this->assertSame(1, (new \setasign\Fpdi\Fpdi())->setSourceFile($path));
        @unlink($path);
    }

    private function requireGhostscript(): void
    {
        exec('which gs 2>/dev/null', $out, $exit);
        if ($exit !== 0) {
            $this->markTestSkipped('Ghostscript not available in this environment.');
        }
    }

    /**
     * What a file that starts with `%PDF-` but cannot be read does to
     * Ghostscript: it reports « Couldn't initialise file » and « No pages
     * will be processed » on its output, EXITS 0, and writes a valid PDF of
     * one blank page. That page was then countersigned as the renter's
     * contract. Anything that did not actually carry a document over must
     * come back as null — « could not be read », never a blank page.
     *
     * @return array<string, array{string}>
     */
    public static function unreadableFilesThatLookLikePdfs(): array
    {
        return [
            'header and prose' => ["%PDF-1.7\nthis is not really a pdf, no xref, nothing\n"],
            'header only' => ["%PDF-1.4\n"],
            'header and binary noise' => ["%PDF-1.7\n" . str_repeat("\x00\xff\x13\x37", 256)],
            'header and a cut-off object' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableFilesThatLookLikePdfs')]
    public function testRewriteForImportAnswersNullForAnUnreadableFileThatStartsLikeAPdf(string $content): void
    {
        $this->requireGhostscript();

        $this->assertNull(
            (new PdfCompressor($this->tempDirectory))->rewriteForImport($content),
            'an unreadable file must be refused, not turned into a blank page'
        );
    }

    public function testRewriteForImportKeepsEveryPageOfARealDocument(): void
    {
        $this->requireGhostscript();

        // The other direction: refusing the unreadable must not refuse a
        // real multi-page document written the way a phone scanner does.
        @mkdir($this->tempDirectory, 0755, true);
        $source = $this->tempDirectory . '/source.pdf';
        $modern = $this->tempDirectory . '/modern.pdf';
        $pdf = new \FPDF();
        foreach (['Page un', 'Page deux', 'Page trois'] as $text) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Text(20, 20, $text);
        }
        file_put_contents($source, $pdf->Output('S'));
        exec(sprintf(
            'gs -dSAFER -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.7 '
                . '-dWriteObjStms=true -dWriteXRefStm=true -sOutputFile=%s %s',
            escapeshellarg($modern),
            escapeshellarg($source)
        ));
        $bytes = (string) file_get_contents($modern);
        @unlink($source);
        @unlink($modern);

        $rewritten = (new PdfCompressor($this->tempDirectory))->rewriteForImport($bytes);

        $this->assertIsString($rewritten);
        $path = $this->tempDirectory . '/rewritten.pdf';
        file_put_contents($path, $rewritten);
        $this->assertSame(3, (new \setasign\Fpdi\Fpdi())->setSourceFile($path));
        @unlink($path);
    }

    public function testRewriteForImportAnswersNullForSomethingThatIsNotAPdf(): void
    {
        exec('which gs 2>/dev/null', $out, $exit);
        if ($exit !== 0) {
            $this->markTestSkipped('Ghostscript not available in this environment.');
        }

        $this->assertNull((new PdfCompressor($this->tempDirectory))->rewriteForImport('not a pdf at all'));
    }

    /**
     * Real Ghostscript round-trip — skipped if unavailable. Doesn't
     * assert a specific size outcome (Ghostscript can legitimately grow
     * a trivial input, per the class's own contract), only that the
     * result always satisfies "null, or a smaller valid PDF" — the
     * contract compress() promises regardless of backend.
     */
    public function testCompressWithRealGhostscriptNeverReturnsSomethingBiggerOrInvalid(): void
    {
        exec('which gs 2>/dev/null', $out, $exit);
        if ($exit !== 0) {
            $this->markTestSkipped('Ghostscript not available in this environment.');
        }

        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        $compressor = new PdfCompressor($this->tempDirectory);
        $result = $compressor->compress($content, PdfCompressor::BACKEND_GHOSTSCRIPT, PdfCompressor::QUALITY_BALANCED);

        $this->assertTrue(
            $result === null
                || (str_starts_with($result, '%PDF-') && strlen($result) < strlen($content)),
            'compress() must answer null or a smaller, valid PDF; it answered '
                . ($result === null ? 'null' : strlen($result) . ' bytes starting with ' . substr($result, 0, 8))
        );
        // Whichever of the two it was, the scratch files are gone
        // (SECURITY.md §5: temporary files are removed on success and on
        // failure alike).
        $this->assertSame([], array_values(array_diff(
            (array) scandir($this->tempDirectory),
            ['.', '..']
        )));
    }

    private function fakeCompressor(\Closure $writeOutput): PdfCompressor
    {
        return new class ($this->tempDirectory, $writeOutput) extends PdfCompressor {
            public function __construct(string $tempDirectory, private \Closure $writeOutput)
            {
                parent::__construct($tempDirectory);
            }

            protected function executeBackend(string $backend, string $inputPath, string $outputPath, string $quality): bool
            {
                return ($this->writeOutput)($outputPath);
            }
        };
    }
}
