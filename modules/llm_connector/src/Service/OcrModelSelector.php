<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Provider\LlmProviderInterface;

/**
 * Selects cheap, capable and OCR models for a provider by asking the provider's
 * cheapest model in a single request. Falls back to rule-based detection when the
 * LLM call fails or returns an invalid answer.
 */
class OcrModelSelector
{
    public function __construct(
        private ?JournalService $journalService = null,
        private ?int $actingUserAccountId = null,
    ) {
    }

    public function setJournalService(JournalService $journalService, ?int $userAccountId = null): void
    {
        $this->journalService = $journalService;
        $this->actingUserAccountId = $userAccountId;
    }

    /**
     * Select the best cheap, capable and OCR models from the available list.
     * Performs exactly one LLM query.
     *
     * @param array<int, string> $modelIds
     * @return array{cheap: string|null, capable: string|null, ocr: string|null}
     */
    public function selectTiers(LlmProviderInterface $provider, array $modelIds): array
    {
        $fallback = $this->fallbackTiers($modelIds);

        if ($modelIds === []) {
            $this->logSelection('', '', 'no_models', 'No models available for tier selection.', $fallback);

            return $fallback;
        }

        // Pick the cheapest-looking model to run the selection query
        $queryModelId = $fallback['cheap'] ?? $modelIds[0];

        try {
            $response = $provider->complete($queryModelId, $this->buildPrompt($modelIds));
            $rawResponse = $response->content;
            $json = $this->extractJson($rawResponse);
            $selection = $json !== null ? json_decode($json, true) : null;
        } catch (\Throwable $e) {
            $this->logSelection(
                $queryModelId,
                '',
                'llm_error',
                'Tier selection request failed: ' . $e->getMessage(),
                $fallback
            );

            return $fallback;
        }

        if (!is_array($selection)) {
            $this->logSelection(
                $queryModelId,
                $rawResponse,
                'invalid_response',
                'Tier selection response was not valid JSON.',
                $fallback
            );

            return $fallback;
        }

        $requiredKeys = ['cheap_model_id', 'capable_model_id', 'ocr_model_id'];
        foreach ($requiredKeys as $key) {
            if (!isset($selection[$key]) || !is_string($selection[$key]) || $selection[$key] === '') {
                $this->logSelection(
                    $queryModelId,
                    $rawResponse,
                    'missing_field',
                    'Tier selection response missing required field: ' . $key,
                    $fallback
                );

                return $fallback;
            }

            if (!in_array($selection[$key], $modelIds, true)) {
                $this->logSelection(
                    $queryModelId,
                    $rawResponse,
                    'unknown_model',
                    "Selected model '{$selection[$key]}' for '{$key}' is not in the available list.",
                    $fallback
                );

                return $fallback;
            }
        }

        // Enforce cost constraints
        $cheap = $selection['cheap_model_id'];
        $capable = $selection['capable_model_id'];
        $ocr = $selection['ocr_model_id'];

        if (!$this->isCheap($cheap)) {
            $this->logSelection(
                $queryModelId,
                $rawResponse,
                'cheap_too_expensive',
                "Selected cheap model '{$cheap}' is not a low-cost model.",
                $fallback
            );

            return $fallback;
        }

        if (!$this->isReasonable($capable)) {
            $this->logSelection(
                $queryModelId,
                $rawResponse,
                'capable_too_expensive',
                "Selected capable model '{$capable}' is too expensive for the capable tier.",
                $fallback
            );

            return $fallback;
        }

        if (!$this->isCheap($ocr)) {
            $this->logSelection(
                $queryModelId,
                $rawResponse,
                'ocr_too_expensive',
                "Selected OCR model '{$ocr}' is not a low-cost model.",
                $fallback
            );

            return $fallback;
        }

        $tierMap = [
            'cheap' => $cheap,
            'capable' => $capable,
            'ocr' => $ocr,
        ];

        $this->logSelection(
            $queryModelId,
            $rawResponse,
            'success',
            "Tiers selected via LLM: cheap='{$cheap}', capable='{$capable}', ocr='{$ocr}'.",
            $tierMap
        );

        return $tierMap;
    }

