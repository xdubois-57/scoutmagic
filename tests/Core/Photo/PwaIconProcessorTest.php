<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\UploadException;
use Core\Photo\PwaIconProcessor;
use PHPUnit\Framework\TestCase;

class PwaIconProcessorTest extends TestCase
{
    private PwaIconProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new PwaIconProcessor();
    }

    /**
     * A solid-color square source — resampling a uniform region introduces
     * no blending, so every derivative's pixels stay exactly this color,
     * which is what makes the maskable safe-zone assertions below exact
     * rather than approximate.
     */
    private function solidPngBytes(int $side, int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor($side, $side);
        $color = imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function decode(string $pngBytes): \GdImage
    {
        $decoded = imagecreatefromstring($pngBytes);
        $this->assertNotFalse($decoded);
        return $decoded;
    }

    public function testProcessReturnsAllFourExpectedSizes(): void
    {
        $icons = $this->processor->process($this->solidPngBytes(600, 255, 0, 0), 'image/png', '#ffffff');

        $this->assertSame([192, 512, '512-maskable', 180], array_keys($icons));

        $sizes = ['192' => 192, '512' => 512, '512-maskable' => 512, '180' => 180];
        foreach ($sizes as $key => $expected) {
            $decoded = $this->decode($icons[$key]);
            $this->assertSame($expected, imagesx($decoded), "width mismatch for {$key}");
            $this->assertSame($expected, imagesy($decoded), "height mismatch for {$key}");
            imagedestroy($decoded);
        }
    }

    public function testNonSquareSourceIsCroppedToSquareBeforeResizing(): void
    {
        $image = imagecreatetruecolor(800, 300);
        $color = imagecolorallocate($image, 0, 128, 255);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $icons = $this->processor->process($bytes, 'image/png', '#ffffff');

        $decoded = $this->decode($icons[512]);
        $this->assertSame(512, imagesx($decoded));
        $this->assertSame(512, imagesy($decoded));
        imagedestroy($decoded);
    }

    /**
     * W3C maskable-icon safe zone: the logo is scaled to 80% of the canvas
     * and centered, so a corner pixel must be the solid background color
     * (never the logo color, never transparent) while the very center must
     * be the logo color.
     */
    public function testMaskableIconPadsLogoToEightyPercentSafeZoneOnSolidBackground(): void
    {
        $icons = $this->processor->process($this->solidPngBytes(400, 255, 0, 0), 'image/png', '#0d6efd');

        $decoded = $this->decode($icons['512-maskable']);

        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 2, 2));
        $this->assertSame(13, $corner['red']);
        $this->assertSame(110, $corner['green']);
        $this->assertSame(253, $corner['blue']);

        $center = imagecolorsforindex($decoded, imagecolorat($decoded, 256, 256));
        $this->assertSame(255, $center['red']);
        $this->assertSame(0, $center['green']);
        $this->assertSame(0, $center['blue']);

        imagedestroy($decoded);
    }

    public function testMaskableBackgroundIsAlwaysOpaqueNeverTransparent(): void
    {
        $icons = $this->processor->process($this->solidPngBytes(400, 255, 0, 0), 'image/png', '#0d6efd');

        $decoded = $this->decode($icons['512-maskable']);
        $alpha = imagecolorsforindex($decoded, imagecolorat($decoded, 2, 2))['alpha'];
        $this->assertSame(0, $alpha, 'GD alpha 0 means fully opaque');
        imagedestroy($decoded);
    }

    public function testMalformedBackgroundColorDefaultsToWhite(): void
    {
        $icons = $this->processor->process($this->solidPngBytes(400, 255, 0, 0), 'image/png', 'not-a-color');

        $decoded = $this->decode($icons['512-maskable']);
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 2, 2));
        $this->assertSame(255, $corner['red']);
        $this->assertSame(255, $corner['green']);
        $this->assertSame(255, $corner['blue']);
        imagedestroy($decoded);
    }

    public function testThrowsOnUndecodableSource(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process('not an image', 'image/png', '#ffffff');
    }

    public function testThrowsOnUnsupportedMimeType(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process($this->solidPngBytes(100, 0, 0, 0), 'image/svg+xml', '#ffffff');
    }
}
