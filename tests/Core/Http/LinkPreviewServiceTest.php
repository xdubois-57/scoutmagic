<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\LinkPreviewCacheRepository;
use Core\Http\LinkPreviewService;
use Core\Http\UrlMetadata;
use Core\Http\UrlMetadataFetcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[Group('database')]
class LinkPreviewServiceTest extends TestCase
{
    private LinkPreviewCacheRepository $cache;

    protected function setUp(): void
    {
        $this->cache = new LinkPreviewCacheRepository(DatabaseTestHelper::createTestDatabase());
    }

    public function testFetchReturnsNullWhenTheScraperFailsOutright(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->once())->method('fetch')->willReturn(null);

        $service = new LinkPreviewService($scraper, $this->cache);

        $this->assertNull($service->fetch('https://example.com/dead-link'));
    }

    public function testFetchReturnsTitleDescriptionAndImageBytesOnSuccess(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(new UrlMetadata('Hello', 'Page', 'World', 'https://example.com/img.jpg'));
        $scraper->expects($this->once())->method('fetchImageBytes')
            ->with('https://example.com/img.jpg')->willReturn('raw-bytes');

        $service = new LinkPreviewService($scraper, $this->cache);
        $preview = $service->fetch('https://example.com/page');

        $this->assertSame('Hello', $preview?->title);
        $this->assertSame('World', $preview?->description);
        $this->assertSame('raw-bytes', $preview?->imageBytes);
    }

    public function testFetchDegradesToNoImageWhenTheImageDownloadFails(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(new UrlMetadata('Hello', null, null, 'https://example.com/img.jpg'));
        $scraper->expects($this->once())->method('fetchImageBytes')->willReturn(null);

        $service = new LinkPreviewService($scraper, $this->cache);
        $preview = $service->fetch('https://example.com/page');

        $this->assertSame('Hello', $preview?->title);
        $this->assertNull($preview?->imageBytes);
    }

    public function testASecondFetchOfTheSameUrlSkipsTheHtmlScrapeButStillDownloadsTheImage(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(new UrlMetadata('Hello', null, 'World', 'https://example.com/img.jpg'));
        $scraper->expects($this->exactly(2))->method('fetchImageBytes')
            ->with('https://example.com/img.jpg')->willReturn('raw-bytes');

        $service = new LinkPreviewService($scraper, $this->cache);

        $first = $service->fetch('https://example.com/page');
        $second = $service->fetch('https://example.com/page');

        $this->assertSame('Hello', $first?->title);
        $this->assertSame('Hello', $second?->title);
    }

    public function testAFailedFetchIsNeverCachedSoTheNextAttemptRetries(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->exactly(2))->method('fetch')->willReturn(null);

        $service = new LinkPreviewService($scraper, $this->cache);

        $this->assertNull($service->fetch('https://example.com/flaky'));
        $this->assertNull($service->fetch('https://example.com/flaky'));
    }

    /**
     * The link dialog only wants a title (#840): metadata() must never
     * make the server download an image nobody is going to store.
     */
    public function testMetadataNeverDownloadsTheImage(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(new UrlMetadata('Hello', 'Page', null, 'https://example.com/img.jpg'));
        $scraper->expects($this->never())->method('fetchImageBytes');

        $metadata = (new LinkPreviewService($scraper, $this->cache))->metadata('https://example.com/page');

        $this->assertSame('Hello', $metadata?->ogTitle);
        $this->assertSame('Page', $metadata?->htmlTitle);
    }

    public function testMetadataIsServedFromTheCacheTheSecondTime(): void
    {
        $scraper = $this->createMock(UrlMetadataFetcher::class);
        $scraper->expects($this->once())->method('fetch')
            ->willReturn(new UrlMetadata(null, 'Agenda', null, null));

        $service = new LinkPreviewService($scraper, $this->cache);
        $service->metadata('https://example.com/agenda');

        $this->assertSame('Agenda', $service->metadata('https://example.com/agenda')?->htmlTitle);
    }
}
