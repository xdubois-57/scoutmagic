<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\UploadException;
use Core\Photo\LandscapeImageProcessor;
use PHPUnit\Framework\TestCase;

class LandscapeImageProcessorTest extends TestCase
{
    private LandscapeImageProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new LandscapeImageProcessor();
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
        $this->assertEqualsWithDelta(1200 / 400, $width / $height, 0.01);
    }

    public function testCropsASquareSourceToLandscape(): void
    {
        $processed = $this->processor->process($this->jpegBytes(800, 800), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertGreaterThan($height, $width, 'a square source must still end up landscape');
        $this->assertEqualsWithDelta(1200 / 400, $width / $height, 0.01);
    }

    public function testCropsAnUltraWideSourceToTheTargetRatio(): void
    {
        $processed = $this->processor->process($this->jpegBytes(3000, 400), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertEqualsWithDelta(1200 / 400, $width / $height, 0.01);
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
        $processed = $this->processor->process($this->jpegBytes(300, 300), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        imagedestroy($decoded);

        $this->assertSame(300, $width);
    }

    public function testOutputIsAlwaysJpeg(): void
    {
        $processed = $this->processor->process($this->jpegBytes(1200, 800), 'image/jpeg');

        $this->assertStringStartsWith("\xFF\xD8\xFF", $processed);
    }

    public function testThrowsOnUndecodableInput(): void
    {
        $this->expectException(UploadException::class);
        $this->processor->process('not an image', 'image/jpeg');
    }

    public function testConstructorAcceptsACustomRatioAndWidth(): void
    {
        $processor = new LandscapeImageProcessor(16 / 9, 800);

        $processed = $processor->process($this->jpegBytes(2000, 2000), 'image/jpeg');

        $decoded = imagecreatefromstring($processed);
        $this->assertNotFalse($decoded);
        $width = imagesx($decoded);
        $height = imagesy($decoded);
        imagedestroy($decoded);

        $this->assertSame(800, $width);
        $this->assertEqualsWithDelta(16 / 9, $width / $height, 0.01);
    }
}
