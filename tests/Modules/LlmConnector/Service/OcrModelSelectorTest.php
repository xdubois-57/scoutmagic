<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ProviderResponse;
use Modules\LlmConnector\Service\OcrModelSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OcrModelSelectorTest extends TestCase
{
    private function catalog(): ProviderModelCatalog
    {
        return new ProviderModelCatalog([
            ['id' => 'bge-multilingual-gemma2', 'display_name' => 'BGE', 'chat' => false, 'vision' => false],
            ['id' => 'cheap-model', 'display_name' => 'Text', 'chat' => true, 'vision' => false],
            ['id' => 'capable-model', 'display_name' => 'Better text', 'chat' => true, 'vision' => false],
            ['id' => 'vision-model', 'display_name' => 'Images', 'chat' => true, 'vision' => true],
            ['id' => 'huge-opus-model', 'display_name' => 'Expensive', 'chat' => true, 'vision' => true],
            ['id' => 'mistral-ocr-3', 'display_name' => 'Document endpoint', 'chat' => false, 'vision' => true],
            ['id' => 'unknown-vision-mini', 'display_name' => 'Unverified'],
        ]);
    }

    private function journalFor(string $status): JournalService
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())->method('log')->with(
            'llm_connector', 'tier_models_selected', 'info', $this->isString(),
            $this->callback(static fn (array $context): bool => $context['tier_selection_status'] === $status),
            null
        );
        return $journal;
    }

    public function testSelectsAllTiersThroughAChatModelAndOnlyOffersVerifiedCandidates(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->once())->method('complete')->with(
            'cheap-model',
            $this->callback(static function (string $prompt): bool {
                return str_contains($prompt, 'Chat-capable models: ["cheap-model","capable-model","vision-model","huge-opus-model"]')
                    && str_contains($prompt, 'Image-capable chat models: ["vision-model","huge-opus-model"]')
                    && !str_contains($prompt, 'bge-multilingual-gemma2')
                    && !str_contains($prompt, 'unknown-vision-mini')
                    && !str_contains($prompt, 'mistral-ocr-3');
            })
        )->willReturn(new ProviderResponse(
            '{"cheap_model_id":"cheap-model","capable_model_id":"capable-model","ocr_model_id":"vision-model"}', 1, 1
        ));

        self::assertSame(
            ['cheap' => 'cheap-model', 'capable' => 'capable-model', 'ocr' => 'vision-model'],
            (new OcrModelSelector($this->journalFor('success')))->selectTiers($provider, $this->catalog())
        );
    }

    public function testExtractsTiersFromMarkdownJsonBlock(): void
    {
        $provider = $this->createStub(LlmProviderInterface::class);
        $provider->method('complete')->willReturn(new ProviderResponse(
            "```json\n{\"cheap_model_id\":\"cheap-model\",\"capable_model_id\":\"capable-model\",\"ocr_model_id\":\"vision-model\"}\n```", 1, 1
        ));
        self::assertSame(
            ['cheap' => 'cheap-model', 'capable' => 'capable-model', 'ocr' => 'vision-model'],
            (new OcrModelSelector($this->journalFor('success')))->selectTiers($provider, $this->catalog())
        );
    }

    public static function invalidSelections(): iterable
    {
        $valid = ['cheap_model_id' => 'cheap-model', 'capable_model_id' => 'capable-model', 'ocr_model_id' => 'vision-model'];
        foreach (array_keys($valid) as $key) {
            yield "BGE on {$key}" => [json_encode([...$valid, $key => 'bge-multilingual-gemma2']), 'unknown_model'];
        }
        yield 'unknown capable' => [json_encode([...$valid, 'capable_model_id' => 'unknown-model']), 'unknown_model'];
        yield 'unknown vision' => [json_encode([...$valid, 'ocr_model_id' => 'unknown-vision-mini']), 'unknown_model'];
        yield 'document OCR endpoint' => [json_encode([...$valid, 'ocr_model_id' => 'mistral-ocr-3']), 'unknown_model'];
        yield 'text only OCR' => [json_encode([...$valid, 'ocr_model_id' => 'cheap-model']), 'ocr_endpoint_incompatible'];
        yield 'missing OCR' => ['{"cheap_model_id":"cheap-model","capable_model_id":"capable-model"}', 'missing_field'];
        yield 'malformed id' => [json_encode([...$valid, 'cheap_model_id' => ['cheap-model']]), 'missing_field'];
        yield 'expensive cheap' => [json_encode([...$valid, 'cheap_model_id' => 'huge-opus-model']), 'cheap_too_expensive'];
        yield 'expensive capable' => [json_encode([...$valid, 'capable_model_id' => 'huge-opus-model']), 'capable_too_expensive'];
        yield 'expensive OCR' => [json_encode([...$valid, 'ocr_model_id' => 'huge-opus-model']), 'ocr_too_expensive'];
        yield 'no JSON' => ['No selection', 'invalid_response'];
        yield 'invalid JSON' => ['{bad}', 'invalid_response'];
    }

    #[DataProvider('invalidSelections')]
    public function testRejectsInvalidSelectionsWithoutReintroducingExcludedModels(string $answer, string $status): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->once())->method('complete')->with('cheap-model', $this->isString())
            ->willReturn(new ProviderResponse($answer, 1, 1));
        self::assertSame(
            ['cheap' => 'cheap-model', 'capable' => 'capable-model', 'ocr' => 'vision-model'],
            (new OcrModelSelector($this->journalFor($status)))->selectTiers($provider, $this->catalog())
        );
    }

    public function testAClassificationFailureUsesOnlyVerifiedFallbacksAndDoesNotLogTheErrorPayload(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->once())->method('complete')->with('cheap-model', $this->isString())
            ->willThrowException(new \RuntimeException('Sensitive echoed receipt content', 422));
        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())->method('log')->with(
            'llm_connector', 'tier_models_selected', 'info',
            'Le classement des modèles IA a échoué ; les choix compatibles de secours sont utilisés.',
            [
                'query_model_id' => 'cheap-model',
                'tier_selection_status' => 'llm_error',
                'selected_tiers' => ['cheap' => 'cheap-model', 'capable' => 'capable-model', 'ocr' => 'vision-model'],
                'error_type' => \RuntimeException::class,
                'error_code' => 422,
            ], null
        );
        self::assertSame(
            ['cheap' => 'cheap-model', 'capable' => 'capable-model', 'ocr' => 'vision-model'],
            (new OcrModelSelector($journal))->selectTiers($provider, $this->catalog())
        );
    }

    public function testAnUntrustedResponseIsNeverIncludedInTheJournal(): void
    {
        $provider = $this->createStub(LlmProviderInterface::class);
        $provider->method('complete')->willReturn(new ProviderResponse(
            '{"cheap_model_id":"Sensitive echoed receipt content","capable_model_id":"capable-model","ocr_model_id":null}', 1, 1
        ));
        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())->method('log')->with(
            'llm_connector', 'tier_models_selected', 'info',
            'Le classement IA désigne un modèle sans compatibilité de conversation établie.',
            [
                'query_model_id' => 'cheap-model',
                'tier_selection_status' => 'unknown_model',
                'selected_tiers' => ['cheap' => 'cheap-model', 'capable' => 'capable-model', 'ocr' => 'vision-model'],
            ], null
        );
        (new OcrModelSelector($journal))->selectTiers($provider, $this->catalog());
    }

    public static function unavailableCatalogs(): iterable
    {
        yield 'empty' => [[]];
        yield 'non chat' => [[['id' => 'bge-multilingual-gemma2', 'display_name' => 'BGE', 'chat' => false, 'vision' => false]]];
        yield 'unknown' => [[['id' => 'new-small-vision', 'display_name' => 'New model']]];
    }

    #[DataProvider('unavailableCatalogs')]
    public function testNoCompatibleModelsMeansNoClassifierCallAndNoTiers(array $rows): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->never())->method('complete');
        self::assertSame(
            ['cheap' => null, 'capable' => null, 'ocr' => null],
            (new OcrModelSelector($this->journalFor('no_models')))->selectTiers($provider, new ProviderModelCatalog($rows))
        );
    }

    public static function imageCapabilities(): iterable
    {
        yield 'text only' => [false, null];
        yield 'unknown images' => [null, null];
        yield 'verified images without a vision name' => [true, 'small-model'];
    }

    #[DataProvider('imageCapabilities')]
    public function testAnOmittedImageChoiceFallsBackOnlyToEstablishedImageSupport(?bool $vision, ?string $expectedOcr): void
    {
        $provider = $this->createStub(LlmProviderInterface::class);
        $provider->method('complete')->willReturn(new ProviderResponse(
            '{"cheap_model_id":"small-model","capable_model_id":"small-model","ocr_model_id":null}', 1, 1
        ));
        $catalog = new ProviderModelCatalog([
            ['id' => 'small-model', 'display_name' => 'Small', 'chat' => true, 'vision' => $vision],
        ]);
        self::assertSame(
            ['cheap' => 'small-model', 'capable' => 'small-model', 'ocr' => $expectedOcr],
            (new OcrModelSelector())->selectTiers($provider, $catalog)
        );
    }
}
