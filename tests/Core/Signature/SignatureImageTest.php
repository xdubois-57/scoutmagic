<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature;

use Core\Signature\SignatureException;
use Core\Signature\SignatureImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A signature as the site keeps it (issue #926): a fresh PNG, bounded,
 * with nothing of the original file but its pixels.
 */
final class SignatureImageTest extends TestCase
{
    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function webp(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }

    public function testAnImportedImageBecomesABoundedPng(): void
    {
        $png = SignatureImage::fromBytes(self::jpeg(3000, 1000))->bytes();

        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertSame(SignatureImage::MAX_WIDTH, $info[0]);
        $this->assertLessThanOrEqual(SignatureImage::MAX_HEIGHT, $info[1]);
    }

    public function testASmallImageKeepsItsSize(): void
    {
        $info = getimagesizefromstring(SignatureImage::fromBytes(self::png(300, 100))->bytes());

        $this->assertNotFalse($info);
        $this->assertSame([300, 100], [$info[0], $info[1]]);
    }

    public function testWebpIsAccepted(): void
    {
        $this->assertSame(
            IMAGETYPE_PNG,
            getimagesizefromstring(SignatureImage::fromBytes(self::webp(200, 80))->bytes())[2] ?? null
        );
    }

    /** A drawn signature stays transparent, so it sits on the page rather than on a white box. */
    public function testTransparencyIsKept(): void
    {
        $image = imagecreatetruecolor(20, 10);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $this->assertNotFalse($transparent);
        imagefill($image, 0, 0, $transparent);
        ob_start();
        imagepng($image);
        $drawn = (string) ob_get_clean();

        $kept = imagecreatefromstring(SignatureImage::fromBytes($drawn)->bytes());
        $this->assertNotFalse($kept);
        $this->assertSame(127, (imagecolorat($kept, 5, 5) >> 24) & 0x7F);
    }

    /**
     * A small file declaring a huge canvas is refused before it is decoded:
     * decoding allocates width × height × 4 bytes, past `memory_limit`.
     */
    public function testAnImageDeclaringAHugeCanvasIsRefusedBeforeDecoding(): void
    {
        // A 6000 × 5000 JPEG — 30 megapixels — stays far under 5 MB.
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('25 mégapixels');

        SignatureImage::fromBytes(self::jpeg(6000, 5000));
    }

    /** Anything after the image data — a comment, a payload — does not survive. */
    public function testOnlyThePixelsSurvive(): void
    {
        $png = SignatureImage::fromBytes(self::jpeg(200, 80) . 'TRAILING-SECRET')->bytes();

        $this->assertStringNotContainsString('TRAILING-SECRET', $png);
    }

    public function testADrawnSignatureArrivesAsADataUrl(): void
    {
        $drawn = 'data:image/png;base64,' . base64_encode(self::png(300, 100));

        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(SignatureImage::fromDataUrl($drawn)->bytes())[2] ?? null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notADrawingProvider(): array
    {
        return [
            'empty' => [''],
            'another type' => ['data:text/html;base64,PHA+'],
            'a JPEG data URL' => ['data:image/jpeg;base64,' . base64_encode('x')],
            'not base64' => ['data:image/png;base64,***'],
            'base64 of no image' => ['data:image/png;base64,' . base64_encode('%PDF-1.4')],
        ];
    }

    #[DataProvider('notADrawingProvider')]
    public function testWhatIsNotADrawingIsRefused(string $input): void
    {
        $this->expectException(SignatureException::class);

        SignatureImage::fromDataUrl($input);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notAnImageProvider(): array
    {
        return [
            'empty' => ['', '5 Mo au plus'],
            'a PDF' => ['%PDF-1.4 not an image', 'PNG, JPEG ou WebP'],
            'a GIF' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'PNG, JPEG ou WebP'],
            'over five megabytes' => [str_repeat('a', SignatureImage::MAX_INPUT_BYTES + 1), '5 Mo au plus'],
        ];
    }

    #[DataProvider('notAnImageProvider')]
    public function testWhatIsNotAnAcceptedImageIsRefusedWithASentenceForTheVisitor(string $bytes, string $said): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage($said);

        SignatureImage::fromBytes($bytes);
    }

    public function testAnUploadedFileIsReadFromDisk(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, self::jpeg(400, 100));

        try {
            $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring(SignatureImage::fromUploadedFile($path)->bytes())[2] ?? null);
        } finally {
            unlink($path);
        }
    }

    /** The size is judged on disk, before a byte of the file is read into memory. */
    public function testAnUploadedFileTooHeavyIsRefusedFromItsSize(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sig');
        $handle = fopen($path, 'w');
        $this->assertNotFalse($handle);
        ftruncate($handle, SignatureImage::MAX_INPUT_BYTES + 1);
        fclose($handle);

        try {
            $this->expectException(SignatureException::class);
            $this->expectExceptionMessage('5 Mo au plus');
            SignatureImage::fromUploadedFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testAMissingUploadIsRefused(): void
    {
        $this->expectException(SignatureException::class);

        SignatureImage::fromUploadedFile(sys_get_temp_dir() . '/no-such-signature-' . bin2hex(random_bytes(4)));
    }
}
