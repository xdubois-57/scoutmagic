<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Modules\Gallery\Api\LinkPreview;
use Modules\Gallery\Api\LinkPreviewFetcher;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\LinkFetchLogRepository;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Service\LinkFetchThrottleService;
use Modules\Groups\Service\PostLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class PostLinkServiceTest extends TestCase
{
    private \PDO $pdo;
    private PostLinkRepository $postLinkRepository;
    private FileRepository $fileRepository;
    private string $storagePath;
    private int $groupId;
    private int $postId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');

        $this->postLinkRepository = new PostLinkRepository($this->pdo);
        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-test-' . uniqid('', true);
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function group(): \Modules\Groups\Repository\DiscussionGroup
    {
        return (new GroupRepository($this->pdo))->findById($this->groupId);
    }

    private function service(LinkPreviewFetcher $fetcher): PostLinkService
    {
        return new PostLinkService(
            $fetcher,
            new LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo)),
            $this->postLinkRepository,
            new UploadHandler($this->fileRepository, $this->storagePath),
            $this->fileRepository,
            $this->storagePath
        );
    }

    private function tinyPngBytes(): string
    {
        // A minimal valid 1x1 transparent PNG.
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }

    public function testAttachStoresTitleDescriptionAndImageOnSuccess(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(new LinkPreview('Title', 'Description', $this->tinyPngBytes()));

        $this->service($fetcher)->attach($this->group(), $this->postId, 'https://example.com/a', 3, 7);

        $link = $this->postLinkRepository->findForPost($this->postId);
        $this->assertSame('Title', $link->title);
        $this->assertSame('Description', $link->description);
        $this->assertNotNull($link->imageFileId);
        $this->assertNotNull($this->fileRepository->findById($link->imageFileId));
    }

    public function testAttachStoresAPlainLinkWhenTheFetchFailsEntirely(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(null);

        $this->service($fetcher)->attach($this->group(), $this->postId, 'https://example.com/dead', 3, 7);

        $link = $this->postLinkRepository->findForPost($this->postId);
        $this->assertSame('https://example.com/dead', $link->url);
        $this->assertNull($link->title);
        $this->assertNull($link->imageFileId);
    }

    public function testAttachDegradesToNoImageWhenThereAreNoOgTagsAtAll(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(new LinkPreview(null, null, null));

        $this->service($fetcher)->attach($this->group(), $this->postId, 'https://example.com/bare', 3, 7);

        $link = $this->postLinkRepository->findForPost($this->postId);
        $this->assertNull($link->title);
        $this->assertNull($link->imageFileId);
    }

    public function testAttachStoresTitleWithoutAnImageWhenTheImageBytesAreUndecodable(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(new LinkPreview('Title', null, 'not-a-real-image'));

        $this->service($fetcher)->attach($this->group(), $this->postId, 'https://example.com/a', 3, 7);

        $link = $this->postLinkRepository->findForPost($this->postId);
        $this->assertSame('Title', $link->title);
        $this->assertNull($link->imageFileId);
    }

    public function testAttachNeverCallsFetchWhenTheMemberIsThrottled(): void
    {
        // Exhaust the member's budget first.
        $throttle = new LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo));
        for ($i = 0; $i < 15; $i++) {
            $throttle->allowFetch(3);
        }

        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->never())->method('fetch');

        $service = new PostLinkService(
            $fetcher, $throttle, $this->postLinkRepository,
            new UploadHandler($this->fileRepository, $this->storagePath), $this->fileRepository, $this->storagePath
        );
        $service->attach($this->group(), $this->postId, 'https://example.com/a', 3, 7);

        // Still becomes a plain link — a throttled fetch is not a reason
        // to lose the post's link entirely.
        $link = $this->postLinkRepository->findForPost($this->postId);
        $this->assertSame('https://example.com/a', $link->url);
        $this->assertNull($link->title);
    }

    public function testDeleteForPostRemovesTheStoredImageFileAndRow(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(new LinkPreview('Title', null, $this->tinyPngBytes()));
        $service = $this->service($fetcher);
        $service->attach($this->group(), $this->postId, 'https://example.com/a', 3, 7);

        $link = $this->postLinkRepository->findForPost($this->postId);
        $file = $this->fileRepository->findById($link->imageFileId);
        $absolutePath = $this->storagePath . '/' . $file->relativePath;
        $this->assertFileExists($absolutePath);

        $service->deleteForPost($this->postId);

        $this->assertFileDoesNotExist($absolutePath);
        $this->assertNull($this->fileRepository->findById($link->imageFileId));
    }

    public function testDeleteForPostOnAPostWithNoLinkIsANoOp(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->never())->method('fetch');

        $this->service($fetcher)->deleteForPost($this->postId);

        $this->assertNull($this->postLinkRepository->findForPost($this->postId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'no scheme' => ['example.com/a'];
        yield 'ftp scheme' => ['ftp://example.com/a'];
        yield 'empty' => [''];
        yield 'too long' => ['https://example.com/' . str_repeat('a', 2100)];
        yield 'malformed' => ['http://'];
    }

    #[DataProvider('invalidUrls')]
    public function testIsValidUrlRejectsUnsafeOrMalformedInput(string $url): void
    {
        $this->assertFalse(PostLinkService::isValidUrl($url));
    }

    public static function validUrls(): iterable
    {
        yield 'https' => ['https://example.com/page?x=1'];
        yield 'http' => ['http://example.com/page'];
    }

    #[DataProvider('validUrls')]
    public function testIsValidUrlAcceptsWellFormedHttpUrls(string $url): void
    {
        $this->assertTrue(PostLinkService::isValidUrl($url));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function textsWithUrls(): iterable
    {
        yield 'plain URL alone' => ['https://example.com/page', 'https://example.com/page'];
        yield 'URL inside a sentence' => ['Regarde ça: https://example.com/page trop bien', 'https://example.com/page'];
        yield 'trailing full stop trimmed' => ['Voir https://example.com/page.', 'https://example.com/page'];
        yield 'trailing closing parenthesis trimmed' => ['(voir https://example.com/page)', 'https://example.com/page'];
        yield 'only the first of two URLs' => ['https://first.example puis https://second.example', 'https://first.example'];
        yield 'no URL at all' => ['Un message tout à fait normal.', null];
        yield 'empty text' => ['', null];
        yield 'a bare scheme is not a URL' => ['Regarde http://', null];
    }

    #[DataProvider('textsWithUrls')]
    public function testFirstUrlInFindsTheFirstWellFormedUrlOrNull(string $text, ?string $expected): void
    {
        $this->assertSame($expected, PostLinkService::firstUrlIn($text));
    }

    public function testFirstUrlInIgnoresAJavascriptSchemeAndStillFindsTheRealUrlAfterIt(): void
    {
        // The regex only ever matches starting from an http(s):// prefix,
        // so a javascript: URI earlier in the text is never even a
        // candidate — nothing here relies on isValidUrl() to catch a
        // scheme the pattern itself cannot produce in the first place.
        $this->assertSame(
            'https://example.com/page',
            PostLinkService::firstUrlIn('javascript:alert(1) mais regarde https://example.com/page')
        );
    }

    public function testStripUrlRemovesTheUrlAndCollapsesTheGapItLeaves(): void
    {
        // The URL substring itself is removed and the double space it
        // leaves behind is collapsed to one — the surrounding sentence
        // text (including its own punctuation) is otherwise untouched.
        $this->assertSame(
            'Regarde ça: trop bien',
            PostLinkService::stripUrl('Regarde ça: https://example.com/page trop bien', 'https://example.com/page')
        );
    }

    public function testStripUrlRemovesEveryOccurrenceOfTheExactUrl(): void
    {
        $this->assertSame(
            'Deux fois',
            PostLinkService::stripUrl('Deux https://example.com fois https://example.com', 'https://example.com')
        );
    }

    public function testStripUrlLeavesTheTextUnchangedWhenTheUrlIsNotPresent(): void
    {
        $this->assertSame('Un message', PostLinkService::stripUrl('Un message', 'https://example.com'));
    }

    public function testLivePreviewReturnsTitleDescriptionAndADataUriOnSuccessWithoutStoringAnything(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->with('https://example.com/a')
            ->willReturn(new LinkPreview('Title', 'Description', $this->tinyPngBytes()));

        $result = $this->service($fetcher)->livePreview('https://example.com/a', 3);

        $this->assertSame('https://example.com/a', $result['url']);
        $this->assertSame('Title', $result['title']);
        $this->assertSame('Description', $result['description']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['image_data_uri']);

        // Nothing written: this is a preview, not an attachment.
        $this->assertNull($this->postLinkRepository->findForPost($this->postId));
    }

    public function testLivePreviewDegradesToAPlainLinkWhenNothingCouldBeResolved(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(null);

        $result = $this->service($fetcher)->livePreview('https://example.com/dead', 3);

        $this->assertSame('https://example.com/dead', $result['url']);
        $this->assertNull($result['title']);
        $this->assertNull($result['image_data_uri']);
    }

    public function testLivePreviewNeverCallsFetchWhenTheMemberIsThrottled(): void
    {
        $throttle = new LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo));
        for ($i = 0; $i < 15; $i++) {
            $throttle->allowFetch(3);
        }

        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->never())->method('fetch');

        $service = new PostLinkService(
            $fetcher, $throttle, $this->postLinkRepository,
            new UploadHandler($this->fileRepository, $this->storagePath), $this->fileRepository, $this->storagePath
        );
        $result = $service->livePreview('https://example.com/a', 3);

        $this->assertSame('https://example.com/a', $result['url']);
        $this->assertNull($result['title']);
    }

    public function testLivePreviewAndAttachDrawFromTheSameThrottleBucket(): void
    {
        $throttle = new LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo));
        for ($i = 0; $i < 14; $i++) {
            $throttle->allowFetch(3);
        }

        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->willReturn(new LinkPreview('Title', null, null));
        $service = new PostLinkService(
            $fetcher, $throttle, $this->postLinkRepository,
            new UploadHandler($this->fileRepository, $this->storagePath), $this->fileRepository, $this->storagePath
        );

        // The 15th and last slot in the window — spent here, by the
        // preview.
        $service->livePreview('https://example.com/a', 3);

        // attach()'s own fetch is refused: the shared budget is already
        // exhausted, exactly as it would be for two attach() calls back
        // to back.
        $fetcher2 = $this->createMock(LinkPreviewFetcher::class);
        $fetcher2->expects($this->never())->method('fetch');
        $service2 = new PostLinkService(
            $fetcher2, $throttle, $this->postLinkRepository,
            new UploadHandler($this->fileRepository, $this->storagePath), $this->fileRepository, $this->storagePath
        );
        $service2->attach($this->group(), $this->postId, 'https://example.com/a', 3, 7);
    }
}
