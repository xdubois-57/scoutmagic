<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Diagnostics;

use Core\Storage\Location\Diagnostics\ObjectStorageErrorExplainer;
use Core\Storage\Location\StorageLocationException;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;

class ObjectStorageErrorExplainerTest extends TestCase
{
    public function testIsAvailableIsFalseWithoutAConnector(): void
    {
        $service = new ObjectStorageErrorExplainer();

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReflectsTheConnectorsOwnAvailability(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(false);
        $service = new ObjectStorageErrorExplainer($llmConnector);

        $this->assertFalse($service->isAvailable());
    }

    public function testExplainThrowsWhenUnavailable(): void
    {
        $service = new ObjectStorageErrorExplainer();

        $this->expectException(StorageLocationException::class);
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
        $service = new ObjectStorageErrorExplainer($llmConnector);

        $result = $service->explain(
            'scaleway', 'https://scoutmagic.s3.fr-par.scw.cloud', 'fr-par', 'scoutmagic', 'AK123', 15, '403 Forbidden'
        );

        $this->assertSame('Diagnostic.', $result);
    }

    public function testExplainWrapsAnLlmFailureAsAStorageLocationException(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider timeout.'));
        $service = new ObjectStorageErrorExplainer($llmConnector);

        $this->expectException(StorageLocationException::class);
        $service->explain('custom', 'https://example.com', '', 'bucket', 'AK', 10, 'error');
    }

    /**
     * One third party's error text explaining another's: this used to append
     * the AI provider's own HTTP body onto the gallery configuration page.
     */
    public function testTheLlmFailuresTextNeverReachesTheStorageLocationExceptionsOwnMessage(): void
    {
        $technical = new LlmException('HTTP 429 — {"error":{"message":"rate_limit_exceeded for org-abc"}}');
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException($technical);
        $service = new ObjectStorageErrorExplainer($llmConnector);

        try {
            $service->explain('custom', 'https://example.com', '', 'bucket', 'AK', 10, 'error');
            self::fail('Expected a StorageLocationException.');
        } catch (StorageLocationException $e) {
            self::assertStringNotContainsString('rate_limit_exceeded', $e->getMessage());
            self::assertStringNotContainsString('HTTP 429', $e->getMessage());
            self::assertSame($technical, $e->getPrevious());
        }
    }
}
