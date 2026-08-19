<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Provider\HttpTransport;
use PHPUnit\Framework\TestCase;
use Tests\Modules\LlmConnector\StubHttpServer;

/**
 * The shared transport is where "the call failed" is decided, so these tests
 * drive a real HTTP server rather than a mock — the whole point is the
 * status line, which only a real response carries.
 */
class HttpTransportTest extends TestCase
{
    private ?StubHttpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testDecodesASuccessfulJsonResponse(): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => '{"ok":true,"n":3}']]);

        $decoded = (new HttpTransport())->getJson($this->server->baseUrl() . '/x', ['X-Test: 1'], 5);

        self::assertSame(['ok' => true, 'n' => 3], $decoded);
    }

    /**
     * The regression this whole class exists for: a non-2xx whose body is not
     * shaped as {"error": {...}} used to sail through every driver's error
     * check and surface as a successful, empty completion.
     */
    public function testRejectsANonSuccessStatusEvenWhenTheBodyIsNotAnErrorObject(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 401, 'body' => '{"message":"Unauthorized","request_id":"abc123"}'],
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::API_ERROR);
        $this->expectExceptionMessageMatches('/HTTP 401/');

        (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 5);
    }

    public function testCarriesTheProviderExplanationIntoTheExceptionMessage(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 400, 'body' => '{"message":"model not found: bogus-model"}'],
        ]);

        try {
            (new HttpTransport())->postJson($this->server->baseUrl() . '/x', [], ['a' => 1], 5);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertStringContainsString('model not found: bogus-model', $e->getMessage());
        }
    }

    public function testMapsTooManyRequestsToTheRateLimitedCode(): void
    {
        $this->server = StubHttpServer::start([['status' => 429, 'body' => '{"message":"slow down"}']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::RATE_LIMITED);

        (new HttpTransport())->postJson($this->server->baseUrl() . '/x', [], ['a' => 1], 5);
    }

    public function testRejectsAServerErrorThatIsNotEvenJson(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 502, 'body' => '<html><body>Bad Gateway</body></html>', 'headers' => ['Content-Type: text/html']],
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/HTTP 502/');

        (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 5);
    }

    public function testTruncatesAVeryLongErrorBody(): void
    {
        $this->server = StubHttpServer::start([['status' => 500, 'body' => str_repeat('x', 5000)]]);

        try {
            (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 5);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertLessThan(700, strlen($e->getMessage()));
        }
    }

    public function testStillRejectsA200WhoseBodyIsNotJson(): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => 'not json at all']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');

        (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 5);
    }

    /**
     * Invalid UTF-8 anywhere in the payload makes json_encode() return false;
     * passing that to strlen() under strict_types raised a TypeError, which
     * is not an LlmException and so escaped every consumer's catch block.
     */
    public function testTurnsAnUnencodablePayloadIntoAnLlmException(): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => '{}']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/Encodage JSON/');

        (new HttpTransport())->postJson(
            $this->server->baseUrl() . '/x',
            [],
            ['prompt' => "Caf\xE9 du coin"], // "Café" as a Latin-1 bank export writes it
            5
        );
    }

    public function testSendsTheBodyWithAMatchingContentLength(): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => '{"ok":true}']]);

        (new HttpTransport())->postJson(
            $this->server->baseUrl() . '/x',
            ['Authorization: Bearer k'],
            ['prompt' => 'Bonjour à toutes et à tous'],
            5
        );

        $request = $this->server->lastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame(strlen($request['body']), (int) $request['headers']['CONTENT_LENGTH']);
        self::assertSame('Bearer k', $request['headers']['HTTP_AUTHORIZATION']);
        self::assertStringContainsString('Bonjour à toutes', $request['body']);
    }

    public function testReportsAnUnreachableEndpointAsATimeout(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::TIMEOUT);

        (new HttpTransport())->getJson('http://127.0.0.1:19/x', [], 1);
    }
}
