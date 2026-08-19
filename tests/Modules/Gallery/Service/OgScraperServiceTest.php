<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\OgScraperService;
use PHPUnit\Framework\TestCase;

class OgScraperServiceTest extends TestCase
{
    private OgScraperService $service;

    protected function setUp(): void
    {
        $this->service = new OgScraperService();
    }

    /**
     * Reproduces a real bug: Google Photos' share page (and other real-world
     * pages) has no <meta charset>, so DOMDocument::loadHTML() defaulted to
     * ISO-8859-1 and mangled real UTF-8 og:title/og:description content
     * ("·" became "Â·", the camera emoji became "ð¸") — see
     * OgScraperService::parseOgTags() for the fix.
     */
    public function testParseOgTagsDecodesUtf8CorrectlyWithNoCharsetMeta(): void
    {
        $html = '<!doctype html><html lang="en-US"><head>'
            . '<meta property="og:title" content="Visite camp Seeonee 2026 · Saturday, Jul 25 📸">'
            . '<meta property="og:description" content="Shared album · Tap to view!">'
            . '<meta property="og:image" content="https://lh3.googleusercontent.com/pw/example=w600-h315-p-k">'
            . '</head><body></body></html>';

        $tags = $this->service->parseOgTags($html);

        $this->assertSame('Visite camp Seeonee 2026 · Saturday, Jul 25 📸', $tags['title']);
        $this->assertSame('Shared album · Tap to view!', $tags['description']);
        $this->assertSame('https://lh3.googleusercontent.com/pw/example=w600-h315-p-k', $tags['image']);
    }

    public function testParseOgTagsStillWorksWithAnExplicitCharsetMeta(): void
    {
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta property="og:title" content="Café en forêt">'
            . '</head><body></body></html>';

        $tags = $this->service->parseOgTags($html);

        $this->assertSame('Café en forêt', $tags['title']);
    }

    public function testParseOgTagsReturnsNullsWhenNoTagsPresent(): void
    {
        $tags = $this->service->parseOgTags('<html><head></head><body></body></html>');

        $this->assertNull($tags['title']);
        $this->assertNull($tags['description']);
        $this->assertNull($tags['image']);
    }

    /**
     * Some sites (and most WordPress SEO plugins) emit the OG tags with
     * name= instead of the spec's property=.
     */
    public function testParseOgTagsAlsoAcceptsTheNameAttribute(): void
    {
        $html = '<html><head>'
            . '<meta name="og:title" content="Camp 2026">'
            . '<meta name="OG:IMAGE" content="https://example.org/cover.jpg">'
            . '</head></html>';

        $tags = $this->service->parseOgTags($html);

        $this->assertSame('Camp 2026', $tags['title']);
        $this->assertSame('https://example.org/cover.jpg', $tags['image']);
    }

    /**
     * name= is a fallback for a missing property=, never an override of it:
     * on an element carrying both, property wins.
     */
    public function testParseOgTagsPrefersPropertyOverNameOnTheSameElement(): void
    {
        $html = '<html><head><meta property="og:title" name="og:description" content="valeur"></head></html>';

        $tags = $this->service->parseOgTags($html);

        $this->assertSame('valeur', $tags['title']);
        $this->assertNull($tags['description']);
    }

    public function testFetchImageBytesReturnsNullForAnInvalidUrl(): void
    {
        $this->assertNull($this->service->fetchImageBytes('not-a-url'));
        $this->assertNull($this->service->fetchImageBytes('ftp://example.com/image.jpg'));
    }

    /**
     * The album link is chief-supplied and the server fetches it, so the
     * guard is what stops the app from GETting an internal address and
     * surfacing part of the response (og:title/og:description, and the
     * og:image bytes, cached and served back via /files/{id}).
     *
     * Literal addresses throughout: the assertions must hold with no DNS and
     * no network at all.
     *
     * @dataProvider blockedUrls
     */
    public function testIsFetchableUrlRefusesAnUnsafeTarget(string $url): void
    {
        $this->assertFalse($this->service->isFetchableUrl($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 172.16/12' => ['http://172.16.3.9/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'link-local cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://8.8.8.8/'],
            'ftp scheme' => ['ftp://8.8.8.8/'],
            'no scheme' => ['//8.8.8.8/album'],
            'garbage' => ['not-a-url'],
            'empty' => [''],
            'unbracketed IPv6' => ['https://2001:4860:4860::8888/'],
        ];
    }

    /**
     * @dataProvider allowedUrls
     */
    public function testIsFetchableUrlAcceptsAPublicTarget(string $url): void
    {
        $this->assertTrue($this->service->isFetchableUrl($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'public IPv4 over https' => ['https://8.8.8.8/album'],
            'public IPv4 over http' => ['http://8.8.8.8/album'],
            'public IPv6' => ['http://[2001:4860:4860::8888]/album'],
        ];
    }

    public function testFetchImageBytesRefusesABlockedTargetWithoutAnyRequest(): void
    {
        $this->assertNull($this->service->fetchImageBytes('http://169.254.169.254/latest/meta-data/'));
        $this->assertNull($this->service->fetchImageBytes('http://127.0.0.1:8080/internal'));
    }

    public function testFetchThrowsForABlockedTarget(): void
    {
        $this->expectException(\Modules\Gallery\Service\GalleryException::class);
        $this->service->fetch('http://10.1.2.3/album');
    }
}
