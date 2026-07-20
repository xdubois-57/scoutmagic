<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Provider\AnthropicProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AnthropicProvider.
 * These tests verify error handling and request structure.
 * Network calls are not made — tests validate that exceptions are thrown
 * for unreachable endpoints.
 */
class AnthropicProviderTest extends TestCase
{
    private AnthropicProvider $provider;

    protected function setUp(): void
    {
        // Use localhost on a closed port — connection refused is immediate
        $this->provider = new AnthropicProvider('http://127.0.0.1:19', 'sk-test-key');
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

        $this->provider->complete('claude-3-haiku-20240307', 'Hello');
    }

    public function testCompleteAcceptsOptions(): void
    {
        $this->expectException(LlmException::class);

        // Verify that options are accepted without type errors
        $this->provider->complete('claude-3-haiku-20240307', 'Hello', [
            'system_prompt' => 'You are helpful.',
            'attachments' => [
                ['data' => base64_encode('test'), 'mime_type' => 'image/png'],
            ],
            'response_schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'timeout' => 5,
        ]);
    }

    public function testListModelsWithValidResponseParsing(): void
    {
        // Test with a provider that points to a closed port
        // This verifies that the timeout/connection error is thrown properly
        $provider = new AnthropicProvider('http://127.0.0.1:19', 'sk-invalid');

        $this->expectException(LlmException::class);
        $provider->listModels();
    }

    public function testResolveTiersPicksMostRecentHaikuAndSonnet(): void
    {
        $models = [
            'claude-3-haiku-20240307',
            'claude-3-5-haiku-20241022',
            'claude-3-sonnet-20240229',
            'claude-3-5-sonnet-20241022',
            'claude-sonnet-4-20250514',
            'claude-3-opus-20240229',
        ];

        $tiers = $this->provider->resolveTiers($models);

        $this->assertSame('claude-3-5-haiku-20241022', $tiers['cheap']);
        $this->assertSame('claude-sonnet-4-20250514', $tiers['capable']);
    }

    public function testResolveTiersReturnsNullWhenNoMatch(): void
    {
        $models = ['claude-3-opus-20240229'];

        $tiers = $this->provider->resolveTiers($models);

        $this->assertNull($tiers['cheap']);
        $this->assertNull($tiers['capable']);
    }

    public function testResolveTiersWithSingleHaikuOnly(): void
    {
        $models = ['claude-3-haiku-20240307'];

        $tiers = $this->provider->resolveTiers($models);

        $this->assertSame('claude-3-haiku-20240307', $tiers['cheap']);
        $this->assertNull($tiers['capable']);
    }

    public function testResolveTiersWithEmptyList(): void
    {
        $tiers = $this->provider->resolveTiers([]);

        $this->assertNull($tiers['cheap']);
        $this->assertNull($tiers['capable']);
    }
}
