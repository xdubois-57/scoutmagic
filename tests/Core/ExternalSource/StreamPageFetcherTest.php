<?php

declare(strict_types=1);

namespace Tests\Core\ExternalSource;

use Core\ExternalSource\StreamPageFetcher;
use PHPUnit\Framework\TestCase;

/**
 * How the stream wrapper's concatenated header list is read once
 * redirects have been followed — recorded header lists, no network.
 */
final class StreamPageFetcherTest extends TestCase
{
    public function testASingleAnswerHasNoRedirect(): void
    {
        $page = StreamPageFetcher::fromHeaders(
            'https://a.test/x',
            ['HTTP/1.1 200 OK', 'Content-Type: text/html'],
            'body'
        );

        $this->assertSame(200, $page->status);
        $this->assertSame('body', $page->body);
        $this->assertNull($page->redirectedTo);
        $this->assertFalse($page->movedPermanently);
    }

    public function testTheLastStatusAndTheLastLocationWin(): void
    {
        $page = StreamPageFetcher::fromHeaders('https://console.a.test/', [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://platform.b.test/',
            'HTTP/1.1 302 Found',
            'location: /login?next=%2F',
            'HTTP/1.1 200 OK',
        ], '');

        $this->assertSame(200, $page->status);
        $this->assertSame('https://platform.b.test/login?next=%2F', $page->redirectedTo);
        $this->assertTrue($page->movedPermanently);
    }

    public function testATemporaryRedirectIsNotAMove(): void
    {
        $page = StreamPageFetcher::fromHeaders('https://a.test/dir/page', [
            'HTTP/1.1 302 Found',
            'Location: other',
            'HTTP/1.1 404 Not Found',
        ], '');

        $this->assertSame(404, $page->status);
        $this->assertSame('https://a.test/dir/other', $page->redirectedTo);
        $this->assertFalse($page->movedPermanently);
    }

    public function testAHeaderListWithoutAStatusLineIsNoAnswer(): void
    {
        $page = StreamPageFetcher::fromHeaders('https://a.test/', ['Content-Type: text/html'], '');

        $this->assertSame(0, $page->status);
        $this->assertSame('no status line', $page->error);
    }
}
