<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\SignatureImage;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;

/**
 * A manager's signature as it is kept (#708, IT-16): a fresh PNG, bounded,
 * with nothing of the original file but its pixels.
 */
class SignatureImageTest extends TestCase
{
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function testAnImportedImageBecomesABoundedPng(): void
    {
        $png = SignatureImage::normalize(self::jpeg(3000, 1000));

        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertLessThanOrEqual(1200, $info[0]);
        $this->assertLessThanOrEqual(400, $info[1]);
    }

    /**
     * A small file declaring a huge canvas is refused before it is decoded:
     * decoding allocates width × height × 4 bytes, past `memory_limit`.
     */
    public function testAnImageDeclaringAHugeCanvasIsRefusedBeforeDecoding(): void
    {
        // A 6000 × 5000 JPEG — 30 megapixels — stays far under 5 MB.
        $this->expectException(\Modules\Rental\Service\RentalException::class);
        $this->expectExceptionMessage('25 mégapixels');

        SignatureImage::normalize(self::jpeg(6000, 5000));
    }

    /** Anything after the image data — a comment, a payload — does not survive. */
    public function testOnlyThePixelsSurvive(): void
    {
        $png = SignatureImage::normalize(self::jpeg(200, 80) . 'TRAILING-SECRET');

        $this->assertStringNotContainsString('TRAILING-SECRET', $png);
    }

    public function testADrawnSignatureArrivesAsADataUrl(): void
    {
        $image = imagecreatetruecolor(300, 100);
        ob_start();
        imagepng($image);
        $drawn = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(SignatureImage::fromDataUrl($drawn))[2] ?? null);
    }

    public function testWhatIsNotAnImageIsRefused(): void
    {
        foreach (['', '%PDF-1.4 not an image', 'data:text/html;base64,PHA+'] as $input) {
            try {
                str_starts_with($input, 'data:') ? SignatureImage::fromDataUrl($input) : SignatureImage::normalize($input);
                $this->fail('accepted: ' . $input);
            } catch (RentalException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
