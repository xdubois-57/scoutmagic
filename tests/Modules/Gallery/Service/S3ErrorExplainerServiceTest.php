<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\S3ErrorExplainerService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;

class S3ErrorExplainerServiceTest extends TestCase
{
    public function testIsAvailableIsFalseWithoutAConnector(): void
    {
        $service = new S3ErrorExplainerService();

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReflectsTheConnectorsOwnAvailability(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(false);
        $service = new S3ErrorExplainerService($llmConnector);

        $this->assertFalse($service->isAvailable());
    }

    public function testExplainThrowsWhenUnavailable(): void
    {
        $service = new S3ErrorExplainerService();

        $this->expectException(GalleryException::class);
        $service->explain('scaleway', 'https://s3.fr-par.scw.cloud', 'fr-par', 'bucket', 'AK123', 18, '403 Forbidden');
    }

    public function testExplainPassesTheSecretKeyLengthOnlyNeverASecretValue(): void
    {
        // explain()'s signature has no $secretKey parameter at all — only
        // $secretKeyLength — so there is no code path through which the
        // actual secret could reach the prompt; this asserts the length
        // note is what the model actually receives in its place.
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->once())->method('complete')->with($this->callback(function ($request) {
            $this->assertStringContainsString('longueur : 15', $request->prompt);
            $this->assertStringContainsString('scaleway', $request->prompt);
            return true;
        }))->willReturn(new LlmResponse('Diagnostic.', null, 5, 5));
        $service = new S3ErrorExplainerService($llmConnector);

        $result = $service->explain(
            'scaleway', 'https://scoutmagic.s3.fr-par.scw.cloud', 'fr-par', 'scoutmagic', 'AK123', 15, '403 Forbidden'
        );

        $this->assertSame('Diagnostic.', $result);
    }

    public function testExplainWrapsAnLlmFailureAsAGalleryException(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider timeout.'));
        $service = new S3ErrorExplainerService($llmConnector);

        $this->expectException(GalleryException::class);
        $service->explain('custom', 'https://example.com', '', 'bucket', 'AK', 10, 'error');
    }
}
