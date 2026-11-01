<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\UrlMetadataFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlMetadataFetcherTest extends TestCase
{
    private UrlMetadataFetcher $service;

    protected function setUp(): void
    {
        $this->service = new UrlMetadataFetcher();
    }

    /**
     * Reproduces a real bug: Google Photos' share page (and other real-world
     * pages) has no <meta charset>, so DOMDocument::loadHTML() defaulted to
     * ISO-8859-1 and mangled real UTF-8 og:title/og:description content
     * ("·" became "Â·", the camera emoji became "ð¸") — see
     * UrlMetadataFetcher::parse() for the fix.
     */
    public function testParseDecodesUtf8CorrectlyWithNoCharsetMeta(): void
    {
        $html = '<!doctype html><html lang="en-US"><head>'
            . '<meta property="og:title" content="Visite camp Seeonee 2026 · Saturday, Jul 25 📸">'
            . '<meta property="og:description" content="Shared album · Tap to view!">'
            . '<meta property="og:image" content="https://lh3.googleusercontent.com/pw/example=w600-h315-p-k">'
            . '</head><body></body></html>';

        $tags = $this->service->parse($html);

        $this->assertSame('Visite camp Seeonee 2026 · Saturday, Jul 25 📸', $tags->ogTitle);
        $this->assertSame('Shared album · Tap to view!', $tags->description);
        $this->assertSame('https://lh3.googleusercontent.com/pw/example=w600-h315-p-k', $tags->imageUrl);
    }

    public function testParseStillWorksWithAnExplicitCharsetMeta(): void
    {
        $html = '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta property="og:title" content="Café en forêt">'
            . '</head><body></body></html>';

        $tags = $this->service->parse($html);

        $this->assertSame('Café en forêt', $tags->ogTitle);
    }

    public function testParseReturnsNullsWhenNoTagsPresent(): void
    {
        $tags = $this->service->parse('<html><head></head><body></body></html>');

        $this->assertNull($tags->ogTitle);
        $this->assertNull($tags->description);
        $this->assertNull($tags->imageUrl);
    }

    public function testAnEmptyHtmlBodyHasNoMetadata(): void
    {
        $tags = $this->service->parse('');

        $this->assertNull($tags->ogTitle);
        $this->assertNull($tags->description);
        $this->assertNull($tags->imageUrl);
        $this->assertNull($tags->htmlTitle);
    }

    /**
     * Some sites (and most WordPress SEO plugins) emit the OG tags with
     * name= instead of the spec's property=. Kept from the parallel
     * gallery-review fix on main, whose parser improvement this
     * class adopted.
     */
    public function testParseAlsoAcceptsTheNameAttribute(): void
    {
        $html = '<html><head>'
            . '<meta name="og:title" content="Camp 2026">'
            . '<meta name="OG:IMAGE" content="https://example.org/cover.jpg">'
            . '</head></html>';

        $tags = $this->service->parse($html);

        $this->assertSame('Camp 2026', $tags->ogTitle);
        $this->assertSame('https://example.org/cover.jpg', $tags->imageUrl);
    }

    /**
     * name= is a fallback for a missing property=, never an override of it:
     * on an element carrying both, property wins.
     */
    public function testParsePrefersPropertyOverNameOnTheSameElement(): void
    {
        $html = '<html><head><meta property="og:title" name="og:description" content="valeur"></head></html>';

        $tags = $this->service->parse($html);

        $this->assertSame('valeur', $tags->ogTitle);
        $this->assertNull($tags->description);
    }

    /**
     * The page's own <title> is read too — the link dialog's « baguette
     * magique » falls back on it when a page has no og:title (#840) —
     * whitespace collapsed, and only the one in <head>: an inline SVG's
     * <title> names a drawing, not the page.
     */
    public function testParseReadsTheDocumentTitleBesideTheOpenGraphTags(): void
    {
        $html = "<html><head><title>\n  Les Scouts —\n   Agenda  </title></head>"
            . '<body><svg><title>Icône</title></svg></body></html>';

        $tags = $this->service->parse($html);

        $this->assertNull($tags->ogTitle);
        $this->assertSame('Les Scouts — Agenda', $tags->htmlTitle);
    }

    public function testParseReportsNoDocumentTitleWhenThereIsOnlyAnSvgOne(): void
    {
        $tags = $this->service->parse('<html><head></head><body><svg><title>Icône</title></svg></body></html>');

        $this->assertNull($tags->htmlTitle);
    }

    public function testFetchImageBytesReturnsNullForAnInvalidUrl(): void
    {
        $this->assertNull($this->service->fetchImageBytes('not-a-url'));
        $this->assertNull($this->service->fetchImageBytes('ftp://example.com/image.jpg'));
    }

    /**
     * SSRF hardening: every one of these targets an IP-literal host, so
     * the rejection happens in isPublicIp()/parseAndValidateUrl() before
     * any DNS lookup or connection attempt — deterministic, no network
     * access required. Covers loopback, RFC1918 private ranges, link-local
     * (including the cloud-metadata address every SSRF checklist starts
     * with), IPv6 loopback and unique-local, an IPv4-mapped IPv6 loopback
     * literal, and multicast.
     *
     * @return iterable<string, array{string}>
     */
    public static function ssrfTargetUrls(): iterable
    {
        yield 'IPv4 loopback' => ['http://127.0.0.1/x'];
        yield 'IPv4 loopback, alternate' => ['http://127.1.2.3/x'];
        yield 'RFC1918 10.x' => ['http://10.0.0.5/x'];
        yield 'RFC1918 172.16.x' => ['http://172.16.0.1/x'];
        yield 'RFC1918 192.168.x' => ['http://192.168.1.1/x'];
        yield 'link-local' => ['http://169.254.1.1/x'];
        yield 'cloud metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'unspecified' => ['http://0.0.0.0/x'];
        yield 'IPv4 multicast' => ['http://224.0.0.1/x'];
        yield 'IPv6 loopback' => ['http://[::1]/x'];
        yield 'IPv6 unique-local' => ['http://[fc00::1]/x'];
        yield 'IPv6 link-local' => ['http://[fe80::1]/x'];
        yield 'IPv6 multicast' => ['http://[ff02::1]/x'];
        yield 'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/x'];
        yield 'https scheme, loopback' => ['https://127.0.0.1/x'];
    }

    #[DataProvider('ssrfTargetUrls')]
    public function testFetchImageBytesRejectsPrivateAndReservedTargets(string $url): void
    {
        $this->assertNull($this->service->fetchImageBytes($url));
    }

    #[DataProvider('ssrfTargetUrls')]
    public function testFetchRejectsPrivateAndReservedTargets(string $url): void
    {
        $this->assertNull($this->service->fetch($url));
    }

    public function testFetchImageBytesRejectsEmbeddedCredentials(): void
    {
        $this->assertNull($this->service->fetchImageBytes('http://user:pass@127.0.0.1/x'));
    }

    /**
     * A safe (public) host is still rejected when the URL asks for a
     * non-default port — connecting to an arbitrary port on an otherwise
     * public address is exactly how an SSRF payload reaches an internal
     * service (a database, an admin panel) that only happens to be
     * fronted by a machine with a public IP.
     */
    public function testFetchImageBytesRejectsNonDefaultPorts(): void
    {
        // 93.184.216.34 is example.com's own address (a real public IP,
        // no DNS lookup needed for this assertion since the rejection
        // happens on the port check alone, before resolution).
        $this->assertNull($this->service->fetchImageBytes('http://93.184.216.34:8080/x'));
        $this->assertNull($this->service->fetchImageBytes('https://93.184.216.34:8443/x'));
    }
}
