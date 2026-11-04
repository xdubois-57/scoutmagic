<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Provider\VisionProbeChallenge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Modules\LlmConnector\StubHttpServer;

class ScalewayVisionProbeTest extends TestCase
{
    public static function replies(): iterable
    {
        yield 'correct image answer' => [200, '{"choices":[{"message":{"content":"12345678"}}]}', ModelProbeStatus::VISION_VERIFIED];
        yield 'claiming to see is insufficient' => [200, '{"choices":[{"message":{"content":"I can see images"}}]}', ModelProbeStatus::UNVERIFIED];
        yield 'wrong digits' => [200, '{"choices":[{"message":{"content":"87654321"}}]}', ModelProbeStatus::UNVERIFIED];
        yield 'truncated correct answer' => [200, '{"choices":[{"message":{"content":"12345678"},"finish_reason":"length"}]}', ModelProbeStatus::UNVERIFIED];
        yield 'actual observed endpoint refusal' => [422, '{"error":{"type":"route_not_supported","message":"Private echoed payload"}}', ModelProbeStatus::CHAT_UNSUPPORTED];
        yield 'temporary API error' => [503, '{"error":{"message":"Private echoed payload"}}', ModelProbeStatus::UNVERIFIED];
        yield 'rate limit' => [429, '{}', ModelProbeStatus::UNVERIFIED];
        yield 'malformed response' => [200, 'not JSON', ModelProbeStatus::UNVERIFIED];
    }

    #[DataProvider('replies')]
    public function testTheProbeRequiresTheImageAnswerAndBoundsItsWireRequest(int $httpStatus, string $reply, ModelProbeStatus $expected): void
    {
        $server = StubHttpServer::start([['status' => $httpStatus, 'body' => $reply]]);
        try {
            $challenge = VisionProbeChallenge::forDigits('12345678');
            $driver = new ScalewayProvider($server->baseUrl(), 'test-key');
            $result = $driver->probeVision('mistral-small-3.2-24b-instruct-2506', $challenge);
            $request = $server->lastRequest();
            $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([
                $expected, 'POST', '/v1/chat/completions', 32, 'mistral-small-3.2-24b-instruct-2506', [[
                    'role' => 'user', 'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . $challenge->imageBase64]],
                        ['type' => 'text', 'text' => 'Read the eight decimal digits from the image. Output only those digits.'],
                    ],
                ]],
            ], [$result, $request['method'], $request['uri'], $body['max_tokens'], $body['model'], $body['messages']]);
        } finally {
            $server->stop();
        }
    }

    public function testAnEmbeddingModelIsNeverProbed(): void
    {
        $server = StubHttpServer::start([['body' => '{}']]);
        try {
            $status = (new ScalewayProvider($server->baseUrl(), 'test-key'))->probeVision(
                'bge-multilingual-gemma2', VisionProbeChallenge::forDigits('12345678')
            );
            self::assertSame([ModelProbeStatus::CHAT_UNSUPPORTED, []], [$status, $server->requests()]);
        } finally {
            $server->stop();
        }
    }

    public function testTheSyntheticChallengeIsARealPngWithNoTextMetadata(): void
    {
        $probe = VisionProbeChallenge::create();
        $png = base64_decode($probe->imageBase64, true);
        $size = getimagesizefromstring($png);
        $chunks = [];
        for ($offset = 8; $offset < strlen($png);) {
            $length = unpack('Nlength', substr($png, $offset, 4))['length'];
            $chunks[] = substr($png, $offset + 4, 4);
            $offset += 12 + $length;
        }
        self::assertSame([8, 352, 112, IMAGETYPE_PNG, ['IHDR', 'IDAT', 'IEND']], [
            strlen($probe->answer), $size[0], $size[1], $size[2], $chunks,
        ]);
    }

    public static function cacheCases(): iterable
    {
        yield 'fresh success' => ['vision_verified', 604799, 'scope', ModelProbeStatus::VISION_VERIFIED, false];
        yield 'expired success' => ['vision_verified', 604800, 'scope', null, true];
        yield 'fresh failure' => ['unverified', 3599, 'scope', ModelProbeStatus::UNVERIFIED, false];
        yield 'retry after an hour' => ['unverified', 3600, 'scope', null, true];
        yield 'negative route retained' => ['chat_unsupported', 604799, 'scope', ModelProbeStatus::CHAT_UNSUPPORTED, false];
        yield 'negative route stays refused while awaiting a new probe' => ['chat_unsupported', 604800, 'scope', ModelProbeStatus::CHAT_UNSUPPORTED, true];
        yield 'changed endpoint or key' => ['vision_verified', 0, 'other-scope', null, true];
        yield 'future evidence' => ['vision_verified', -1, 'scope', null, true];
        yield 'unknown status' => ['claimed', 0, 'scope', null, true];
    }

    #[DataProvider('cacheCases')]
    public function testEvidenceHasATtlAndIsBoundToTheConfiguration(string $status, int $age, string $scope, ?ModelProbeStatus $expected, bool $needsProbe): void
    {
        $cache = new ModelProbeCache([
            'version' => 1, 'scope' => $scope,
            'results' => ['model' => ['status' => $status, 'checked_at' => 1800000000 - $age]],
        ], 'scope', 1800000000);
        self::assertSame([$expected, $needsProbe], [$cache->resultFor('model'), $cache->needsProbe('model')]);
    }

    public function testRecordingEvidenceCanBeReadAfterPersistence(): void
    {
        $cache = new ModelProbeCache([], 'scope', 1800000000);
        $cache->record('model', ModelProbeStatus::VISION_VERIFIED);
        $restored = new ModelProbeCache(json_decode(json_encode($cache->toArray(), JSON_THROW_ON_ERROR), true), 'scope', 1800000010);
        self::assertSame(ModelProbeStatus::VISION_VERIFIED, $restored->resultFor('model'));
    }

    public function testATemporaryFailureCannotEraseAnEstablishedRouteRefusal(): void
    {
        $cache = new ModelProbeCache([], 'scope', 1800000000);
        $cache->record('model', ModelProbeStatus::CHAT_UNSUPPORTED);
        $cache->record('model', ModelProbeStatus::UNVERIFIED);
        self::assertSame(ModelProbeStatus::CHAT_UNSUPPORTED, $cache->resultFor('model'));
        $cache->record('model', ModelProbeStatus::VISION_VERIFIED);
        self::assertSame(ModelProbeStatus::VISION_VERIFIED, $cache->resultFor('model'));
    }
}
