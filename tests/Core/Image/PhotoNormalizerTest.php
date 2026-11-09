<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Image;

use Core\File\UploadException;
use Core\Image\PhotoNormalizationException;
use Core\Image\PhotoNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * A photo leaves the normaliser as a JPEG with fresh pixels and nothing
 * else — or does not leave it at all. Never as it came.
 */
class PhotoNormalizerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAJpegLosesItsExifAndItsGpsPosition(): void
    {
        $source = $this->jpegWithExif(40, 30, 1);
        $this->assertNotFalse(@exif_read_data($this->stream($source)), 'premise: the source carries EXIF');
        $this->assertArrayHasKey('GPSLatitude', (array) @exif_read_data($this->stream($source)));

        $jpeg = (new PhotoNormalizer())->normalize($source);

        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        $this->assertStringNotContainsString('Exif', $jpeg);
        $this->assertStringNotContainsString('ScoutCam', $jpeg);
        $exif = @exif_read_data($this->stream($jpeg));
        $this->assertArrayNotHasKey('GPSLatitude', is_array($exif) ? $exif : []);
        $this->assertArrayNotHasKey('Orientation', is_array($exif) ? $exif : []);
    }

    /**
     * Orientation 6 is a portrait shot stored as landscape pixels: the turn
     * is baked into the pixels before the tag that asked for it goes.
     */
    public function testTheOrientationIsBakedIntoThePixels(): void
    {
        $jpeg = (new PhotoNormalizer())->normalize($this->jpegWithExif(40, 20, 6));

        [$width, $height] = (array) getimagesizefromstring($jpeg);
        $this->assertSame([20, 40], [$width, $height]);
    }

    /** A mirrored orientation (7) turns and flips, and still comes out upright-sized. */
    public function testAMirroredOrientationIsAppliedToo(): void
    {
        $jpeg = (new PhotoNormalizer())->normalize($this->jpegWithExif(40, 20, 7));

        [$width, $height] = (array) getimagesizefromstring($jpeg);
        $this->assertSame([20, 40], [$width, $height]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orientedContainers')]
    public function testPngAndWebpOrientationIsAppliedBeforeItsTagIsRemoved(string $mimeType, bool $littleEndian, bool $prefix, int $orientation): void
    {
        $source = $this->orientedContainer($mimeType, $littleEndian, $prefix, $orientation);
        $this->assertSame($mimeType, (new \finfo(FILEINFO_MIME_TYPE))->buffer($source));
        $this->assertSame([40, 20], array_slice(getimagesizefromstring($source), 0, 2));

        $jpeg = (new PhotoNormalizer())->normalize($source);

        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        $this->assertSame([20, 40], array_slice(getimagesizefromstring($jpeg), 0, 2));
        $exif = @exif_read_data($this->stream($jpeg));
        $this->assertArrayNotHasKey('Orientation', is_array($exif) ? $exif : []);
    }

    public static function orientedContainers(): iterable
    {
        yield 'PNG little endian' => ['image/png', true, false, 6];
        yield 'PNG big endian' => ['image/png', false, false, 8];
        yield 'WebP EXIF prefix' => ['image/webp', true, true, 6];
        yield 'WebP bare TIFF' => ['image/webp', false, false, 8];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableExifCapabilities')]
    public function testAnUnavailableExifReaderKeepsThePhotoWithoutItsMetadata(
        string $capability,
        string $mimeType,
        bool $littleEndian,
        bool $prefix,
        int $orientation
    ): void {
        $source = $this->orientedContainer($mimeType, $littleEndian, $prefix, $orientation);
        $script = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
if ($argv[2] === 'stream') {
    stream_wrapper_unregister('php');
}
$result = [
    'exif_available' => function_exists('exif_read_data'),
    'stream_available' => in_array('php', stream_get_wrappers(), true),
    'jpeg' => '',
    'error' => null,
];
try {
    $result['jpeg'] = base64_encode((new Core\Image\PhotoNormalizer())->normalize(base64_decode($argv[3], true)));
} catch (Throwable $exception) {
    $result['error'] = get_class($exception);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $process = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                '-d', 'disable_functions=' . ($capability === 'extension' ? 'exif_read_data' : ''),
                '-r', $script, dirname(__DIR__, 3), $capability, base64_encode($source),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the EXIF capability probe.');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $jpeg = (string) base64_decode($result['jpeg'], true);
        $dimensions = $jpeg !== '' ? getimagesizefromstring($jpeg) : false;

        $this->assertSame(
            [0, '', $capability !== 'extension', $capability !== 'stream', null, 'image/jpeg', [40, 20], true],
            [
                $exitCode, $errors, $result['exif_available'], $result['stream_available'], $result['error'],
                $jpeg !== '' ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg) : null,
                $dimensions !== false ? array_slice($dimensions, 0, 2) : null,
                !str_contains($jpeg, 'Exif'),
            ]
        );
    }

    public static function unavailableExifCapabilities(): iterable
    {
        foreach (self::orientedContainers() as $name => $arguments) {
            foreach (['extension', 'stream'] as $capability) {
                yield $name . ' without ' . $capability => [$capability, ...$arguments];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEmbeddedExif')]
    public function testUnreadableEmbeddedExifIsRefusedRatherThanLosingItsOrientation(string $case): void
    {
        $png = str_starts_with($case, 'png');
        $source = $this->orientedContainer($png ? 'image/png' : 'image/webp', true, !$png, 6);
        $offset = strpos($source, $png ? 'eXIf' : 'EXIF');
        $this->assertNotFalse($offset);
        if ($case === 'png checksum') {
            $source[$offset + 4] = 'X';
        } elseif ($case === 'png chunk length') {
            $source = substr_replace($source, pack('N', 0xFFFFFFFF), $offset - 4, 4);
        } elseif ($case === 'webp chunk length') {
            $source = substr_replace($source, pack('V', 0xFFFFFFFF), $offset + 4, 4);
        } else {
            // Keep the RIFF chunk and EXIF preamble valid, but corrupt TIFF.
            $source[$offset + 8 + 6] = 'X';
        }

        $this->expectException(PhotoNormalizationException::class);
        (new PhotoNormalizer())->normalize($source);
    }

    public static function malformedEmbeddedExif(): iterable
    {
        yield ['png checksum'];
        yield ['png chunk length'];
        yield ['webp chunk length'];
        yield ['webp TIFF'];
    }

    private function orientedContainer(string $mimeType, bool $littleEndian, bool $prefix, int $orientation): string
    {
        $tiff = $littleEndian
            ? 'II' . pack('vVv', 42, 8, 1) . pack('vvVvvV', 0x0112, 3, 1, $orientation, 0, 0)
            : 'MM' . pack('nNn', 42, 8, 1) . pack('nnNnnN', 0x0112, 3, 1, $orientation, 0, 0);
        $payload = ($prefix ? "Exif\x00\x00" : '') . $tiff;
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        $mimeType === 'image/png' ? imagepng($image) : imagewebp($image, null, 85);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);
        if ($mimeType === 'image/png') {
            $chunk = 'eXIf' . $payload;
            // eXIf after IDAT is valid; stop before the final IEND chunk.
            return substr($encoded, 0, -12) . pack('N', strlen($payload)) . $chunk
                . hash('crc32b', $chunk, true) . substr($encoded, -12);
        }

        // VP8X announces EXIF; its canvas dimensions are little-endian 24-bit.
        $extended = 'VP8X' . pack('V', 10) . "\x08\x00\x00\x00"
            . substr(pack('V', 39), 0, 3) . substr(pack('V', 19), 0, 3);
        $exif = 'EXIF' . pack('V', strlen($payload)) . $payload
            . (strlen($payload) % 2 === 1 ? "\x00" : '');
        $body = 'WEBP' . $extended . substr($encoded, 12) . 'JUNK' . pack('V', 1) . "x\x00" . $exif;
        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    public function testTheLongestSideIsBroughtDownTo2400Pixels(): void
    {
        $image = imagecreatetruecolor(4000, 3000);
        ob_start();
        imagejpeg($image, null, 70);
        $source = (string) ob_get_clean();

        $jpeg = (new PhotoNormalizer())->normalize($source);

        [$width, $height] = (array) getimagesizefromstring($jpeg);
        $this->assertSame([2400, 1800], [$width, $height]);
    }

    /**
     * A smaller copy for one use — the PDF of an inventory embeds 1 600 px
     * (#989) — goes through the same pipeline with its own bound and
     * quality, the orientation still applied and no metadata kept.
     */
    public function testACallerMayAskForASmallerBoundAndItsOwnQuality(): void
    {
        $normalizer = new PhotoNormalizer();

        $turned = $normalizer->normalize($this->jpegWithExif(2400, 1800, 6), 1600);
        [$width, $height] = (array) getimagesizefromstring($turned);
        $this->assertSame([1200, 1600], [$width, $height]);
        $this->assertStringNotContainsString('ScoutCam', $turned);

        $image = imagecreatetruecolor(400, 300);
        for ($x = 0; $x < 400; $x += 4) {
            imagefilledrectangle($image, $x, 0, $x + 3, 299, (int) imagecolorallocate($image, $x % 256, ($x * 7) % 256, 90));
        }
        ob_start();
        imagepng($image);
        $source = (string) ob_get_clean();
        $this->assertLessThan(
            strlen($normalizer->normalize($source, 1600, 95)),
            strlen($normalizer->normalize($source, 1600, 30))
        );
    }

    public function testASmallPhotoKeepsItsSize(): void
    {
        $jpeg = (new PhotoNormalizer())->normalize($this->jpegWithExif(300, 200, 1));

        [$width, $height] = (array) getimagesizefromstring($jpeg);
        $this->assertSame([300, 200], [$width, $height]);
    }

    /** A portrait photo is bounded by its height, the longer side. */
    public function testAPortraitIsBoundedByItsHeight(): void
    {
        $image = imagecreatetruecolor(1500, 3000);
        ob_start();
        imagepng($image);
        $source = (string) ob_get_clean();

        [$width, $height] = (array) getimagesizefromstring((new PhotoNormalizer())->normalize($source));
        $this->assertSame([1200, 2400], [$width, $height]);
    }

    /** A PNG with transparency comes out as a JPEG on white, not on black. */
    public function testAPngBecomesAJpegOnWhite(): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $source = (string) ob_get_clean();

        $jpeg = (new PhotoNormalizer())->normalize($source);

        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        $decoded = imagecreatefromstring($jpeg);
        $this->assertNotFalse($decoded);
        $rgb = imagecolorat($decoded, 10, 10);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
    }

    public function testAWebpBecomesAJpeg(): void
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagewebp($image);
        $source = (string) ob_get_clean();

        $this->assertSame(
            'image/jpeg',
            (new \finfo(FILEINFO_MIME_TYPE))->buffer((new PhotoNormalizer())->normalize($source))
        );
    }

    /** A file that is not an image is refused, never stored as it came. */
    public function testSomethingThatIsNotAPhotoIsRefused(): void
    {
        $this->expectException(PhotoNormalizationException::class);
        $this->expectExceptionMessage(PhotoNormalizer::NOT_A_PHOTO);

        (new PhotoNormalizer())->normalize('%PDF-1.4 not a photo at all');
    }

    /** Bytes that claim to be a JPEG and do not decode are refused too. */
    public function testAJpegThatDoesNotDecodeIsRefused(): void
    {
        $this->expectException(PhotoNormalizationException::class);
        $this->expectExceptionMessage(PhotoNormalizer::UNREADABLE);

        (new PhotoNormalizer())->normalize("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64));
    }

    /** A decompression bomb is refused before GD allocates for it. */
    public function testAnImageOverThePixelCeilingIsRefused(): void
    {
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 40000) . pack('N', 40000)
            . "\x08\x02\x00\x00\x00\x00\x00\x00\x00";

        $this->expectException(PhotoNormalizationException::class);
        $this->expectExceptionMessage('mégapixels');

        (new PhotoNormalizer())->normalize($header);
    }

    /**
     * Without a converter, an HEIC photo is refused with the sentence that
     * tells an iPhone owner what to change — never kept with its GPS.
     */
    public function testAnHeicPhotoIsRefusedWhereTheServerCannotReadIt(): void
    {
        try {
            (new PhotoNormalizer(null))->normalize($this->heicBytes());
            $this->fail('An HEIC photo must be refused without a converter.');
        } catch (PhotoNormalizationException $e) {
            $this->assertSame(PhotoNormalizer::HEIC_UNREADABLE, $e->getMessage());
            // Every upload surface catches UploadException and shows it.
            $this->assertInstanceOf(UploadException::class, $e);
        }
    }

    /**
     * With a converter, an HEIC photo goes through it and then through the
     * same GD re-encoding as everything else: bounded, stripped, JPEG.
     */
    public function testAnHeicPhotoIsConvertedWhereTheServerCanReadIt(): void
    {
        $seen = null;
        $converter = function (string $bytes) use (&$seen): string {
            $seen = $bytes;
            $image = imagecreatetruecolor(3000, 1000);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        };

        $jpeg = (new PhotoNormalizer($converter))->normalize($this->heicBytes());

        $this->assertSame($this->heicBytes(), $seen);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        [$width, $height] = (array) getimagesizefromstring($jpeg);
        $this->assertSame([2400, 800], [$width, $height]);
    }

    /** A converter that fails reads as a photo that could not be read. */
    public function testAConverterThatFailsIsAnUnreadablePhoto(): void
    {
        $normalizer = new PhotoNormalizer(static function (string $bytes): string {
            throw new \RuntimeException('libheif: unsupported codec');
        });

        try {
            $normalizer->normalize($this->heicBytes());
            $this->fail('A failed conversion must be refused.');
        } catch (PhotoNormalizationException $e) {
            $this->assertSame(PhotoNormalizer::UNREADABLE, $e->getMessage());
            $this->assertStringNotContainsString('libheif', $e->getMessage());
        }
    }

    /**
     * The server's own normaliser reads HEIC exactly when its Imagick
     * lists the format — and when it does, Imagick's half of the work turns
     * and strips like GD's does.
     */
    public function testTheServersNormaliserReadsHeicOnlyWhenImagickDoes(): void
    {
        $imagickReadsHeic = class_exists(\Imagick::class) && \Imagick::queryFormats('HEIC') !== [];

        $this->assertSame($imagickReadsHeic, PhotoNormalizer::serverReadsHeic());
        $this->assertSame($imagickReadsHeic, PhotoNormalizer::forThisServer()->readsHeic());

        if (class_exists(\Imagick::class)) {
            $jpeg = PhotoNormalizer::imagickToJpeg($this->jpegWithExif(40, 20, 6));
            [$width, $height] = (array) getimagesizefromstring($jpeg);
            $this->assertSame([20, 40], [$width, $height]);
            $this->assertStringNotContainsString('ScoutCam', $jpeg);
        }
    }

    public function testAnUploadIsNormalisedIntoATemporaryJpeg(): void
    {
        $path = $this->temporary($this->jpegWithExif(40, 30, 1));
        $normalizer = new PhotoNormalizer();

        $entry = $normalizer->normalizeUpload([
            'name' => 'IMG_0042.HEIC',
            'type' => 'image/heic',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ], 1024 * 1024);
        $this->temporaryFiles[] = $entry['tmp_name'];

        $this->assertSame('IMG_0042.jpg', $entry['name']);
        $this->assertSame('image/jpeg', $entry['type']);
        $this->assertNotSame($path, $entry['tmp_name']);
        $this->assertSame((int) filesize($entry['tmp_name']), $entry['size']);
        $this->assertStringNotContainsString('Exif', (string) file_get_contents($entry['tmp_name']));

        PhotoNormalizer::discard($entry);
        $this->assertFileDoesNotExist($entry['tmp_name']);
    }

    public function testAnUploadOverTheSizeLimitIsRefusedBeforeItIsDecoded(): void
    {
        $path = $this->temporary($this->jpegWithExif(40, 30, 1));

        $this->expectException(PhotoNormalizationException::class);
        $this->expectExceptionMessage('taille maximale');

        (new PhotoNormalizer())->normalizeUpload(
            ['name' => 'a.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 2048],
            1024
        );
    }

    public function testAFailedUploadIsRefused(): void
    {
        $this->expectException(PhotoNormalizationException::class);

        (new PhotoNormalizer())->normalizeUpload(
            ['name' => 'a.jpg', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0],
            1024
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /**
     * A JPEG with a hand-packed EXIF APP1 segment: the Orientation tag, a
     * camera make, and a GPS sub-IFD holding a latitude — what a phone
     * writes into a photo taken inside a rented hall. GD has no API to
     * write EXIF, so the TIFF structure is built here (little-endian).
     */
    private function jpegWithExif(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // IFD0 at 8: three entries (Make, Orientation, GPS pointer).
        $ifd0Size = 2 + 3 * 12 + 4;
        $makeOffset = 8 + $ifd0Size;
        $make = "ScoutCam\x00";
        $gpsOffset = $makeOffset + strlen($make) + (strlen($make) % 2);
        $gpsSize = 2 + 2 * 12 + 4;
        $latitudeOffset = $gpsOffset + $gpsSize;

        $tiff = 'II' . pack('v', 0x002A) . pack('V', 8)
            . pack('v', 3)
            . pack('vvVV', 0x010F, 2, strlen($make), $makeOffset)
            . pack('vvV', 0x0112, 3, 1) . pack('vv', $orientation, 0)
            . pack('vvVV', 0x8825, 4, 1, $gpsOffset)
            . pack('V', 0)
            . $make . str_repeat("\x00", strlen($make) % 2)
            . pack('v', 2)
            . pack('vvV', 0x0001, 2, 2) . "N\x00\x00\x00"
            . pack('vvVV', 0x0002, 5, 3, $latitudeOffset)
            . pack('V', 0)
            . pack('VVVVVV', 50, 1, 50, 1, 0, 1);

        $payload = "Exif\x00\x00" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    /** The first bytes of an HEIC file: enough for finfo to name it. */
    private function heicBytes(): string
    {
        return pack('N', 24) . 'ftypheic' . pack('N', 0) . 'mif1heic' . str_repeat("\x00", 64);
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');
        $this->assertNotFalse($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function temporary(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'normalizer-test-');
        file_put_contents($path, $bytes);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
