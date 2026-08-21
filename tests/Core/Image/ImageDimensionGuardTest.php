<?php

declare(strict_types=1);

namespace Tests\Core\Image;

use Core\Image\ImageDimensionException;
use Core\Image\ImageDimensionGuard;
use PHPUnit\Framework\TestCase;

class ImageDimensionGuardTest extends TestCase
{
    /**
     * A valid PNG header declaring huge dimensions but carrying no pixel data
     * — a few dozen bytes on disk that would ask GD for gigabytes if decoded.
     * getimagesize reads only the header, so this is exactly what the guard
     * must catch without ever decoding.
     */
    private function pngHeader(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR'
            . pack('N', $width) . pack('N', $height)
            . "\x08\x02\x00\x00\x00\x00\x00\x00\x00";
    }

    private function smallRealPng(): string
    {
        $img = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    public function testAcceptsAGenuineSmallImageFromString(): void
    {
        ImageDimensionGuard::assertWithinCeilingFromString($this->smallRealPng());
        $this->addToAssertionCount(1);
    }

    public function testRejectsADecompressionBombFromString(): void
    {
        $this->expectException(ImageDimensionException::class);
        // 40000×40000 = 1.6 billion pixels, far past the 50-megapixel ceiling.
        ImageDimensionGuard::assertWithinCeilingFromString($this->pngHeader(40000, 40000));
    }

    public function testAcceptsAnImageExactlyAtTheCeiling(): void
    {
        // 7071² ≈ 49.999 MP — just under 50,000,000.
        ImageDimensionGuard::assertWithinCeilingFromString($this->pngHeader(7071, 7071));
        $this->addToAssertionCount(1);
    }

    public function testRejectsAnImageJustOverTheCeiling(): void
    {
        $this->expectException(ImageDimensionException::class);
        // 7072² ≈ 50.01 MP — just over.
        ImageDimensionGuard::assertWithinCeilingFromString($this->pngHeader(7072, 7072));
    }

    public function testAcceptsAndRejectsFromAPathToo(): void
    {
        $bomb = tempnam(sys_get_temp_dir(), 'dimguard_bomb_');
        $ok = tempnam(sys_get_temp_dir(), 'dimguard_ok_');
        try {
            file_put_contents($bomb, $this->pngHeader(40000, 40000));
            file_put_contents($ok, $this->smallRealPng());

            ImageDimensionGuard::assertWithinCeilingFromPath($ok);
            $this->addToAssertionCount(1);

            $this->expectException(ImageDimensionException::class);
            ImageDimensionGuard::assertWithinCeilingFromPath($bomb);
        } finally {
            @unlink($bomb);
            @unlink($ok);
        }
    }

    public function testIgnoresNonImageContentSoTheDecoderReportsItsOwnError(): void
    {
        // A header that isn't a decodable image is not this guard's concern —
        // the caller's own decode/null-check handles it.
        ImageDimensionGuard::assertWithinCeilingFromString('this is not an image');
        $this->addToAssertionCount(1);
    }
}
