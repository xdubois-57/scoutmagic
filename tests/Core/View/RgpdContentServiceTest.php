<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Config\SettingService;
use Core\Module\ModuleManager;
use Core\View\RgpdContentService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;

class RgpdContentServiceTest extends TestCase
{
    private ModuleManager $moduleManager;
    private SettingService $settingService;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->moduleManager->method('getEnabledModuleIds')->willReturn([]);

        $this->settingService = $this->createMock(SettingService::class);
        $this->settingService->method('get')->willReturn('');
    }

    public function testGenerateWithAiRequestsAHighMaxTokensForTheLongFormDocument(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->once())->method('complete')
            ->with($this->callback(fn(LlmRequest $request) => $request->maxTokens === 8192))
            ->willReturn(new LlmResponse(content: '<h2>Titre</h2><p>Contenu</p>', parsed: null, inputTokens: 10, outputTokens: 20, truncated: false));

        $service = new RgpdContentService($this->moduleManager, $this->settingService, $llmConnector);

        $result = $service->generateWithAi('Instructions');

        $this->assertStringContainsString('Contenu', $result);
    }

    public function testGenerateWithAiThrowsWhenTheResponseWasTruncated(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(
            new LlmResponse(content: '<h2>Titre</h2><p>Contenu incompl', parsed: null, inputTokens: 10, outputTokens: 8192, truncated: true)
        );

        $service = new RgpdContentService($this->moduleManager, $this->settingService, $llmConnector);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tronquée/');
        $service->generateWithAi('Instructions');
    }

    public function testGenerateWithAiThrowsWhenNoConnectorIsConfigured(): void
    {
        $service = new RgpdContentService($this->moduleManager, $this->settingService, null);

        $this->expectException(\RuntimeException::class);
        $service->generateWithAi('Instructions');
    }
}
