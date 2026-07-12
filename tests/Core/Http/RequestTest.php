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
}
