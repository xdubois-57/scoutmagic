<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\UploadException;
use Core\Photo\SectionPhotoProcessor;
use PHPUnit\Framework\TestCase;

class SectionPhotoProcessorTest extends TestCase
{
    private SectionPhotoProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new SectionPhotoProcessor();
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

    public function testCropsAPortraitSourceToLandscape(): void
    {
        $processed = $this->processor->process($this->jpegBytes(400, 1000), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertGreaterThan($height, $width, 'output must be landscape (wider than tall)');
        $this->assertEqualsWithDelta(4 / 3, $width / $height, 0.01);
    }

    public function testCropsAnUltraWideSourceToLandscape(): void
    {
        $processed = $this->processor->process($this->jpegBytes(2000, 400), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertEqualsWithDelta(4 / 3, $width / $height, 0.01);
    }

    public function testKeepsAnAlready4By3SourceAtItsOwnRatio(): void
    {
        $processed = $this->processor->process($this->jpegBytes(800, 600), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertEqualsWithDelta(4 / 3, $width / $height, 0.01);
    }

    public function testCapsTheOutputWidthEvenForAHugeSource(): void
    {
        $processed = $this->processor->process($this->jpegBytes(4000, 3000), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        imagedestroy($decoded);

        $this->assertLessThanOrEqual(1600, $width);
    }

    public function testNeverUpscalesASmallSourceBeyondItsCroppedSize(): void
    {
        $processed = $this->processor->process($this->jpegBytes(200, 150), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        imagedestroy($decoded);

        $this->assertSame(200, $width);
    }

    public function testOutputIsAlwaysJpeg(): void
    {
        $processed = $this->processor->process($this->jpegBytes(800, 600), 'image/jpeg');

        $this->assertStringStartsWith("\xFF\xD8\xFF", $processed);
    }

    public function testThrowsOnUndecodableInput(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process('not an image', 'image/jpeg');
    }
}
