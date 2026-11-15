<?php

declare(strict_types=1);

namespace Tests\Core\Pdf;

use PHPUnit\Framework\TestCase;
use Tests\Support\PdfImages;

/**
 * Counting the images a PDF paints, not the image objects it contains
 * (issue #1146).
 *
 * A PDF carries a QR code's transparency as a SECOND image object, the
 * soft mask, which the colour image points to with `/SMask N 0 R`. On a
 * GD that writes alpha, two labels are four `/Subtype /Image` objects, and
 * a raw count reads 4 where the sheet shows 2. These synthetic documents
 * are modelled on the object dump of the failing run: 18 (DeviceGray) is the
 * mask of 19 (DeviceRGB), 22 the mask of 23.
 */
final class PdfImagesTest extends TestCase
{
    /**
     * @param array{id: int, mask?: int} ...$images
     */
    private static function pdf(array ...$images): string
    {
        $out = "%PDF-1.7\n";
        foreach ($images as $image) {
            $space = isset($image['mask']) ? '/DeviceRGB /SMask ' . $image['mask'] . ' 0 R' : '/DeviceGray';
            $out .= $image['id'] . " 0 obj\n<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace "
                . $space . " /BitsPerComponent 8 /Length 1 >>\nstream\n\x00\nendstream\nendobj\n";
        }

        return $out . "%%EOF\n";
    }

    public function testAnImageWithoutAMaskIsOneImage(): void
    {
        $this->assertSame(2, PdfImages::count(self::pdf(['id' => 10], ['id' => 11])));
    }

    public function testAMaskIsNotAnImageOfItsOwn(): void
    {
        // 18 is the mask of 19, 22 the mask of 23: two labels, four objects.
        $pdf = self::pdf(['id' => 18], ['id' => 19, 'mask' => 18], ['id' => 22], ['id' => 23, 'mask' => 22]);

        $this->assertSame(4, preg_match_all('#/Subtype\s*/Image#', $pdf), 'a raw count reads the masks too: four');
        $this->assertSame(2, PdfImages::count($pdf));
    }

    public function testTheOrderOfAMaskAndItsImageDoesNotMatter(): void
    {
        $pdf = self::pdf(['id' => 19, 'mask' => 18], ['id' => 18], ['id' => 23, 'mask' => 22], ['id' => 22]);

        $this->assertSame(2, PdfImages::count($pdf));
    }

    /**
     * The count must still see a real extra label — a sheet that prints
     * three where two are owed is the failure this assertion is for.
     */
    public function testARealExtraImageIsStillCountedAmongMasks(): void
    {
        $pdf = self::pdf(['id' => 18], ['id' => 19, 'mask' => 18], ['id' => 22], ['id' => 23, 'mask' => 22], ['id' => 30]);

        $this->assertSame(3, PdfImages::count($pdf));
    }

    public function testAnExtraMaskedLabelIsCountedToo(): void
    {
        $pdf = self::pdf(
            ['id' => 18],
            ['id' => 19, 'mask' => 18],
            ['id' => 22],
            ['id' => 23, 'mask' => 22],
            ['id' => 26],
            ['id' => 27, 'mask' => 26]
        );

        $this->assertSame(3, PdfImages::count($pdf));
    }

    public function testADocumentWithoutImagesHasNone(): void
    {
        $this->assertSame(0, PdfImages::count("%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"));
    }
}
