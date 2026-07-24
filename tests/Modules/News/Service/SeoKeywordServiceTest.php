<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\News\Service\NewsException;
use Modules\News\Service\SeoKeywordService;
use PHPUnit\Framework\TestCase;

class SeoKeywordServiceTest extends TestCase
{
    public function testIsAvailableIsFalseWhenNoConnector(): void
    {
        $service = new SeoKeywordService(null);
        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReflectsConnectorAvailability(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);

        $service = new SeoKeywordService($connector);
        $this->assertTrue($service->isAvailable());
    }

    public function testGenerateKeywordsThrowsWhenUnavailable(): void
    {
        $service = new SeoKeywordService(null);

        $this->expectException(NewsException::class);
        $service->generateKeywords('Titre', '<p>Corps</p>');
    }

    public function testGenerateKeywordsReturnsTrimmedContent(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willReturn(new LlmResponse(" camp, ete, scoutisme \n", null, 10, 5));

        $service = new SeoKeywordService($connector);

        $this->assertSame('camp, ete, scoutisme', $service->generateKeywords('Titre', '<p>Corps</p>'));
    }

    public function testGenerateKeywordsWrapsLlmExceptionIntoNewsException(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willThrowException(new LlmException('API down'));

        $service = new SeoKeywordService($connector);

        $this->expectException(NewsException::class);
        $service->generateKeywords('Titre', '<p>Corps</p>');
    }
}
