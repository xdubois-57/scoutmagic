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

        if ($result !== null) {
            $this->assertStringStartsWith('%PDF-', $result);
            $this->assertLessThan(strlen($content), strlen($result));
        } else {
            $this->assertTrue(true);
        }
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
