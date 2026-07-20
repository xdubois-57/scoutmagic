<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\ProviderResponse;
use Modules\LlmConnector\Service\OcrModelSelector;
use PHPUnit\Framework\TestCase;

class OcrModelSelectorTest extends TestCase
{
    public function testSelectsAllThreeTiersInOneQuery(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->once())
            ->method('complete')
            ->with(
                'cheap-model',
                $this->callback(static fn (string $prompt): bool => str_contains($prompt, 'cheap_model_id')
                    && str_contains($prompt, 'capable_model_id')
                    && str_contains($prompt, 'ocr_model_id')
                    && str_contains($prompt, 'cheap-model')
                    && str_contains($prompt, 'capable-model'))
            )
            ->willReturn(new ProviderResponse('{"cheap_model_id":"cheap-model","capable_model_id":"capable-model","ocr_model_id":"ocr-model"}', 1, 1));

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'tier_models_selected',
                'info',
                "Tiers selected via LLM: cheap='cheap-model', capable='capable-model', ocr='ocr-model'.",
                $this->callback(static fn (array $ctx): bool => $ctx['tier_selection_status'] === 'success'
                    && $ctx['selected_tiers']['cheap'] === 'cheap-model')
            );

        $tiers = (new OcrModelSelector($journal))->selectTiers(
            $provider,
            ['cheap-model', 'capable-model', 'ocr-model']
        );

        $this->assertSame('cheap-model', $tiers['cheap']);
        $this->assertSame('capable-model', $tiers['capable']);
        $this->assertSame('ocr-model', $tiers['ocr']);
    }

    public function testExtractsTiersFromMarkdownJsonBlock(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('complete')
            ->willReturn(new ProviderResponse("```json\n{\"cheap_model_id\":\"cheap-model\",\"capable_model_id\":\"capable-model\",\"ocr_model_id\":\"ocr-model\"}\n```", 1, 1));

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'tier_models_selected',
                'info',
                "Tiers selected via LLM: cheap='cheap-model', capable='capable-model', ocr='ocr-model'.",
                $this->callback(static fn (array $ctx): bool => $ctx['tier_selection_status'] === 'success')
            );

        $tiers = (new OcrModelSelector($journal))->selectTiers(
            $provider,
            ['cheap-model', 'capable-model', 'ocr-model']
        );

        $this->assertSame('cheap-model', $tiers['cheap']);
        $this->assertSame('capable-model', $tiers['capable']);
        $this->assertSame('ocr-model', $tiers['ocr']);
    }

    public function testRejectsUnknownModelAndFallsBackToRuleBased(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('complete')
            ->willReturn(new ProviderResponse('{"cheap_model_id":"cheap-model","capable_model_id":"unknown-model","ocr_model_id":"ocr-model"}', 1, 1));

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'tier_models_selected',
                'info',
                "Selected model 'unknown-model' for 'capable_model_id' is not in the available list.",
                $this->callback(static fn (array $ctx): bool => $ctx['tier_selection_status'] === 'unknown_model')
            );

        $tiers = (new OcrModelSelector($journal))->selectTiers(
            $provider,
            ['cheap-model', 'capable-model', 'ocr-model']
        );

        $this->assertSame('cheap-model', $tiers['cheap']);
        $this->assertSame('capable-model', $tiers['capable']);
        $this->assertSame('ocr-model', $tiers['ocr']);
    }

    public function testRejectsTooExpensiveCheapModelAndFallsBack(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('complete')
            ->willReturn(new ProviderResponse('{"cheap_model_id":"huge-opus-model","capable_model_id":"capable-model","ocr_model_id":"ocr-model"}', 1, 1));

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'tier_models_selected',
                'info',
                "Selected cheap model 'huge-opus-model' is not a low-cost model.",
                $this->callback(static fn (array $ctx): bool => $ctx['tier_selection_status'] === 'cheap_too_expensive')
            );

        $tiers = (new OcrModelSelector($journal))->selectTiers(
            $provider,
            ['cheap-model', 'huge-opus-model', 'capable-model', 'ocr-model']
        );

        $this->assertSame('cheap-model', $tiers['cheap']);
        $this->assertSame('capable-model', $tiers['capable']);
        $this->assertSame('ocr-model', $tiers['ocr']);
    }

    public function testFallsBackWhenLlmCallFails(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('complete')->willThrowException(new \RuntimeException('Network error'));

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'tier_models_selected',
                'info',
                'Tier selection request failed: Network error',
                $this->callback(static fn (array $ctx): bool => $ctx['tier_selection_status'] === 'llm_error')
            );

        $tiers = (new OcrModelSelector($journal))->selectTiers(
            $provider,
            ['cheap-model', 'capable-model', 'ocr-model']
        );

        $this->assertSame('cheap-model', $tiers['cheap']);
        $this->assertSame('capable-model', $tiers['capable']);
        $this->assertSame('ocr-model', $tiers['ocr']);
    }

    public function testUsesFallbackForEmptyModelList(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->never())->method('complete');

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'llm_connector',
                'tier_models_selected',
                'info',
                'No models available for tier selection.',
                $this->callback(static fn (array $ctx): bool => $ctx['tier_selection_status'] === 'no_models')
            );

        $tiers = (new OcrModelSelector($journal))->selectTiers($provider, []);

        $this->assertNull($tiers['cheap']);
        $this->assertNull($tiers['capable']);
        $this->assertNull($tiers['ocr']);
    }
}
