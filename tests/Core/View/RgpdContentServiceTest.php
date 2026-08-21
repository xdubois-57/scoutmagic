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
        $llmConnector->method('isTierAvailable')->willReturn(true);
        $llmConnector->expects($this->once())->method('complete')
            ->with($this->callback(fn(LlmRequest $request) => $request->maxTokens === 8192))
            ->willReturn(new LlmResponse(content: '<h2>Titre</h2><p>Contenu. Unité scoute est responsable du traitement.</p>', parsed: null, inputTokens: 10, outputTokens: 20, truncated: false));

        $service = new RgpdContentService($this->moduleManager, $this->settingService, $llmConnector);

        $result = $service->generateWithAi('Instructions');

        $this->assertStringContainsString('Contenu', $result);
    }

    public function testGenerateWithAiContinuesGenerationWhenTheFirstResponseIsTruncated(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
        $llmConnector->expects($this->exactly(2))->method('complete')
            ->willReturnOnConsecutiveCalls(
                new LlmResponse(content: '<h2>Titre</h2><p>Début du contenu', parsed: null, inputTokens: 10, outputTokens: 8192, truncated: true),
                new LlmResponse(content: ' et fin du contenu. Unité scoute est responsable du traitement.</p>', parsed: null, inputTokens: 10, outputTokens: 50, truncated: false)
            );

        $service = new RgpdContentService($this->moduleManager, $this->settingService, $llmConnector);

        $result = $service->generateWithAi('Instructions');

        $this->assertStringContainsString('Début du contenu et fin du contenu.', $result);
    }

    public function testContinuationRequestAsksToResumeFromThePartialContent(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);

        $capturedContinuationRequest = null;
        $llmConnector->expects($this->exactly(2))->method('complete')
            ->willReturnCallback(function (LlmRequest $request) use (&$capturedContinuationRequest) {
                if ($capturedContinuationRequest === null && str_contains($request->prompt, 'Génère le contenu RGPD complet')) {
                    return new LlmResponse(content: '<h2>Titre</h2><p>Début du contenu', parsed: null, inputTokens: 10, outputTokens: 8192, truncated: true);
                }
                $capturedContinuationRequest = $request;
                return new LlmResponse(content: ' et fin. Unité scoute est responsable du traitement.</p>', parsed: null, inputTokens: 10, outputTokens: 50, truncated: false);
            });

        $service = new RgpdContentService($this->moduleManager, $this->settingService, $llmConnector);
        $service->generateWithAi('Instructions');

        $this->assertNotNull($capturedContinuationRequest);
        $this->assertStringContainsString('Début du contenu', $capturedContinuationRequest->prompt);
        $this->assertStringContainsString('Continue directement', $capturedContinuationRequest->prompt);
    }

    public function testGenerateWithAiThrowsAfterExhaustingAllContinuations(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
        // MAX_CONTINUATIONS = 2 → 1 initial call + 2 continuations = 3 total.
        $llmConnector->expects($this->exactly(3))->method('complete')->willReturn(
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

    public function testGenerateWithAiThrowsAndDoesNotReturnContentWhenNoControllerIsDesignated(): void
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(
            fn(string $key) => $key === 'site_name' ? 'Unité Test' : ''
        );

        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('isTierAvailable')->willReturn(true);
        $llmConnector->method('complete')->willReturn(
            new LlmResponse(content: '<h2>Titre</h2><p>Le site traite vos données.</p>', parsed: null, inputTokens: 10, outputTokens: 20, truncated: false)
        );

        $service = new RgpdContentService($this->moduleManager, $settingService, $llmConnector);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/responsable du traitement/');
        $service->generateWithAi('Instructions');
    }

    public function testDefaultContentDisclosesTheUsageStatisticsAndDiagnosticPackage(): void
    {
        $service = new RgpdContentService($this->moduleManager, $this->settingService);
        $content = $service->getDefaultContent();

        $this->assertStringContainsString('Statistiques d\'utilisation', $content);
        $this->assertStringContainsString('n\'est pas anonyme', $content);
        $this->assertStringContainsString('aucune donnée de membre', $content);
        $this->assertStringContainsString('Archive de diagnostic', $content);
        $this->assertStringContainsString('jamais transmise automatiquement', $content);
        $this->assertStringContainsString('adresses IP', $content);
    }

    public function testTheSystemPromptDescribesTheSameProcessing(): void
    {
        $captured = null;
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isTierAvailable')->willReturn(true);
        $connector->method('complete')->willReturnCallback(
            function (LlmRequest $request) use (&$captured): LlmResponse {
                $captured = $request->systemPrompt;
                return new LlmResponse(content: '<h2>Contenu</h2><p>Unité scoute est responsable du traitement.</p>', parsed: null, inputTokens: 10, outputTokens: 10, truncated: false);
            }
        );

        $service = new RgpdContentService($this->moduleManager, $this->settingService, $connector);
        $service->generateWithAi('Instructions.');

        $this->assertIsString($captured);
        $this->assertStringContainsString('Statistiques d\'utilisation et archive de diagnostic', $captured);
        $this->assertStringContainsString('jamais transmise automatiquement', $captured);
        $this->assertStringContainsString('ne le décris jamais comme anonyme', $captured);
    }
}
