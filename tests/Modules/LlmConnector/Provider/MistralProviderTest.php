<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Provider\MistralProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MistralProvider.
 */
class MistralProviderTest extends TestCase
{
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

    public function testResolveTiersPicksMostRecentSmallAndLarge(): void
    {
        $models = [
            'mistral-small-2402',
            'mistral-small-latest',
            'mistral-large-2411',
            'mistral-large-latest',
            'mistral-medium-latest',
            'mistral-embed',
            'codestral-latest',
        ];

        $tiers = $this->provider->resolveTiers($models);

        // "latest" is treated as most recent → extractDate returns '99999999'
        // "2402" → '24020000', "2411" → '24110000'
        $this->assertSame('mistral-small-latest', $tiers['cheap']);
        $this->assertSame('mistral-large-latest', $tiers['capable']);
    }

    public function testResolveTiersReturnsNullWhenNoMatch(): void
    {
        $models = ['mistral-embed', 'codestral-latest'];

        $tiers = $this->provider->resolveTiers($models);

        $this->assertNull($tiers['cheap']);
        $this->assertNull($tiers['capable']);
    }

    public function testResolveTiersWithEmptyList(): void
    {
        $tiers = $this->provider->resolveTiers([]);

        $this->assertNull($tiers['cheap']);
        $this->assertNull($tiers['capable']);
    }

    public function testResolveTiersMediumCountsAsCapable(): void
    {
        $models = ['mistral-medium-20250514'];

        $tiers = $this->provider->resolveTiers($models);

        $this->assertNull($tiers['cheap']);
        $this->assertSame('mistral-medium-20250514', $tiers['capable']);
    }
}
