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

    /** Where a redirect points, in the tests that need one. */
    private ?StubHttpServer $elsewhere = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        $this->elsewhere?->stop();
        $this->elsewhere = null;
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

    /**
     * The provider's explanation is still carried — on $detail, for the
     * journal — and deliberately NOT in the message, which is rendered to a
     * chief. Api\LlmException is a Core\Exception\UserFacingException, so
     * whatever is in its message reaches a page verbatim.
     */
    public function testCarriesTheProviderExplanationAsADetailAndNotInTheMessage(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 400, 'body' => '{"message":"model not found: bogus-model"}'],
        ]);

        try {
            (new HttpTransport())->postJson($this->server->baseUrl() . '/x', [], ['a' => 1], 5);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertStringContainsString('model not found: bogus-model', (string) $e->detail);
            self::assertStringNotContainsString('model not found', $e->getMessage());
            self::assertStringContainsString('HTTP 400', $e->getMessage());
        }
    }

    public function testMapsTooManyRequestsToTheRateLimitedCode(): void
    {
        $this->server = StubHttpServer::start([['status' => 429, 'body' => '{"message":"slow down"}']]);

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::RATE_LIMITED);

        (new HttpTransport())->postJson($this->server->baseUrl() . '/x', [], ['a' => 1], 5);
    }

    /**
     * 429 has the same rule as any other non-2xx: the provider's body is a
     * $detail, never part of the sentence a member sees.
     */
    public function testTheRateLimitBodyIsADetailAndNotPartOfTheMessage(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 429, 'body' => '{"message":"rate_limit_exceeded for org-abc, retry in 12s"}'],
        ]);

        try {
            (new HttpTransport())->postJson($this->server->baseUrl() . '/x', [], ['a' => 1], 5);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertSame(LlmException::RATE_LIMITED, $e->getCode());
            self::assertStringContainsString('org-abc', (string) $e->detail);
            self::assertStringNotContainsString('org-abc', $e->getMessage());
            self::assertStringNotContainsString('rate_limit_exceeded', $e->getMessage());
        }
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
            self::assertLessThan(700, strlen((string) $e->detail));
        }
    }

    public function testStillRejectsA200WhoseBodyIsNotJson(): void
    {
        $this->server = StubHttpServer::start([['status' => 200, 'body' => 'not json at all']]);

        try {
            (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 5);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertStringContainsString('Invalid JSON', (string) $e->detail);
            self::assertStringNotContainsString('Invalid JSON', $e->getMessage());
        }
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
        $this->expectExceptionCode(LlmException::INVALID_REQUEST);
        $this->expectExceptionMessageMatches('/caractères illisibles/');

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

    /**
     * **A redirect is not followed, so the key never reaches its target**
     * (#1269, SECURITY.md §17). The stream wrapper follows `Location` by
     * default and sends the request's own headers along — the provider's
     * API key among them. A second server stands where the redirect
     * points: it must receive nothing at all.
     */
    public function testARedirectIsNotFollowedAndTheKeyNeverReachesItsTarget(): void
    {
        $this->elsewhere = StubHttpServer::start([['status' => 200, 'body' => '{"ok":true}']]);
        $target = $this->elsewhere->baseUrl() . '/collect';
        $this->server = StubHttpServer::start([
            ['status' => 302, 'body' => '', 'headers' => ['Location: ' . $target]],
        ]);

        try {
            (new HttpTransport())->postJson(
                $this->server->baseUrl() . '/v1/messages',
                ['x-api-key: sk-secret-key'],
                ['a' => 1],
                5
            );
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertSame(LlmException::API_ERROR, $e->getCode());
            self::assertStringContainsString('redirection', $e->getMessage());
            self::assertStringContainsString('HTTP 302', $e->getMessage());
            // The target is named nowhere: not to the reader, not to the journal.
            self::assertStringNotContainsString($target, $e->getMessage());
            self::assertStringNotContainsString($target, (string) $e->detail);
        }

        self::assertSame([], $this->elsewhere->requests(), 'the redirect target was contacted');
        self::assertSame('sk-secret-key', $this->server->lastRequest()['headers']['HTTP_X_API_KEY'] ?? null);
    }

    /** The same for a GET — the model catalogue carries the key just as well. */
    public function testAPermanentRedirectOnAGetIsNotFollowedEither(): void
    {
        $this->elsewhere = StubHttpServer::start([['status' => 200, 'body' => '{"data":[]}']]);
        $this->server = StubHttpServer::start([
            ['status' => 308, 'body' => '', 'headers' => ['Location: ' . $this->elsewhere->baseUrl() . '/v1/models']],
        ]);

        try {
            (new HttpTransport())->getJson($this->server->baseUrl() . '/v1/models', ['Authorization: Bearer k'], 5);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertStringContainsString('HTTP 308', $e->getMessage());
        }

        self::assertSame([], $this->elsewhere->requests());
    }

    /**
     * **A response past the ceiling is refused, not buffered whole** (#1269):
     * the body is read up to one byte beyond it, and that byte is enough to
     * say no.
     */
    public function testAResponseBeyondTheCeilingIsRefused(): void
    {
        $this->server = StubHttpServer::start([
            ['status' => 200, 'body' => '{"a":"' . str_repeat('x', HttpTransport::MAX_RESPONSE_BYTES) . '"}'],
        ]);

        try {
            (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 20);
            self::fail('Expected an LlmException.');
        } catch (LlmException $e) {
            self::assertSame(LlmException::API_ERROR, $e->getCode());
            self::assertStringContainsString('trop volumineuse', $e->getMessage());
        }
    }

    /** And a response exactly at the ceiling is still read and decoded. */
    public function testAResponseExactlyAtTheCeilingIsStillRead(): void
    {
        $padding = HttpTransport::MAX_RESPONSE_BYTES - strlen('{"a":""}');
        $this->server = StubHttpServer::start([
            ['status' => 200, 'body' => '{"a":"' . str_repeat('x', $padding) . '"}'],
        ]);

        $decoded = (new HttpTransport())->getJson($this->server->baseUrl() . '/x', [], 20);

        self::assertSame($padding, strlen((string) $decoded['a']));
    }
}
