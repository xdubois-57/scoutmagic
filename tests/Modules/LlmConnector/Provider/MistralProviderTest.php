<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Provider\MistralProvider;
use PHPUnit\Framework\TestCase;
use Tests\Modules\LlmConnector\StubHttpServer;

/**
 * Unit tests for MistralProvider.
 */
class MistralProviderTest extends TestCase
{
    public function testModelCardsRequireRealChatAndVisionBooleans(): void
    {
        $server = StubHttpServer::start([['body' => json_encode(['data' => [
            ['id' => 'chat-with-images', 'capabilities' => ['completion_chat' => true, 'vision' => true]],
            ['id' => 'chat-text', 'capabilities' => ['completion_chat' => true, 'vision' => false]],
            ['id' => 'chat-unknown-images', 'capabilities' => ['completion_chat' => true, 'vision' => 'true']],
            ['id' => 'embeddings', 'capabilities' => ['completion_chat' => false, 'vision' => false]],
            ['id' => 'vision-small'],
            ['id' => 'malformed', 'capabilities' => ['completion_chat' => 'true', 'vision' => true]],
        ]], JSON_THROW_ON_ERROR)]]);
        try {
            $catalog = (new MistralProvider($server->baseUrl(), 'test-key'))->listModels();
            self::assertSame([
                ['chat-with-images', 'chat-text', 'chat-unknown-images'],
                ['chat-with-images'],
                ['embeddings' => 'chat_endpoint_unsupported', 'vision-small' => 'chat_capability_unverified',
                    'malformed' => 'chat_capability_unverified'],
            ], [$catalog->chatModelIds(), $catalog->visionModelIds(), $catalog->exclusions()]);
        } finally {
            $server->stop();
        }
    }

    private MistralProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MistralProvider('http://127.0.0.1:19', 'sk-test-key');
    }

    public function testListModelsThrowsOnNetworkFailure(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::TIMEOUT);

        $this->provider->listModels();
    }

    public function testCompleteThrowsOnNetworkFailure(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::TIMEOUT);

        $this->provider->complete('mistral-small-latest', 'Hello');
    }

    public function testCompleteAcceptsOptions(): void
    {
        $this->expectException(LlmException::class);

        $this->provider->complete('mistral-small-latest', 'Hello', [
            'system_prompt' => 'You are helpful.',
            'response_schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'timeout' => 5,
        ]);
    }

    public function testBuildUserContentReturnsPlainStringWithoutAttachments(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'buildUserContent');
        $method->setAccessible(true);

        $content = $method->invoke($this->provider, 'Hello', []);

        $this->assertSame('Hello', $content);
    }

    public function testBuildUserContentBuildsImageUrlBlockForImageAttachment(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'buildUserContent');
        $method->setAccessible(true);

        $data = base64_encode('fake-image-bytes');
        $content = $method->invoke($this->provider, 'Extract the data', [
            ['data' => $data, 'mime_type' => 'image/jpeg'],
        ]);

        $this->assertIsArray($content);
        $this->assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $data]], $content[0]);
        $this->assertSame(['type' => 'text', 'text' => 'Extract the data'], $content[1]);
    }

    public function testBuildUserContentSkipsNonImageAttachments(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'buildUserContent');
        $method->setAccessible(true);

        $content = $method->invoke($this->provider, 'Hello', [
            ['data' => base64_encode('pdf-bytes'), 'mime_type' => 'application/pdf'],
        ]);

        $this->assertSame('Hello', $content);
    }

    public function testBuildRequestBodyAlwaysIncludesMaxTokens(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'buildRequestBody');
        $method->setAccessible(true);

        $body = $method->invoke($this->provider, 'model-1', [], []);

        $this->assertSame(4096, $body['max_tokens'], 'a call with no max_tokens option must still send a default — never omitted');
    }

    public function testBuildRequestBodyUsesTheProvidedMaxTokens(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'buildRequestBody');
        $method->setAccessible(true);

        $body = $method->invoke($this->provider, 'model-1', [], ['max_tokens' => 8192]);

        $this->assertSame(8192, $body['max_tokens']);
    }

    public function testParseChoiceDetectsTruncationFromFinishReasonLength(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'parseChoice');
        $method->setAccessible(true);

        [$content, $truncated] = $method->invoke($this->provider, [
            'choices' => [['message' => ['content' => 'Partial text'], 'finish_reason' => 'length']],
        ]);

        $this->assertSame('Partial text', $content);
        $this->assertTrue($truncated);
    }

    public function testParseChoiceIsNotTruncatedOnNormalCompletion(): void
    {
        $method = new \ReflectionMethod(MistralProvider::class, 'parseChoice');
        $method->setAccessible(true);

        [$content, $truncated] = $method->invoke($this->provider, [
            'choices' => [['message' => ['content' => 'Full text'], 'finish_reason' => 'stop']],
        ]);

        $this->assertSame('Full text', $content);
        $this->assertFalse($truncated);
    }
}
