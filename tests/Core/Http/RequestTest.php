<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testFromGlobalsCreatesValidRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test?foo=bar';
        $_GET = ['foo' => 'bar'];
        $_POST = [];
        $_COOKIE = [];

        $request = Request::fromGlobals();

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/test', $request->getPath());
    }

    public function testGetPathReturnsCorrectPath(): void
    {
        $request = new Request('GET', '/members/42', [], [], [], []);

        $this->assertSame('/members/42', $request->getPath());
    }

    public function testGetQueryReturnsParameterWithDefault(): void
    {
        $request = new Request('GET', '/', ['page' => '2'], [], [], []);

        $this->assertSame('2', $request->getQuery('page'));
        $this->assertNull($request->getQuery('missing'));
        $this->assertSame('default', $request->getQuery('missing', 'default'));
    }

    public function testGetMethodReturnsUppercaseMethod(): void
    {
        $request = new Request('post', '/submit', [], [], [], []);

        // Constructor receives whatever is passed; fromGlobals uppercases it
        $this->assertSame('post', $request->getMethod());

        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/submit';
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];

        $fromGlobals = Request::fromGlobals();
        $this->assertSame('POST', $fromGlobals->getMethod());
    }

    public function testGetBodyReturnsBodyParameter(): void
    {
        $request = new Request('POST', '/', [], ['name' => 'test'], [], []);

        $this->assertSame('test', $request->getBody('name'));
        $this->assertNull($request->getBody('missing'));
    }

    public function testGetCookieReturnsCookieValue(): void
    {
        $request = new Request('GET', '/', [], [], ['session' => 'abc123'], []);

        $this->assertSame('abc123', $request->getCookie('session'));
        $this->assertNull($request->getCookie('missing'));
    }

    public function testGetServerReturnsServerValue(): void
    {
        $request = new Request('GET', '/', [], [], [], ['HTTP_HOST' => 'localhost']);

        $this->assertSame('localhost', $request->getServer('HTTP_HOST'));
        $this->assertNull($request->getServer('MISSING'));
    }

    protected function tearDown(): void
    {
        unset($_FILES, $_SERVER['CONTENT_LENGTH']);
    }

    public function testParseIniSizeToBytesHandlesUnitsAndUnlimited(): void
    {
        $this->assertSame(1048576, Request::parseIniSizeToBytes('1M'));
        $this->assertSame(2147483648, Request::parseIniSizeToBytes('2G'));
        $this->assertSame(524288, Request::parseIniSizeToBytes('512K'));
        $this->assertSame(100, Request::parseIniSizeToBytes('100'));
        $this->assertSame(0, Request::parseIniSizeToBytes('0'));
        $this->assertSame(0, Request::parseIniSizeToBytes(''));
    }

    public function testIsPostTooLargeReturnsTrueWhenContentLengthExceedsPostMaxSize(): void
    {
        // post_max_size is PHP_INI_PERDIR — can't be overridden via
        // ini_set() in a test, so this reads the real configured value
        // and picks a Content-Length guaranteed to exceed it.
        $postMaxBytes = Request::parseIniSizeToBytes((string) ini_get('post_max_size'));
        if ($postMaxBytes === 0) {
            $this->markTestSkipped('post_max_size is unlimited in this environment.');
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = (string) ($postMaxBytes + 1);

        $this->assertTrue(Request::isPostTooLarge());
    }

    public function testIsPostTooLargeReturnsFalseForASmallJsonPostEvenThoughPostAndFilesAreEmpty(): void
    {
        // A JSON body (e.g. the "Générer avec l'IA" AJAX endpoints) never
        // populates $_POST/$_FILES regardless of size — must not be
        // mistaken for a truncated-by-post_max_size request.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_LENGTH'] = '120';
        $_POST = [];
        $_FILES = [];

        $this->assertFalse(Request::isPostTooLarge());
    }

    public function testIsPostTooLargeReturnsFalseWhenNotAPostRequest(): void
    {
        $postMaxBytes = Request::parseIniSizeToBytes((string) ini_get('post_max_size'));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_LENGTH'] = (string) ($postMaxBytes + 1024);

        $this->assertFalse(Request::isPostTooLarge());
    }

    public function testIsPostTooLargeReturnsFalseWhenContentLengthIsMissing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['CONTENT_LENGTH']);

        $this->assertFalse(Request::isPostTooLarge());
    }

    public function testGetFilesNormalizesMultiFileFieldIntoPerFileEntries(): void
    {
        $_FILES['receipts'] = [
            'name' => ['a.pdf', 'b.pdf'],
            'tmp_name' => ['/tmp/php1', '/tmp/php2'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [100, 200],
            'type' => ['application/pdf', 'application/pdf'],
        ];
        $request = new Request('POST', '/', [], [], [], []);

        $files = $request->getFiles('receipts');

        $this->assertCount(2, $files);
        $this->assertSame('a.pdf', $files[0]['name']);
        $this->assertSame('/tmp/php1', $files[0]['tmp_name']);
        $this->assertSame(100, $files[0]['size']);
        $this->assertSame('b.pdf', $files[1]['name']);
    }

    public function testGetFilesReturnsEmptyArrayWhenFieldIsMissing(): void
    {
        $request = new Request('POST', '/', [], [], [], []);

        $this->assertSame([], $request->getFiles('receipts'));
    }

    public function testGetFilesReturnsEmptyArrayForASingleFileField(): void
    {
        // A non-multiple <input type="file"> produces a flat entry
        // (name is a string, not an array) — getFile() is the right
        // accessor for that shape, not getFiles().
        $_FILES['receipt'] = [
            'name' => 'a.pdf',
            'tmp_name' => '/tmp/php1',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
            'type' => 'application/pdf',
        ];
        $request = new Request('POST', '/', [], [], [], []);

        $this->assertSame([], $request->getFiles('receipt'));
    }
}
