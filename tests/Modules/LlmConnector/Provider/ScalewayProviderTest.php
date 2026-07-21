<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Provider\ScalewayProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScalewayProvider.
 */
class ScalewayProviderTest extends TestCase
{
    private ScalewayProvider $provider;

    protected function setUp(): void
    {
        // Use localhost on a closed port — connection refused is immediate
        $this->provider = new ScalewayProvider('http://127.0.0.1:19', 'sk-test-key');
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

        $this->provider->complete('pixtral-12b-2409', 'Hello');
    }

    public function testCompleteAcceptsOptions(): void
    {
        $this->expectException(LlmException::class);

        $this->provider->complete('pixtral-12b-2409', 'Hello', [
            'system_prompt' => 'You are helpful.',
            'attachments' => [
                ['data' => base64_encode('test'), 'mime_type' => 'image/png'],
            ],
            'response_schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'timeout' => 5,
        ]);
    }

    public function testBuildUserContentReturnsPlainStringWithoutAttachments(): void
    {
        $method = new \ReflectionMethod(ScalewayProvider::class, 'buildUserContent');
        $method->setAccessible(true);

        $content = $method->invoke($this->provider, 'Hello', []);

        $this->assertSame('Hello', $content);
    }

    public function testBuildUserContentBuildsImageUrlBlockForImageAttachment(): void
    {
        $method = new \ReflectionMethod(ScalewayProvider::class, 'buildUserContent');
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
        $method = new \ReflectionMethod(ScalewayProvider::class, 'buildUserContent');
        $method->setAccessible(true);

        $content = $method->invoke($this->provider, 'Hello', [
            ['data' => base64_encode('pdf-bytes'), 'mime_type' => 'application/pdf'],
        ]);

        $this->assertSame('Hello', $content);
    }

    public function testResolveTiersWithEmptyList(): void
    {
        $tiers = $this->provider->resolveTiers([]);

        $this->assertNull($tiers['cheap']);
        $this->assertNull($tiers['capable']);
        $this->assertNull($tiers['ocr']);
    }
}
