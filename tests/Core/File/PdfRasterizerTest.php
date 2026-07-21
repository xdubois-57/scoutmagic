<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\PdfRasterizer;
use PHPUnit\Framework\TestCase;

class PdfRasterizerTest extends TestCase
{
    private PdfRasterizer $rasterizer;

    protected function setUp(): void
    {
        // The PHP extension can be present without a working PDF
        // delegate (Ghostscript) behind it — queryFormats() is the only
        // reliable way to tell, and a CI runner may have one but not
        // the other.
        if (!class_exists(\Imagick::class) || !in_array('PDF', \Imagick::queryFormats('PDF'), true)) {
            $this->markTestSkipped('imagick with PDF (Ghostscript) support not available.');
        }
        $this->rasterizer = new PdfRasterizer();
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/fixtures/pdf/' . $name);
        \assert($content !== false);
        return $content;
    }

    public function testRendersFirstPageAsAValidJpeg(): void
    {
        $jpeg = $this->rasterizer->firstPageToJpeg($this->fixture('text_receipt.pdf'));

        $this->assertNotNull($jpeg);
        // JPEG files start with the SOI marker 0xFFD8.
        $this->assertSame("\xFF\xD8", substr($jpeg, 0, 2));
    }

    public function testReturnsNullForMalformedContent(): void
    {
        $this->assertNull($this->rasterizer->firstPageToJpeg('not a pdf at all'));
    }

    public function testReturnsNullForEmptyContent(): void
    {
        $this->assertNull($this->rasterizer->firstPageToJpeg(''));
    }

    public function testNeverLeavesATemporaryFileBehind(): void
    {
        $before = count(glob(sys_get_temp_dir() . '/pdf_rasterize_*') ?: []);

        $this->rasterizer->firstPageToJpeg($this->fixture('text_receipt.pdf'));
        $this->rasterizer->firstPageToJpeg('not a pdf at all');

        $after = count(glob(sys_get_temp_dir() . '/pdf_rasterize_*') ?: []);
        $this->assertSame($before, $after);
    }
}
