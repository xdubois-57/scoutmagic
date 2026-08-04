<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\UploadException;
use Core\Photo\StaffThumbnailProcessor;
use PHPUnit\Framework\TestCase;

class StaffThumbnailProcessorTest extends TestCase
{
    private StaffThumbnailProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new StaffThumbnailProcessor();
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

    private function decodeWebp(string $bytes): \GdImage
    {
        $decoded = imagecreatefromstring($bytes);
        $this->assertNotFalse($decoded);
        return $decoded;
    }

    public function testOutputIsAlways160By160RegardlessOfSourceAspectRatio(): void
    {
        foreach ([[400, 1000], [2000, 400], [800, 600], [200, 150]] as [$width, $height]) {
            $processed = $this->processor->process($this->jpegBytes($width, $height), 'image/jpeg');
            $decoded = $this->decodeWebp($processed);

            $this->assertSame(160, imagesx($decoded));
            $this->assertSame(160, imagesy($decoded));
            imagedestroy($decoded);
        }
    }

    public function testUpscalesASourceSmallerThan160(): void
    {
        $processed = $this->processor->process($this->jpegBytes(80, 80), 'image/jpeg');
        $decoded = $this->decodeWebp($processed);

        $this->assertSame(160, imagesx($decoded));
        $this->assertSame(160, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function testOutputIsAlwaysWebp(): void
    {
        $processed = $this->processor->process($this->jpegBytes(800, 600), 'image/jpeg');

        $this->assertStringStartsWith('RIFF', $processed);
        $this->assertStringContainsString('WEBP', substr($processed, 0, 16));
    }

    public function testThrowsOnUndecodableInput(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process('not an image', 'image/jpeg');
    }

    public function testRejectsUnsupportedMimeType(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process($this->jpegBytes(200, 200), 'application/pdf');
    }
}
