<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Provider\Ovh;

use Modules\SosStaff\Provider\Ovh\OvhApiClient;
use Modules\SosStaff\Provider\Ovh\OvhApiException;
use PHPUnit\Framework\TestCase;

class OvhApiClientTest extends TestCase
{
    /**
     * A fake transport that answers GET /auth/time with a fixed server
     * time — since the client's signing timestamp is `time() + delta` and
     * delta is computed as `serverTime - time()`, this makes every
     * subsequent signed request's timestamp deterministically "1000000000"
     * regardless of the actual wall-clock time, so the resulting signature
     * can be hand-derived and asserted exactly.
     *
     * @param array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> $captured
     */
    private function fakeTransport(array &$captured, string $responseBody, int $status = 200): \Closure
    {
        return function (string $method, string $url, array $headers, ?string $body) use (&$captured, $responseBody, $status): array {
            if (str_ends_with($url, '/auth/time')) {
                return ['status' => 200, 'body' => '1000000000'];
            }
            $captured[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return ['status' => $status, 'body' => $responseBody];
        };
    }

    public function testGetSignsRequestWithExpectedSignature(): void
    {
        $captured = [];
        $client = new OvhApiClient(
            applicationKey: 'testappkey',
            applicationSecret: 'testsecret',
            consumerKey: 'testconsumerkey',
            transport: $this->fakeTransport($captured, '["ba-1"]')
        );

        $result = $client->get('/telephony');

        $this->assertSame(['ba-1'], $result);
        $this->assertCount(1, $captured);
        $this->assertSame('GET', $captured[0]['method']);
        $this->assertSame('https://eu.api.ovh.com/1.0/telephony', $captured[0]['url']);
        $this->assertSame('testappkey', $captured[0]['headers']['X-Ovh-Application']);
        $this->assertSame('testconsumerkey', $captured[0]['headers']['X-Ovh-Consumer']);
        $this->assertSame('1000000000', $captured[0]['headers']['X-Ovh-Timestamp']);
        $this->assertSame(
            '$1$91ca36656ca3a489bec505204190d704ef0a429b',
            $captured[0]['headers']['X-Ovh-Signature']
        );
    }

    public function testPutSignsRequestBodyIntoSignature(): void
    {
        $captured = [];
        $client = new OvhApiClient(
            applicationKey: 'testappkey',
            applicationSecret: 'testsecret',
            consumerKey: 'testconsumerkey',
            transport: $this->fakeTransport($captured, '{}')
        );

        $client->put('/telephony/ba-123/line/0033100000000/options', [
            'forwardUnconditional' => true,
            'forwardUnconditionalNumber' => '+32470000000',
        ]);

        $this->assertCount(1, $captured);
        $this->assertSame('PUT', $captured[0]['method']);
        $this->assertSame(
            '{"forwardUnconditional":true,"forwardUnconditionalNumber":"+32470000000"}',
            $captured[0]['body']
        );
        $this->assertSame(
            '$1$f3fda39382bb53e3ac31e52907dcbefcd100148b',
            $captured[0]['headers']['X-Ovh-Signature']
        );
    }

    public function testGetThrowsWithoutConsumerKey(): void
    {
        $client = new OvhApiClient(
            applicationKey: 'testappkey',
            applicationSecret: 'testsecret',
            consumerKey: null,
            transport: fn() => ['status' => 200, 'body' => '{}']
        );

        $this->expectException(OvhApiException::class);
        $client->get('/telephony');
    }

    public function testHttpErrorStatusThrowsWithOvhMessage(): void
    {
        $captured = [];
        $client = new OvhApiClient(
            applicationKey: 'testappkey',
            applicationSecret: 'testsecret',
            consumerKey: 'testconsumerkey',
            transport: $this->fakeTransport($captured, '{"message":"Invalid signature"}', 401)
        );

        $this->expectException(OvhApiException::class);
        $this->expectExceptionMessage('Invalid signature');
        $client->get('/telephony');
    }

    public function testRequestConsumerKeyDoesNotRequireExistingConsumerKey(): void
    {
        $client = new OvhApiClient(
            applicationKey: 'testappkey',
            applicationSecret: 'testsecret',
            consumerKey: null,
            transport: function (string $method, string $url, array $headers, ?string $body): array {
                $this->assertSame('POST', $method);
                $this->assertSame('https://eu.api.ovh.com/1.0/auth/credential', $url);
                $this->assertSame('testappkey', $headers['X-Ovh-Application']);
                $this->assertArrayNotHasKey('X-Ovh-Signature', $headers);
                $decoded = json_decode((string) $body, true);
                $this->assertSame([['method' => 'GET', 'path' => '/telephony*']], $decoded['accessRules']);
                return ['status' => 200, 'body' => '{"consumerKey":"newck","validationUrl":"https://ovh.example/auth/newck"}'];
            }
        );

        $result = $client->requestConsumerKey([['method' => 'GET', 'path' => '/telephony*']]);

        $this->assertSame('newck', $result['consumerKey']);
        $this->assertSame('https://ovh.example/auth/newck', $result['validationUrl']);
    }

    public function testGetPropagatesEmptyBodyIntoSignature(): void
    {
        $captured = [];
        $client = new OvhApiClient(
            applicationKey: 'a',
            applicationSecret: 'b',
            consumerKey: 'c',
            transport: $this->fakeTransport($captured, '{}')
        );

        $client->get('/telephony');

        $this->assertNull($captured[0]['body']);
    }
}
