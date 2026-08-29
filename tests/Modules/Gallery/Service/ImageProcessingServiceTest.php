<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Api\GalleryException;
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

    public function testProcessRejectsADecompressionBombBeforeDecoding(): void
    {
        // A tiny PNG header declaring 40000×40000 (~1.6 billion pixels) would
        // OOM the background photo task if decoded (audit M7). The dimension
        // guard must reject it up front as a GalleryException, never allocate.
        $bomb = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR'
            . pack('N', 40000) . pack('N', 40000)
            . "\x08\x02\x00\x00\x00\x00\x00\x00\x00";

        // The message must be the dimension guard's (not the generic
        // "impossible de décoder"), proving the bomb was rejected up front on
        // its declared size rather than after a failed/partial decode.
        $this->expectException(GalleryException::class);
        $this->expectExceptionMessageMatches('/trop grande.*pixels/');
        $this->service->process($bomb, 'image/png', 3000);
    }

    /**
     * GalleryException is a Core\Exception\UserFacingException, so what it
     * is constructed with is what the page shows. The guard's own sentence
     * survives only because Core\Image\ImageDimensionException claims to be
     * user-facing too — and the cause is chained either way, so the trace
     * still names the guard.
     */
    public function testTheDimensionGuardsCauseIsChainedOntoTheGalleryException(): void
    {
        $bomb = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR'
            . pack('N', 40000) . pack('N', 40000)
            . "\x08\x02\x00\x00\x00\x00\x00\x00\x00";

        try {
            $this->service->process($bomb, 'image/png', 3000);
            $this->fail('Expected a GalleryException.');
        } catch (GalleryException $e) {
            $this->assertInstanceOf(\Core\Image\ImageDimensionException::class, $e->getPrevious());
        }
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

    public function testProcessRefusesAMimeTypeOutsideTheAllowedPhotoTypes(): void
    {
        $this->expectException(GalleryException::class);
        $this->service->process($this->fakeJpeg(100, 100), 'image/gif', 3000);
    }

    /**
     * The medium target used to be compared against the source's WIDTH, so a
     * tall narrow photo (a phone screenshot, a portrait shot) was considered
     * "already medium-sized" at any height and the grid was served the
     * full-size image as its medium rendition.
     */
    public function testMediumIsDownscaledForATallNarrowPortrait(): void
    {
        $result = $this->service->process($this->fakeJpeg(900, 2400), 'image/jpeg', 3000);

        $medium = imagecreatefromstring($result['medium']);
        $large = imagecreatefromstring($result['large']);

        $this->assertNotSame($result['medium'], $result['large']);
        $this->assertSame(1200, imagesy($medium));
        $this->assertSame(450, imagesx($medium));
        $this->assertSame(2400, imagesy($large));
    }

    /**
     * With gallery_photo_max_dimension below the medium long side, "medium"
     * came out BIGGER than "large" — so the lightbox's "Voir en haute
     * qualité" button actually downgraded the image.
     */
    public function testMediumNeverExceedsLargeWhenMaxDimensionIsBelowMediumSize(): void
    {
        $result = $this->service->process($this->fakeJpeg(4000, 2000), 'image/jpeg', 1000);

        $medium = imagecreatefromstring($result['medium']);
        $large = imagecreatefromstring($result['large']);

        $this->assertLessThanOrEqual(
            max(imagesx($large), imagesy($large)),
            max(imagesx($medium), imagesy($medium))
        );
        $this->assertSame(1000, imagesx($large));
        $this->assertSame($result['medium'], $result['large']);
    }

    /**
     * gallery_photo_max_dimension is a free-form number setting: a 0 (or a
     * blank saved value) asked GD for a 0x0 canvas, failing every photo.
     * Controller\GalleryConfigController now refuses to store that, and this
     * covers the service being robust to it on its own.
     *
     * @dataProvider nonPositiveMaxDimensions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonPositiveMaxDimensions')]
    public function testNonPositiveMaxDimensionFallsBackToTheSourceSize(int $maxDimension): void
    {
        $result = $this->service->process($this->fakeJpeg(2000, 1000), 'image/jpeg', $maxDimension);

        $large = imagecreatefromstring($result['large']);

        $this->assertSame(2000, imagesx($large));
        $this->assertSame(1000, imagesy($large));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function nonPositiveMaxDimensions(): array
    {
        return ['zero' => [0], 'negative' => [-500]];
    }

    public function testThumbIsCappedOnTheLongSideForAPortraitSource(): void
    {
        $result = $this->service->process($this->fakeJpeg(1000, 3000), 'image/jpeg', 3000);

        $thumb = imagecreatefromstring($result['thumb']);

        $this->assertSame(300, imagesy($thumb));
        $this->assertSame(100, imagesx($thumb));
    }

    public function testTinySourceStillProducesAValidThumb(): void
    {
        $result = $this->service->process($this->fakeJpeg(1, 1), 'image/jpeg', 3000);

        $thumb = imagecreatefromstring($result['thumb']);

        $this->assertSame(1, imagesx($thumb));
        $this->assertSame(1, imagesy($thumb));
    }
}
