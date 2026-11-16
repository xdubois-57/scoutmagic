<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\UploadException;
use Core\Photo\ImageVariantProcessor;
use PHPUnit\Framework\TestCase;

class ImageVariantProcessorTest extends TestCase
{
    private ImageVariantProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new ImageVariantProcessor();
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function pngBytesWithAlpha(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function decodeWebp(string $bytes): \GdImage
    {
        $decoded = imagecreatefromstring($bytes);
        $this->assertNotFalse($decoded);
        return $decoded;
    }

    // --- thumb ---

    public function testThumbIsAlways192x192(): void
    {
        $processed = $this->processor->process('thumb', $this->jpegBytes(800, 600), 'image/jpeg');

        $decoded = $this->decodeWebp($processed);
        $this->assertSame(192, imagesx($decoded));
        $this->assertSame(192, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function testThumbCentreCropsAPortraitSourceToSquare(): void
    {
        $processed = $this->processor->process('thumb', $this->jpegBytes(400, 1000), 'image/jpeg');

        $decoded = $this->decodeWebp($processed);
        $this->assertSame(192, imagesx($decoded));
        $this->assertSame(192, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function testThumbUpscalesASmallSourceToTheFixedSize(): void
    {
        $processed = $this->processor->process('thumb', $this->jpegBytes(50, 50), 'image/jpeg');

        $decoded = $this->decodeWebp($processed);
        $this->assertSame(192, imagesx($decoded));
        $this->assertSame(192, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function testThumbOutputIsWebp(): void
    {
        $processed = $this->processor->process('thumb', $this->jpegBytes(300, 300), 'image/jpeg');

        $this->assertStringStartsWith('RIFF', $processed);
    }

    // --- md ---

    public function testMdCapsTheLongestEdgeAt1024(): void
    {
        $processed = $this->processor->process('md', $this->jpegBytes(4000, 3000), 'image/jpeg');

        $decoded = $this->decodeWebp($processed);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertSame(1024, $width);
        $this->assertEqualsWithDelta(4000 / 3000, $width / $height, 0.01);
    }

    public function testMdCapsTheLongestEdgeWhenPortrait(): void
    {
        $processed = $this->processor->process('md', $this->jpegBytes(1200, 3000), 'image/jpeg');

        $decoded = $this->decodeWebp($processed);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertSame(1024, $height);
        $this->assertEqualsWithDelta(1200 / 3000, $width / $height, 0.01);
    }

    public function testMdNeverUpscalesASourceAlreadyUnder1024(): void
    {
        $processed = $this->processor->process('md', $this->jpegBytes(400, 300), 'image/jpeg');

        $decoded = $this->decodeWebp($processed);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertSame(400, $width);
        $this->assertSame(300, $height);
    }

    public function testMdOutputIsWebp(): void
    {
        $processed = $this->processor->process('md', $this->jpegBytes(300, 300), 'image/jpeg');

        $this->assertStringStartsWith('RIFF', $processed);
    }

    public function testMdPreservesAlphaFromAPngSource(): void
    {
        $processed = $this->processor->process('md', $this->pngBytesWithAlpha(300, 300), 'image/png');

        $decoded = $this->decodeWebp($processed);
        $this->assertTrue(imageistruecolor($decoded));
        $alphaAtCorner = imagecolorat($decoded, 0, 0);
        $alphaChannel = ($alphaAtCorner >> 24) & 0x7F;
        imagedestroy($decoded);

        // 127 is GD's own "fully transparent" alpha value (0-127 scale).
        $this->assertSame(127, $alphaChannel);
    }

    // --- validation ---

    public function testThrowsOnUnknownVariant(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process('large', $this->jpegBytes(100, 100), 'image/jpeg');
    }

    public function testThrowsOnUndecodableInput(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process('thumb', 'not an image', 'image/jpeg');
    }

    /**
     * A decompression bomb — a PNG announcing 40 000 × 40 000 pixels — is
     * refused before GD allocates for it, as an upload error carrying the
     * guard's own French sentence.
     */
    public function testAnImageOverThePixelCeilingIsRefusedBeforeItIsDecoded(): void
    {
        $bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 40000, 40000) . "\x08\x02\x00\x00\x00"
            . str_repeat("\x00", 64);

        try {
            $this->processor->process('thumb', $bomb, 'image/png');
            $this->fail('A decompression bomb must be refused.');
        } catch (UploadException $e) {
            $this->assertSame(
                'Image trop grande (40000×40000 pixels) : la limite est de 50 mégapixels.',
                $e->getMessage()
            );
        }
    }
}
