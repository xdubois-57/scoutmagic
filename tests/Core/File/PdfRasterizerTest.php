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

    /**
     * A page whose background is transparent — a scan or photo exported
     * with a mask — comes out on white, its content intact (#1342). It
     * came out black: converted to JPEG before the flattening, whose
     * result was thrown away. A valid JPEG signature proved none of it.
     */
    public function testATransparentBackgroundComesOutWhiteWithItsContent(): void
    {
        $image = new \Imagick();
        $image->newImage(200, 200, new \ImagickPixel('transparent'), 'png');
        $bar = new \ImagickDraw();
        $bar->setFillColor('black');
        $bar->rectangle(20, 90, 180, 110);
        $image->drawImage($bar);
        $image->setImageFormat('pdf');

        $page = $this->decoded($this->rasterizer->firstPageToJpeg($image->getImagesBlob()));

        $this->assertGreaterThan(240, $this->grey($page, 0.05, 0.05), 'the background is white');
        $this->assertLessThan(60, $this->grey($page, 0.5, 0.5), 'the content is still there');
    }

    /**
     * The FIRST page, whole: a two-page document whose second page is all
     * black renders its first, light one, at the page's own proportions.
     */
    public function testRendersTheWholeFirstPageAndNotTheNext(): void
    {
        $page = $this->decoded($this->rasterizer->firstPageToJpeg(self::twoPagePdf()));

        $this->assertGreaterThan(240, $this->grey($page, 0.05, 0.05));
        $this->assertGreaterThan(240, $this->grey($page, 0.95, 0.95));
        // A 200 × 200 pt page at 150 dpi, uncropped.
        $this->assertEqualsWithDelta(417, $page->getImageWidth(), 2);
        $this->assertEqualsWithDelta(417, $page->getImageHeight(), 2);
    }

    private function decoded(?string $jpeg): \Imagick
    {
        $this->assertNotNull($jpeg);
        $this->assertSame("\xFF\xD8", substr($jpeg, 0, 2));
        $image = new \Imagick();
        $image->readImageBlob($jpeg);

        return $image;
    }

    /** The grey level (0–255) at a point given as fractions of the width and height. */
    private function grey(\Imagick $image, float $x, float $y): int
    {
        $colour = $image->getImagePixelColor(
            (int) ($x * ($image->getImageWidth() - 1)),
            (int) ($y * ($image->getImageHeight() - 1))
        )->getColor();

        return (int) round(($colour['r'] + $colour['g'] + $colour['b']) / 3);
    }

    /** Page 1: a line of text on no background. Page 2: entirely black. */
    private static function twoPagePdf(): string
    {
        $pages = ['BT /F1 24 Tf 20 100 Td (PAGE UN) Tj ET', '0 0 0 rg 0 0 200 200 re f'];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
            7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        foreach ($pages as $i => $content) {
            $id = 3 + 2 * $i;
            $objects[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] '
                . '/Resources << /Font << /F1 7 0 R >> >> /Contents ' . ($id + 1) . ' 0 R >>';
            $objects[$id + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
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
