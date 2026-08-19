<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Repository\LinkPreviewCacheRepository;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\LinkPreviewService;
use Modules\Gallery\Service\OgScraperService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * @group database
 */
#[Group('database')]
class LinkPreviewServiceTest extends TestCase
{
    private LinkPreviewCacheRepository $cache;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($pdo);
        $this->cache = new LinkPreviewCacheRepository($pdo);
    }

    public function testFetchReturnsNullWhenTheScraperFailsOutright(): void
    {
        $scraper = $this->createMock(OgScraperService::class);
        $scraper->expects($this->once())->method('fetch')->willThrowException(new GalleryException('unreachable'));

        $service = new LinkPreviewService($scraper, $this->cache);

        $this->assertNull($service->fetch('https://example.com/dead-link'));
    }

    public function testFetchReturnsTitleDescriptionAndImageBytesOnSuccess(): void
    {
        $scraper = $this->createMock(OgScraperService::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(['title' => 'Hello', 'description' => 'World', 'image' => 'https://example.com/img.jpg']);
        $scraper->expects($this->once())->method('fetchImageBytes')
            ->with('https://example.com/img.jpg')->willReturn('raw-bytes');

        $service = new LinkPreviewService($scraper, $this->cache);
        $preview = $service->fetch('https://example.com/page');

        $this->assertSame('Hello', $preview->title);
        $this->assertSame('World', $preview->description);
        $this->assertSame('raw-bytes', $preview->imageBytes);
    }

    public function testFetchDegradesToNoImageWhenTheImageDownloadFails(): void
    {
        $scraper = $this->createMock(OgScraperService::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(['title' => 'Hello', 'description' => null, 'image' => 'https://example.com/img.jpg']);
        $scraper->expects($this->once())->method('fetchImageBytes')->willReturn(null);

        $service = new LinkPreviewService($scraper, $this->cache);
        $preview = $service->fetch('https://example.com/page');

        $this->assertSame('Hello', $preview->title);
        $this->assertNull($preview->imageBytes);
    }

    public function testASecondFetchOfTheSameUrlSkipsTheHtmlScrapeButStillDownloadsTheImage(): void
    {
        $scraper = $this->createMock(OgScraperService::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(['title' => 'Hello', 'description' => 'World', 'image' => 'https://example.com/img.jpg']);
        $scraper->expects($this->exactly(2))->method('fetchImageBytes')
            ->with('https://example.com/img.jpg')->willReturn('raw-bytes');

        $service = new LinkPreviewService($scraper, $this->cache);

        $first = $service->fetch('https://example.com/page');
        $second = $service->fetch('https://example.com/page');

        $this->assertSame('Hello', $first->title);
        $this->assertSame('Hello', $second->title);
    }

    public function testAFailedFetchIsNeverCachedSoTheNextAttemptRetries(): void
    {
        $scraper = $this->createMock(OgScraperService::class);
        $scraper->expects($this->exactly(2))->method('fetch')
            ->willThrowException(new GalleryException('unreachable'));

        $service = new LinkPreviewService($scraper, $this->cache);

        $this->assertNull($service->fetch('https://example.com/flaky'));
        $this->assertNull($service->fetch('https://example.com/flaky'));
    }
}
