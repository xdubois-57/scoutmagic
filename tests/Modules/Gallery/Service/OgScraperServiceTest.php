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

    public function testFetchImageBytesReturnsNullForAnInvalidUrl(): void
    {
        $this->assertNull($this->service->fetchImageBytes('not-a-url'));
        $this->assertNull($this->service->fetchImageBytes('ftp://example.com/image.jpg'));
    }
}
