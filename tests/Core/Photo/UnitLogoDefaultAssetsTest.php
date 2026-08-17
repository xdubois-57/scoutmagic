<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use PHPUnit\Framework\TestCase;

/**
 * The actual shipped defaults under public/assets/img/pwa/ (scripts/
 * generate-pwa-defaults.php) — Core\Photo\UnitLogoService falls back to
 * these whenever no superadmin has uploaded a custom logo, so a bug in
 * these files is a bug for every unit that never uploads one. Pins down
 * the real pre-existing bug fixed alongside the processor: the shipped
 * icon-180.png default was itself alpha-preserving, meaning even a unit
 * that never touched the logo feature got a solid black apple-touch-icon
 * on iOS.
 */
class UnitLogoDefaultAssetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__, 3) . '/public/assets/img/pwa';
    }

    /**
     * @dataProvider expectedFilesProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expectedFilesProvider')]
    public function testExpectedDefaultFileExistsAtTheRightSize(string $filename, int $expectedSize): void
    {
        $path = $this->dir . '/' . $filename;
        $this->assertFileExists($path);

        $decoded = imagecreatefromstring((string) file_get_contents($path));
        $this->assertNotFalse($decoded, "{$filename} must be a decodable PNG");
        $this->assertSame($expectedSize, imagesx($decoded));
        $this->assertSame($expectedSize, imagesy($decoded));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function expectedFilesProvider(): array
    {
        return [
            'favicon 16' => ['favicon-16.png', 16],
            'favicon 32' => ['favicon-32.png', 32],
            'favicon 48' => ['favicon-48.png', 48],
            'footer logo 64' => ['logo-64.png', 64],
            'apple touch icon 180' => ['icon-180.png', 180],
            'manifest icon 192' => ['icon-192.png', 192],
            'manifest icon 512' => ['icon-512.png', 512],
        ];
    }

    public function testIcon180DefaultIsOpaqueNotAlphaPreserving(): void
    {
        // Real bug, fixed: this shipped file used to have an alpha
        // channel — iOS renders any transparency in an apple-touch-icon
        // as solid black on the home screen, for every unit that never
        // uploads a custom logo, not just a hypothetical uploaded one.
        // PNG's own IHDR chunk color type is the reliable way to check
        // this (byte 25: 6 = truecolor+alpha, 2 = truecolor, no alpha) —
        // more reliable than round-tripping through GD's in-memory
        // decode, which doesn't cleanly expose "did the source file have
        // an alpha channel" after the fact.
        $bytes = (string) file_get_contents($this->dir . '/icon-180.png');
        $colorType = ord($bytes[25]);
        $this->assertSame(2, $colorType, 'icon-180.png default must be PNG color type 2 (truecolor, no alpha), not 6 (truecolor+alpha)');
    }

    public function testMaskableDefaultIsOpaque(): void
    {
        $decoded = imagecreatefromstring((string) file_get_contents($this->dir . '/icon-512-maskable.png'));
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $this->assertSame(0, $corner['alpha'], 'GD alpha 0 means fully opaque');
    }

    public function testFaviconIcoDefaultIsAValidThreeSizeIcoContainer(): void
    {
        $ico = (string) file_get_contents($this->dir . '/favicon.ico');
        $this->assertNotEmpty($ico);

        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type']);
        $this->assertSame(3, $header['count']);
    }
}
