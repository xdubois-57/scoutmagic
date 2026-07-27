<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\ImageProcessingService;
use PHPUnit\Framework\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    private ImageProcessingService $service;

    protected function setUp(): void
    {
        $this->service = new ImageProcessingService();
    }

    private function fakeJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        imagedestroy($image);
        return (string) ob_get_clean();
    }

    public function testProcessReturnsThreeSizesAndDimensions(): void
    {
        $result = $this->service->process($this->fakeJpeg(2000, 1000), 'image/jpeg', 3000);

        $this->assertSame(2000, $result['width']);
        $this->assertSame(1000, $result['height']);
        $this->assertStringStartsWith("\xFF\xD8", $result['thumb']);
        $this->assertStringStartsWith("\xFF\xD8", $result['medium']);
        $this->assertStringStartsWith("\xFF\xD8", $result['large']);
    }

    public function testThumbIsNoWiderThanTargetWidth(): void
    {
        $result = $this->service->process($this->fakeJpeg(2000, 1000), 'image/jpeg', 3000);

        $thumb = imagecreatefromstring($result['thumb']);
        $this->assertLessThanOrEqual(300, imagesx($thumb));
    }

    public function testLargeIsCappedAtMaxDimension(): void
    {
        $result = $this->service->process($this->fakeJpeg(4000, 2000), 'image/jpeg', 1000);

        $large = imagecreatefromstring($result['large']);
        $this->assertSame(1000, imagesx($large));
        $this->assertSame(500, imagesy($large));
    }

    public function testMediumEqualsLargeWhenSourceIsSmallerThanMediumWidth(): void
    {
        $result = $this->service->process($this->fakeJpeg(800, 600), 'image/jpeg', 3000);

        $this->assertSame($result['medium'], $result['large']);
    }

    public function testSmallSourceIsNeverUpscaled(): void
    {
        $result = $this->service->process($this->fakeJpeg(100, 80), 'image/jpeg', 3000);

        $large = imagecreatefromstring($result['large']);
        $this->assertSame(100, imagesx($large));
        $this->assertSame(80, imagesy($large));
    }

    public function testProcessThrowsOnUndecodableContent(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->process('not an image', 'image/jpeg', 3000);
    }
}
