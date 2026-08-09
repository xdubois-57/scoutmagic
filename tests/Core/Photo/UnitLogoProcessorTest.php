<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\UploadException;
use Core\Photo\UnitLogoProcessor;
use PHPUnit\Framework\TestCase;

class UnitLogoProcessorTest extends TestCase
{
    private UnitLogoProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new UnitLogoProcessor();
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

    /**
     * A source with a real transparent region — needed to tell an
     * alpha-preserving resize apart from a flattened/opaque one (a fully
     * opaque source would look identical either way).
     */
    private function transparentPngBytes(int $side): string
    {
        $image = imagecreatetruecolor($side, $side);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
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

    public function testProcessReturnsAllEightPngSizesPlusIco(): void
    {
        $icons = $this->processor->process($this->solidPngBytes(600, 255, 0, 0), 'image/png', '#ffffff');

        $this->assertSame([16, 32, 48, 64, 180, 192, 512, '512-maskable', 'ico'], array_keys($icons));

        $sizes = ['16' => 16, '32' => 32, '48' => 48, '64' => 64, '180' => 180, '192' => 192, '512' => 512, '512-maskable' => 512];
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

    /**
     * Real bug, fixed: icon-180 (apple-touch-icon) used to go through the
     * same alpha-preserving resize as every other size — iOS renders any
     * transparency there as solid black on the home screen. A transparent
     * source must now flatten fully opaque, filled with the background
     * color, no margin (unlike the maskable icon's 80% safe zone).
     */
    public function testIcon180IsFlattenedFullBleedOntoTheOpaqueBackgroundColor(): void
    {
        $icons = $this->processor->process($this->transparentPngBytes(400), 'image/png', '#123456');

        $decoded = $this->decode($icons[180]);

        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 1, 1));
        $this->assertSame(0, $corner['alpha'], 'icon-180 must be fully opaque, not transparent');
        $this->assertSame(0x12, $corner['red']);
        $this->assertSame(0x34, $corner['green']);
        $this->assertSame(0x56, $corner['blue']);

        $center = imagecolorsforindex($decoded, imagecolorat($decoded, 90, 90));
        $this->assertSame(0, $center['alpha'], 'the logo area itself must also be opaque, full bleed (no safe-zone margin unlike the maskable icon)');

        imagedestroy($decoded);
    }

    /**
     * Favicons/footer logo/plain manifest icons are the opposite of
     * icon-180/maskable: they must KEEP the source's transparency, since
     * they're expected to blend with a browser tab, dark-mode home
     * screen, etc.
     */
    public function testFaviconsAndFooterLogoPreserveSourceTransparency(): void
    {
        $icons = $this->processor->process($this->transparentPngBytes(400), 'image/png', '#123456');

        foreach ([16, 32, 48, 64, 192, 512] as $size) {
            $decoded = $this->decode($icons[$size]);
            $alpha = imagecolorsforindex($decoded, imagecolorat($decoded, 1, 1))['alpha'];
            $this->assertSame(127, $alpha, "size {$size} must preserve transparency, GD alpha 127 means fully transparent");
            imagedestroy($decoded);
        }
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

    /**
     * The favicon.ico container's own binary structure — ICONDIR header
     * (reserved=0, type=1, count=3) followed by one 16-byte ICONDIRENTRY
     * per embedded size, each pointing at a byte range that is itself a
     * valid, correctly-sized PNG.
     */
    public function testIcoContainerHasAValidIcondirHeaderAndThreeEntries(): void
    {
        $icons = $this->processor->process($this->solidPngBytes(400, 10, 20, 30), 'image/png', '#ffffff');
        $ico = $icons['ico'];

        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type'], 'type 1 = icon (not 2 = cursor)');
        $this->assertSame(3, $header['count']);

        $expectedSizes = [16, 32, 48];
        foreach ($expectedSizes as $i => $expectedSize) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbitcount/Vbytes/Voffset', substr($ico, 6 + $i * 16, 16));
            $this->assertSame($expectedSize, $entry['width']);
            $this->assertSame($expectedSize, $entry['height']);
            $this->assertSame(1, $entry['planes']);
            $this->assertSame(32, $entry['bitcount']);

            $embeddedPng = substr($ico, $entry['offset'], $entry['bytes']);
            $decoded = imagecreatefromstring($embeddedPng);
            $this->assertNotFalse($decoded, "entry {$i} must be a decodable PNG");
            $this->assertSame($expectedSize, imagesx($decoded));
            imagedestroy($decoded);
        }
    }
}
