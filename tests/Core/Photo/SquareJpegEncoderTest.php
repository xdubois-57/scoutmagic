<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Photo\SquareJpegEncoder;
use PHPUnit\Framework\TestCase;

/**
 * The one implementation of "small square JPEG of a portrait", shared by
 * the trombinoscope PDF and the contact card's vCard.
 */
class SquareJpegEncoderTest extends TestCase
{
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function testARectangleComesBackAsASquareJpegOfTheAskedSide(): void
    {
        $encoded = SquareJpegEncoder::encode($this->png(400, 200), 64, 75);

        $this->assertNotNull($encoded);
        $info = getimagesizefromstring($encoded);
        $this->assertNotFalse($info);
        $this->assertSame([64, 64], [$info[0], $info[1]]);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    /**
     * A stale file row, a truncated upload, a file that is not an image at
     * all: null, never an exception. A portrait must never fail a whole
     * document or a whole contact card.
     */
    public function testBytesThatAreNotAnImageComeBackAsNull(): void
    {
        $this->assertNull(SquareJpegEncoder::encode('not an image at all', 64, 75));
        $this->assertNull(SquareJpegEncoder::encode('', 64, 75));
    }
}
