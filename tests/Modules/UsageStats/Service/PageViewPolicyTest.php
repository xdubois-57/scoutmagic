<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Service;

use Modules\UsageStats\Service\PageViewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The gate that decides whether a request costs a database write at all.
 * Everything it lets through is a page somebody opened; everything else
 * has to be refused here rather than filtered out at reading time, because
 * the whole performance argument of IT-01 is that the write never happens.
 */
class PageViewPolicyTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    public function testAPlainHtmlPageIsCounted(): void
    {
        $this->assertTrue(self::decide('GET', '/calendar', 200, null, false, self::BROWSER));
    }

    public function testAnExplicitHtmlContentTypeIsStillAPage(): void
    {
        $this->assertTrue(self::decide('GET', '/calendar', 200, 'text/html; charset=utf-8', false, self::BROWSER));
    }

    public function testAPostIsNotAPageView(): void
    {
        $this->assertFalse(self::decide('POST', '/calendar', 200, null, false, self::BROWSER));
    }

    public function testARedirectIsNotAPageView(): void
    {
        $this->assertFalse(self::decide('GET', '/calendar', 302, null, false, self::BROWSER));
    }

    public function testANotFoundIsNotAPageView(): void
    {
        // The router matched nothing, so there is no pattern to count under
        // either — both halves of the refusal.
        $this->assertFalse(self::decide('GET', null, 404, null, false, self::BROWSER));
    }

    public function testAForbiddenPageIsNotCounted(): void
    {
        $this->assertFalse(self::decide('GET', '/finance', 403, null, false, self::BROWSER));
    }

    public function testAJsonEndpointIsNotCounted(): void
    {
        $this->assertFalse(
            self::decide('GET', '/notifications/unread', 200, 'application/json', false, self::BROWSER)
        );
    }

    public function testAnApiRouteIsNotCountedEvenWhenItAnswersHtml(): void
    {
        $this->assertFalse(self::decide('GET', '/api/offline/manifest', 200, null, false, self::BROWSER));
    }

    public function testAFileDownloadIsNotCounted(): void
    {
        $this->assertFalse(self::decide('GET', '/files/{id}', 200, null, true, self::BROWSER));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('crawlers')]
    public function testACrawlerIsNeverCounted(string $userAgent): void
    {
        $this->assertTrue(PageViewPolicy::isCrawler($userAgent));
        $this->assertFalse(self::decide('GET', '/news', 200, null, false, $userAgent));
    }

    /** @return array<string, array{0: string}> */
    public static function crawlers(): array
    {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'Facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'WhatsApp' => ['WhatsApp/2.23.20.0'],
            'curl' => ['curl/8.5.0'],
            'uptime monitor' => ['Better Uptime Bot Mozilla/5.0'],
            'no user agent at all' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('browsers')]
    public function testARealBrowserIsNotMistakenForACrawler(string $userAgent): void
    {
        $this->assertFalse(PageViewPolicy::isCrawler($userAgent));
    }

    /** @return array<string, array{0: string}> */
    public static function browsers(): array
    {
        return [
            'iOS Safari' => [self::BROWSER],
            'Android Chrome' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/120.0.0.0 Mobile Safari/537.36',
            ],
            'Desktop Firefox' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            ],
            'Desktop Safari' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/17.2 Safari/605.1.15',
            ],
        ];
    }

    private static function decide(
        string $method,
        ?string $routePattern,
        int $statusCode,
        ?string $contentType,
        bool $servesAFile,
        string $userAgent
    ): bool {
        return PageViewPolicy::shouldCount($method, $routePattern, $statusCode, $contentType, $servesAFile, $userAgent);
    }
}
