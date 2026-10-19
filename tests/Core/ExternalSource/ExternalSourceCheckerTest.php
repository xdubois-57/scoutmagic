<?php

declare(strict_types=1);

namespace Tests\Core\ExternalSource;

use Core\ExternalSource\ExternalSource;
use Core\ExternalSource\ExternalSourceChecker;
use Core\ExternalSource\ExternalSourceKind;
use Core\ExternalSource\ExternalSources;
use Core\ExternalSource\FederalScale;
use Core\ExternalSource\FetchedPage;
use Core\ExternalSource\PageFetcherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checker behind `scripts/check-external-sources.php` and the sixth
 * release gate (issue #355), replayed on recorded pages — never the
 * network.
 *
 * The fixtures under `tests/fixtures/external-sources/` are excerpts, not
 * whole copies: `fees-conform.html` is the fees page as it read on
 * 24 September 2026, with the two markup defects the real page carries
 * right before its amounts; `fees-changed-amounts.html` is the same page a
 * year on; `page-moved.html` is what a site serves once a page has gone.
 */
final class ExternalSourceCheckerTest extends TestCase
{
    private static function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/fixtures/external-sources/' . $name);
        self::assertIsString($contents, "Fixture {$name} is unreadable.");

        return $contents;
    }

    private static function feesSource(): ExternalSource
    {
        $source = ExternalSources::byId(ExternalSources::FEES_PAGE_ID);
        self::assertNotNull($source);

        return $source;
    }

    private static function checker(FetchedPage $page, ?FederalScale $reference = null): ExternalSourceChecker
    {
        return new ExternalSourceChecker(new class ($page) implements PageFetcherInterface {
            public function __construct(private readonly FetchedPage $page)
            {
            }

            public function fetch(string $url): FetchedPage
            {
                return $this->page;
            }
        }, $reference);
    }

    private static function link(): ExternalSource
    {
        return new ExternalSource('console', 'https://console.example.test/', ExternalSourceKind::Link, 'test', []);
    }

    // ── Content: the federal fees page ─────────────────────────────────

    public function testTheConformingFeesPageConformsAndItsAmountsAreReported(): void
    {
        $result = self::checker(new FetchedPage(200, self::fixture('fees-conform.html')))
            ->check(self::feesSource());

        $this->assertTrue($result->isConform(), implode("\n", $result->divergences));
        $this->assertNotNull($result->scale);
        $this->assertSame([5750, 4600, 3900], [
            $result->scale->normalCents,
            $result->scale->coupleCents,
            $result->scale->familyCents,
        ]);
        $this->assertSame('2026-2027', $result->scale->year);
        $this->assertContains(
            'amounts on the page: 2026-2027: normal 57,50 €, couple 46,00 €, family 39,00 €',
            $result->notes
        );
    }

    /**
     * A caller that passes no reference scale (the script always passes
     * the shipped one): the note says the amounts were not compared,
     * rather than implying they were.
     */
    public function testWithoutAReferenceScaleTheReportSaysNothingWasCompared(): void
    {
        $result = self::checker(new FetchedPage(200, self::fixture('fees-changed-amounts.html')))
            ->check(self::feesSource());

        $this->assertTrue($result->isConform());
        $this->assertContains(
            'no shipped scale to compare them with yet — amounts reported, not compared',
            $result->notes
        );
    }

    public function testChangedAmountsDivergeFromTheReferenceScale(): void
    {
        $reference = new FederalScale(5750, 4600, 3900, '2026-2027');

        $result = self::checker(new FetchedPage(200, self::fixture('fees-changed-amounts.html')), $reference)
            ->check(self::feesSource());

        $this->assertFalse($result->isConform());
        $this->assertSame(
            ['amounts changed: the page says 2027-2028: normal 59,00 €, couple 47,50 €, family 40,25 €; '
                . 'the shipped scale says 2026-2027: normal 57,50 €, couple 46,00 €, family 39,00 €'],
            $result->divergences
        );
    }

    public function testUnchangedAmountsConformToTheReferenceScale(): void
    {
        $reference = new FederalScale(5750, 4600, 3900);

        $result = self::checker(new FetchedPage(200, self::fixture('fees-conform.html')), $reference)
            ->check(self::feesSource());

        $this->assertTrue($result->isConform(), implode("\n", $result->divergences));
    }

    public function testANewSeasonWithTheSameAmountsDivergesOnItsYear(): void
    {
        $reference = new FederalScale(5750, 4600, 3900, '2025-2026');

        $result = self::checker(new FetchedPage(200, self::fixture('fees-conform.html')), $reference)
            ->check(self::feesSource());

        $this->assertSame(
            ['season changed: the page is about 2026-2027, the shipped scale about 2025-2026 '
                . '— same amounts, but its year must follow'],
            $result->divergences
        );
    }

    public function testAMovedPageAnswering404Diverges(): void
    {
        $result = self::checker(new FetchedPage(404, self::fixture('page-moved.html')))
            ->check(self::feesSource());

        $this->assertSame(['HTTP 404 — the page is gone'], $result->divergences);
    }

    /**
     * A site that answers 200 with its "not found" page, or that replaced
     * the page with another one: the status proves nothing, the content
     * does.
     */
    public function testAReplacedPageAnswering200DivergesOnItsContent(): void
    {
        $result = self::checker(new FetchedPage(200, self::fixture('page-moved.html')))
            ->check(self::feesSource());

        $this->assertFalse($result->isConform());
        $this->assertContains(
            'expected content « Cotisation normale » not found — the page was replaced, '
                . 'or no longer says what the site links it for',
            $result->divergences
        );
        $this->assertContains(
            'the normal, couple and family amounts could not all be read off the page',
            $result->divergences
        );
    }

    /**
     * FederalScaleLookupService refuses redirects, so for the fees page a
     * redirect breaks « Chercher les montants » exactly like a 404.
     */
    public function testARedirectDivergesForASourceWhoseConsumerRefusesRedirects(): void
    {
        $page = new FetchedPage(
            200,
            self::fixture('fees-conform.html'),
            'https://www.lesscouts.be/fr/new-fees',
            null,
            true
        );

        $result = self::checker($page)->check(self::feesSource());

        $this->assertFalse($result->isConform());
        $this->assertStringStartsWith('redirects to https://www.lesscouts.be/fr/new-fees', $result->divergences[0]);
    }

    public function testARedirectIsOnlyANoteForAnOrdinaryContentSource(): void
    {
        $source = new ExternalSource(
            'page',
            'https://lesscouts.be/fr/page',
            ExternalSourceKind::Content,
            'test',
            [],
            ['Le parcours scout']
        );
        $page = new FetchedPage(200, '<h1>Le parcours scout</h1>', 'https://www.lesscouts.be/fr/page', null, true);

        $result = self::checker($page)->check($source);

        $this->assertTrue($result->isConform());
        $this->assertSame(['moved permanently to https://www.lesscouts.be/fr/page'], $result->notes);
    }

    // ── Link: consoles and legal pages ─────────────────────────────────

    /**
     * @return array<string, array{int}>
     */
    public static function aliveStatuses(): array
    {
        return ['200' => [200], 'redirect not followed' => [302], 'unauthorised' => [401], 'forbidden' => [403]];
    }

    #[DataProvider('aliveStatuses')]
    public function testALinkIsAliveBehindALogin(int $status): void
    {
        $this->assertTrue(self::checker(new FetchedPage($status))->check(self::link())->isConform());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function deadStatuses(): array
    {
        return [
            'not found' => [404, 'HTTP 404 — the page is gone'],
            'gone' => [410, 'HTTP 410 — the page is gone'],
            'server error' => [503, 'HTTP 503 — server error'],
            'other' => [400, 'HTTP 400'],
        ];
    }

    #[DataProvider('deadStatuses')]
    public function testALinkIsDeadOtherwise(int $status, string $reason): void
    {
        $this->assertSame([$reason], self::checker(new FetchedPage($status))->check(self::link())->divergences);
    }

    public function testAnUnknownDomainIsDead(): void
    {
        $result = self::checker(FetchedPage::unreachable('php_network_getaddresses: getaddrinfo failed'))
            ->check(self::link());

        $this->assertFalse($result->isConform());
        $this->assertStringContainsString('getaddrinfo failed', $result->divergences[0]);
    }

    public function testALoginRedirectKeepsALinkAliveAndIsNoted(): void
    {
        $page = new FetchedPage(200, '', 'https://auth.example.test/login');

        $result = self::checker($page)->check(self::link());

        $this->assertTrue($result->isConform());
        $this->assertSame(['redirects to https://auth.example.test/login'], $result->notes);
    }

    // ── A host that answers only some of the time (issue #760) ─────────

    /**
     * A fetcher replaying `$pages` in order, one per call, and counting the
     * calls: the number of requests the checker makes is what is tested.
     *
     * @param list<FetchedPage> $pages
     */
    private static function sequence(array $pages): object
    {
        return new class ($pages) implements PageFetcherInterface {
            public int $calls = 0;

            /** @param list<FetchedPage> $pages */
            public function __construct(private readonly array $pages)
            {
            }

            public function fetch(string $url): FetchedPage
            {
                return $this->pages[min($this->calls++, count($this->pages) - 1)];
            }
        };
    }

    public function testASingleAttemptIsTheDefaultAndAnUnansweredRequestDiverges(): void
    {
        $fetcher = self::sequence([FetchedPage::unreachable('timed out'), new FetchedPage(200)]);

        $result = (new ExternalSourceChecker($fetcher))->check(self::link());

        $this->assertFalse($result->isConform());
        $this->assertSame(1, $fetcher->calls);
        $this->assertStringContainsString('no answer (timed out)', $result->divergences[0]);
    }

    public function testAnIsolatedFailureDoesNotDivergeWhenASecondAttemptIsAllowed(): void
    {
        $fetcher = self::sequence([FetchedPage::unreachable('timed out'), new FetchedPage(200)]);

        $result = (new ExternalSourceChecker($fetcher, attempts: 2))->check(self::link());

        $this->assertTrue($result->isConform(), implode("\n", $result->divergences));
        $this->assertSame(200, $result->status);
        $this->assertSame(2, $fetcher->calls);
    }

    public function testARepeatedFailureStillDivergesAndSaysHowManyAttemptsWereMade(): void
    {
        $fetcher = self::sequence([FetchedPage::unreachable('timed out')]);

        $result = (new ExternalSourceChecker($fetcher, attempts: 2))->check(self::link());

        $this->assertFalse($result->isConform());
        $this->assertSame(2, $fetcher->calls);
        $this->assertStringContainsString('no answer after 2 attempts (timed out)', $result->divergences[0]);
    }

    /**
     * Only silence is retried: a server that answered 404 or 500 said what
     * it had to say, and asking again would hide a page that really is gone.
     */
    public function testARealAnswerIsNeverRetried(): void
    {
        $fetcher = self::sequence([new FetchedPage(404), new FetchedPage(200)]);

        $result = (new ExternalSourceChecker($fetcher, attempts: 2))->check(self::link());

        $this->assertFalse($result->isConform());
        $this->assertSame(1, $fetcher->calls);
        $this->assertSame(['HTTP 404 — the page is gone'], $result->divergences);
    }

    public function testASecondAttemptAlsoSavesAContentSource(): void
    {
        $fetcher = self::sequence([
            FetchedPage::unreachable('timed out'),
            new FetchedPage(200, self::fixture('fees-conform.html')),
        ]);

        $result = (new ExternalSourceChecker($fetcher, attempts: 2))->check(self::feesSource());

        $this->assertTrue($result->isConform(), implode("\n", $result->divergences));
        $this->assertSame(2, $fetcher->calls);
    }

    public function testFewerThanOneAttemptStillMakesOneRequest(): void
    {
        $fetcher = self::sequence([new FetchedPage(200)]);

        (new ExternalSourceChecker($fetcher, attempts: 0))->check(self::link());

        $this->assertSame(1, $fetcher->calls);
    }

    /**
     * The retry is for the release gate only (the maintainer's decision on
     * #760): the weekly check stays a single attempt, and the option is
     * spelled out. An unknown option exits 2 — "could not run" — before any
     * page is fetched, rather than being ignored.
     */
    public function testTheScriptRefusesAnUnknownOptionBeforeFetchingAnything(): void
    {
        $script = dirname(__DIR__, 3) . '/scripts/check-external-sources.php';

        $process = proc_open(
            [PHP_BINARY, $script, '--no-such-option'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout, 'A page was checked despite the unknown option.');
        $this->assertStringContainsString('--retry-unreachable', $stderr);
    }

    // ── The report and the exit code ───────────────────────────────────

    public function testTheReportListsDivergencesFirstAndCounts(): void
    {
        $ok = self::checker(new FetchedPage(200))->check(self::link());
        $dead = self::checker(new FetchedPage(404))->check(self::feesSource());

        $report = ExternalSourceChecker::report([$ok, $dead]);

        $this->assertStringStartsWith('[DIVERGENT] ' . ExternalSources::FEES_PAGE_ID, $report);
        $this->assertStringContainsString("[ok] console (link, HTTP 200)", $report);
        $this->assertStringContainsString('shipped default: setting fees_federal_scale_url', $report);
        $this->assertStringContainsString('2 source(s) checked: 1 conform, 1 divergent.', $report);
        $this->assertFalse(ExternalSourceChecker::allConform([$ok, $dead]));
        $this->assertTrue(ExternalSourceChecker::allConform([$ok]));
    }

    /**
     * scripts/ ships with the artifact and .htaccess does not apply on
     * nginx, so the script refuses a web request itself, before it loads
     * anything or fetches a single page (SECURITY.md §24).
     */
    public function testTheScriptRefusesAnythingButTheCommandLineFirst(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/check-external-sources.php');

        $guard = strpos($script, "if (PHP_SAPI !== 'cli') {");
        $this->assertNotFalse($guard, 'The script has no CLI guard.');
        $this->assertLessThan((int) strpos($script, 'require $autoload'), $guard);
    }
}
