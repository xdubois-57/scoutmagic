<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * How many images a PDF paints, for the tests that count a sheet's labels
 * by the QR code each one carries (issue #1146).
 *
 * **An image OBJECT is not an image on the page.** A picture with
 * transparency is written as two objects: the colour image, and a
 * greyscale soft mask it points to with `/SMask N 0 R`. On a GD that
 * writes alpha, a sheet of two labels holds four `/Subtype /Image` objects,
 * and counting them says four. The masks are a valid representation —
 * the generator is not at fault and is not changed to please a counter — so
 * the count leaves out every image object that another one names as its
 * mask.
 *
 * Reads the document as bytes, like the assertions it replaces: the image
 * dictionaries are written uncompressed, unlike the content streams. An
 * object stored inside a compressed object stream would not be seen, which
 * is the limit of the raw reading and was already the limit of the raw
 * count.
 */
final class PdfImages
{
    public static function count(string $pdf): int
    {
        $masks = [];
        if (preg_match_all('#/SMask\s+(\d+)\s+\d+\s+R#', $pdf, $found) > 0) {
            $masks = array_flip($found[1]);
        }

        $count = 0;
        if (preg_match_all('#(?<![\d.])(\d+)\s+\d+\s+obj\b(.*?)\bendobj#s', $pdf, $objects, PREG_SET_ORDER) > 0) {
            foreach ($objects as [, $id, $body]) {
                if (preg_match('#/Subtype\s*/Image#', $body) === 1 && !isset($masks[$id])) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