    /**
     * @param array<int, string> $modelIds
     * @return array{cheap: string|null, capable: string|null, ocr: string|null}
     */
    private function fallbackTiers(array $modelIds): array
    {
        $usableModels = array_values(array_filter(
            $modelIds,
            static fn (string $id): bool => !preg_match('/embed|moderation|whisper|tts|codestral/i', $id)
        ));

        if ($usableModels === []) {
            return ['cheap' => null, 'capable' => null, 'ocr' => null];
        }

        $bestSmall = $this->pickBestByPatterns($usableModels, [
            'small', 'ministral', 'nemo', 'haiku', 'mini', 'lite',
        ]);

        $bestLarge = $this->pickBestByPatterns($usableModels, [
            'large', 'medium', 'sonnet',
        ]);

        // If no named small/cheap model found, use the first available one
        if ($bestSmall === null) {
            $bestSmall = $usableModels[0];
        }

        // If no named capable model found, pick the first usable model that is different
        // from the cheap model and not obviously too expensive (e.g. opus/405b/70b/72b)
        if ($bestLarge === null) {
            foreach ($usableModels as $id) {
                if ($id !== $bestSmall && $this->isReasonable($id)) {
                    $bestLarge = $id;
                    break;
                }
            }
            $bestLarge ??= $bestSmall;
        }

        $bestOcr = $this->pickBestByPatterns($usableModels, ['ocr']) ?? $bestSmall;

        return [
            'cheap' => $bestSmall,
            'capable' => $bestLarge,
            'ocr' => $bestOcr,
        ];
    }

    /**
     * @param array<int, string> $modelIds
     * @param array<int, string> $patterns
     */
    private function pickBestByPatterns(array $modelIds, array $patterns): ?string
    {
        $best = null;
        foreach ($modelIds as $id) {
            $lower = strtolower($id);
            foreach ($patterns as $pattern) {
                if (str_contains($lower, $pattern)) {
                    if ($best === null || $this->extractDate($id) > $this->extractDate($best)) {
                        $best = $id;
                    }
                    break;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<int, string> $modelIds
     */
    private function buildPrompt(array $modelIds): string
    {
        $availableModels = json_encode(array_values($modelIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are a model selection assistant for an LLM connector.

Given the list of available models below, choose the best model for each of the following three tiers:

1. cheap_model_id: the cheapest/small model suitable for simple, high-volume tasks. It should be the most economical choice.
2. capable_model_id: a model that is noticeably better than the cheap choice for harder tasks, but still reasonably priced. Do NOT pick the most expensive option. Avoid models with "opus", "405b", "70b" or "72b" in the name unless there is no alternative.
3. ocr_model_id: the best model for OCR of photographed shop receipts. It must understand images and extract text/structured data from low-quality receipt photos. It must remain cheap or at most average cost; never choose a costly/large model. Prefer the smallest vision-capable model. Avoid "large", "opus", "sonnet", or models with 70b/72b/405b in the name unless there is genuinely no cheaper alternative.

Rules for your answer:
1. Respond ONLY with a valid JSON object.
2. The JSON object must contain exactly three fields: "cheap_model_id", "capable_model_id", "ocr_model_id".
3. Every value must be the EXACT string identifier of one model from the list below.
4. Do not add any explanation, markdown, or comments outside the JSON.
5. If none of the available models is clearly better for a tier, choose the smallest/cheapest available model.

Example of a valid response:
{"cheap_model_id": "claude-3-5-haiku-20241022", "capable_model_id": "claude-3-5-sonnet-20241022", "ocr_model_id": "claude-3-5-haiku-20241022"}

Available models: {$availableModels}
PROMPT;
    }

    /**
     * Try to extract a JSON object from a response that may be wrapped in
     * markdown code fences or contain extra text.
     */
    private function extractJson(string $rawResponse): ?string
    {
        if (preg_match('/```(?:json)?\s*\n?(\{.*?\})\n?\s*```/s', $rawResponse, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $rawResponse, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function isCheap(string $modelId): bool
    {
        return !preg_match('/(?:large|medium|opus|sonnet|(?:^|[-_])(?:70|72|405)b(?:$|[-_]))/i', $modelId);
    }

    private function isReasonable(string $modelId): bool
    {
        // Capable tier can be medium/sonnet but not the very largest/expensive families
        return !preg_match('/(?:opus|(?:^|[-_])(?:405|70|72)b(?:$|[-_]))/i', $modelId);
    }

    private function extractDate(string $modelId): string
    {
        if (stripos($modelId, 'latest') !== false) {
            return '99999999';
        }
        if (preg_match('/(\d{8})/', $modelId, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d{4})$/', $modelId, $matches)) {
            return $matches[1] . '0000';
        }

        return '00000000';
    }

    /**
     * @param array{cheap: string|null, capable: string|null, ocr: string|null} $tierMap
     */
    private function logSelection(
        string $queryModelId,
        string $rawResponse,
        string $status,
        string $message,
        array $tierMap
    ): void {
        if ($this->journalService === null) {
            return;
        }

        $context = [
            'query_model_id' => $queryModelId,
            'tier_selection_status' => $status,
            'selected_tiers' => $tierMap,
        ];

        if ($rawResponse !== '') {
            $context['tier_selection_response'] = $rawResponse;
        }

        // Journal level column only allows 'info'/'security'; status lives in context.
        $this->journalService->log(
            'llm_connector',
            'tier_models_selected',
            'info',
            $message,
            $context,
            $this->actingUserAccountId
        );
    }
}
