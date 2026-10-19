<?php

declare(strict_types=1);

namespace Tests\Core\ExternalSource;

use Core\ExternalSource\ExternalSources;
use Core\ExternalSource\ExternalSourcesCommand;
use Core\ExternalSource\FederalScale;
use Core\ExternalSource\FetchedPage;
use Core\ExternalSource\PageFetcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * What `scripts/check-external-sources.php` does once autoloaded — its
 * arguments, its exit code, what it prints — replayed with a fetcher that
 * never touches the network (issues #355 and #760).
 */
final class ExternalSourcesCommandTest extends TestCase
{
    private static function fetcher(FetchedPage $page): object
    {
        return new class ($page) implements PageFetcherInterface {
            public int $calls = 0;

            public function __construct(private readonly FetchedPage $page)
            {
            }

            public function fetch(string $url): FetchedPage
            {
                $this->calls++;

                return $this->page;
            }
        };
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private static function execute(
        PageFetcherInterface $fetcher,
        array $arguments,
        ?\Closure $loadScale = null,
    ): array {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $exit = (new ExternalSourcesCommand($fetcher, $loadScale ?? static fn (): FederalScale => new FederalScale(5750, 4600, 3900)))
            ->run($arguments, $out, $err);

        rewind($out);
        rewind($err);

        return [$exit, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testAnUnknownOptionExitsTwoBeforeFetchingAnything(): void
    {
        $fetcher = self::fetcher(new FetchedPage(404));

        [$exit, $stdout, $stderr] = self::execute($fetcher, ['--no-such-option']);

        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame(0, $fetcher->calls);
        $this->assertStringContainsString('Unknown option --no-such-option', $stderr);
        $this->assertStringContainsString('--retry-unreachable', $stderr);
    }

    public function testAnUnreadableShippedScaleExitsTwoBeforeFetchingAnything(): void
    {
        $fetcher = self::fetcher(new FetchedPage(404));

        [$exit, $stdout, $stderr] = self::execute(
            $fetcher,
            [],
            static fn (): FederalScale => throw new \UnexpectedValueException('federal-scale.json is not valid JSON')
        );

        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame(0, $fetcher->calls);
        $this->assertStringContainsString('Shipped federal scale unreadable: federal-scale.json is not valid JSON', $stderr);
    }

    public function testADivergenceExitsOneAndIsReportedOnStandardOutput(): void
    {
        $fetcher = self::fetcher(new FetchedPage(404));

        [$exit, $stdout, $stderr] = self::execute($fetcher, []);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[DIVERGENT]', $stdout);
        $this->assertStringContainsString('source(s) checked', $stdout);
        $this->assertSame('', $stderr);
    }

    /**
     * The scale the script loads is the reference the fees page is compared
     * with: a page whose amounts differ from it diverges, naming both.
     */
    public function testTheLoadedScaleIsTheReferenceTheFeesPageIsComparedWith(): void
    {
        $feesUrl = ExternalSources::byId(ExternalSources::FEES_PAGE_ID)?->url;
        $this->assertNotNull($feesUrl);
        $page = new FetchedPage(200, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/external-sources/fees-conform.html'));
        $fetcher = new class ($feesUrl, $page) implements PageFetcherInterface {
            public function __construct(private readonly string $feesUrl, private readonly FetchedPage $page)
            {
            }

            public function fetch(string $url): FetchedPage
            {
                return $url === $this->feesUrl ? $this->page : new FetchedPage(200);
            }
        };

        [, $same] = self::execute($fetcher, [], static fn (): FederalScale => new FederalScale(5750, 4600, 3900));
        [, $other] = self::execute($fetcher, [], static fn (): FederalScale => new FederalScale(1, 2, 3));

        $this->assertStringNotContainsString('amounts changed', $same);
        $this->assertStringContainsString('amounts changed', $other);
    }

    /** The weekly check: one request per source, whatever the host does. */
    public function testWithoutTheOptionEachSourceIsAskedOnce(): void
    {
        $fetcher = self::fetcher(FetchedPage::unreachable('timed out'));

        [$exit] = self::execute($fetcher, []);

        $this->assertSame(1, $exit);
        $this->assertSame(count(ExternalSources::all()), $fetcher->calls);
    }

    /** The release gate: a source that did not answer is asked a second time. */
    public function testRetryUnreachableAsksASilentSourceASecondTime(): void
    {
        $fetcher = self::fetcher(FetchedPage::unreachable('timed out'));

        [$exit, $stdout] = self::execute($fetcher, ['--retry-unreachable']);

        $this->assertSame(1, $exit);
        $this->assertSame(2 * count(ExternalSources::all()), $fetcher->calls);
        $this->assertStringContainsString('no answer after 2 attempts (timed out)', $stdout);
    }
}
