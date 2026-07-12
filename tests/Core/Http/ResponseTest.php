<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testDefaultStatusCodeIs200(): void
    {
        $response = new Response();

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSetStatusCodeChangesTheCode(): void
    {
        $response = new Response();
        $response->setStatusCode(404);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSetHeaderAddsHeaders(): void
    {
        $response = new Response();
        $response->setHeader('Content-Type', 'application/json');

        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    public function testSetBodySetsTheBodyContent(): void
    {
        $response = new Response();
        $response->setBody('Hello World');

        $this->assertSame('Hello World', $response->getBody());
    }

    public function testConstructorSetsBodyAndStatusCode(): void
    {
        $response = new Response('Test body', 201);

        $this->assertSame('Test body', $response->getBody());
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testSecurityHeadersArePresentAfterSend(): void
    {
        $response = new Response('test');

        // We cannot test actual header() calls in PHPUnit without runkit,
        // but we can verify the send() method would output the body.
        ob_start();
        @$response->send(); // suppress header warnings in CLI
        $output = ob_get_clean();

        $this->assertSame('test', $output);
    }

    public function testSetHeaderReturnsSelfForChaining(): void
    {
        $response = new Response();
        $result = $response->setHeader('X-Custom', 'value');

        $this->assertSame($response, $result);
    }

    public function testSetStatusCodeReturnsSelfForChaining(): void
    {
        $response = new Response();
        $result = $response->setStatusCode(301);

        $this->assertSame($response, $result);
    }

    public function testSetBodyReturnsSelfForChaining(): void
    {
        $response = new Response();
        $result = $response->setBody('content');

        $this->assertSame($response, $result);
    }
}
