<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Api\LlmException;

/** Capabilities gate candidates; an LLM and cost heuristics only rank them. */
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
     * At most one classification query, through a proven chat endpoint.
     * No image tier is advertised without independently established vision.
     *
     * @return array{cheap: ?string, capable: ?string, ocr: ?string}
     */
    public function selectTiers(LlmProviderInterface $provider, ProviderModelCatalog $catalog): array
    {
        $fallback = $this->fallbackTiers($catalog);
        $modelIds = $catalog->chatModelIds();
        if ($modelIds === []) {
            $this->logSelection('', 'no_models', 'Aucun modèle compatible avec la conversation.', $fallback);
            return $fallback;
        }

        if ($fallback['cheap'] === null) {
            $this->logSelection('', 'no_cheap_model', 'Aucun modèle économique pour classer le catalogue.', $fallback);
            return $fallback;
        }

        $queryModelId = $fallback['cheap'];
        try {
            $response = $provider->complete($queryModelId, $this->buildPrompt($catalog));
            $json = $this->extractJson($response->content);
            $selection = $json === null ? null : json_decode($json, true);
        } catch (\Throwable $error) {
            if ($provider instanceof ScalewayProvider && $error instanceof LlmException
                && ScalewayProvider::isUnsupportedRoute($error)) {
                $fallback = $this->fallbackTiers($catalog->withoutChatModel($queryModelId));
            }
            $this->logSelection(
                $queryModelId,
                'llm_error',
                'Le classement des modèles IA a échoué ; les choix compatibles de secours sont utilisés.',
                $fallback,
                ['error_type' => $error::class, 'error_code' => $error->getCode()]
            );
            return $fallback;
        }

        if (!is_array($selection)) {
            $this->logSelection(
                $queryModelId,
                'invalid_response',
                'Le classement des modèles IA est illisible.',
                $fallback
            );
            return $fallback;
        }
        $error = $this->identifierError($selection, $catalog);
        if ($error !== null) {
            $this->logSelection($queryModelId, $error['status'], $error['message'], $fallback);
            return $fallback;
        }

        // identifierError established these types; no untrusted answer text is logged.
        $cheap = (string) $selection['cheap_model_id'];
        $capable = (string) $selection['capable_model_id'];
        $ocr = $selection['ocr_model_id'] === null ? $fallback['ocr'] : (string) $selection['ocr_model_id'];
        $error = $this->costError($cheap, $capable, $ocr);
        if ($error !== null) {
            $this->logSelection($queryModelId, $error['status'], $error['message'], $fallback);
            return $fallback;
        }

        $tiers = ['cheap' => $cheap, 'capable' => $capable, 'ocr' => $ocr];
        $this->logSelection($queryModelId, 'success', 'Les modèles IA compatibles ont été sélectionnés.', $tiers);
        return $tiers;
    }

    /**
     * @param array<mixed> $selection
     * @return array{status: string, message: string}|null
     */
    private function identifierError(array $selection, ProviderModelCatalog $catalog): ?array
    {
        $chatIds = $catalog->chatModelIds();
        foreach (['cheap_model_id', 'capable_model_id', 'ocr_model_id'] as $key) {
            if ($key === 'ocr_model_id' && array_key_exists($key, $selection) && $selection[$key] === null) {
                continue;
            }
            if (!isset($selection[$key]) || !is_string($selection[$key]) || $selection[$key] === '') {
                return [
                    'status' => 'missing_field',
                    'message' => 'Le classement IA ne précise pas tous les choix requis.',
                ];
            }
            if (!in_array($selection[$key], $chatIds, true)) {
                return [
                    'status' => 'unknown_model',
                    'message' => 'Le classement IA désigne un modèle sans compatibilité de conversation établie.',
                ];
            }
        }
        if ($selection['ocr_model_id'] !== null
            && !in_array($selection['ocr_model_id'], $catalog->visionModelIds(), true)) {
            return [
                'status' => 'ocr_endpoint_incompatible',
                'message' => 'Le modèle proposé pour les reçus ne dispose pas de lecture d’images établie.',
            ];
        }
        return null;
    }

    /** @return array{status: string, message: string}|null */
    private function costError(string $cheap, string $capable, ?string $ocr): ?array
    {
        if (!$this->isCheap($cheap)) {
            return [
                'status' => 'cheap_too_expensive',
                'message' => 'Le modèle proposé pour le tier économique est trop coûteux.',
            ];
        }
        if (!$this->isReasonable($capable)) {
            return [
                'status' => 'capable_too_expensive',
                'message' => 'Le modèle proposé pour le tier performant est trop coûteux.',
            ];
        }
        if ($ocr !== null && !$this->isCheap($ocr)) {
            return ['status' => 'ocr_too_expensive', 'message' => 'Le modèle proposé pour les reçus est trop coûteux.'];
        }
        return null;
    }

    /** @return array{cheap: ?string, capable: ?string, ocr: ?string} */
    public function fallbackTiers(ProviderModelCatalog $catalog): array
    {
        $models = $catalog->chatModelIds();
        if ($models === []) {
            return ['cheap' => null, 'capable' => null, 'ocr' => null];
        }
        $patterns = ['small', 'ministral', 'nemo', 'haiku', 'mini', 'lite'];
        $economical = array_values(array_filter($models, self::isCheap(...)));
        $cheap = $this->pickBestByPatterns($economical, $patterns) ?? $economical[0] ?? null;
        $reasonable = array_values(array_filter($models, $this->isReasonable(...)));
        $capable = $this->pickBestByPatterns($reasonable, ['large', 'medium', 'sonnet']);
        if ($capable === null) {
            foreach ($models as $id) {
                if ($id !== $cheap && $this->isReasonable($id)) {
                    $capable = $id;
                    break;
                }
            }
            $capable ??= $cheap;
        }
        $vision = array_values(array_filter($catalog->visionModelIds(), $this->isCheap(...)));
        $ocr = in_array($cheap, $vision, true)
            ? $cheap : ($this->pickBestByPatterns($vision, $patterns) ?? $vision[0] ?? null);
        return ['cheap' => $cheap, 'capable' => $capable, 'ocr' => $ocr];
    }

    /** @param list<string> $models
     *  @param list<string> $patterns
     */
    private function pickBestByPatterns(array $models, array $patterns): ?string
    {
        $best = null;
        foreach ($models as $id) {
            foreach ($patterns as $pattern) {
                if (str_contains(strtolower($id), $pattern)) {
                    if ($best === null || $this->extractDate($id) > $this->extractDate($best)) {
                        $best = $id;
                    }
                    break;
                }
            }
        }
        return $best;
    }

    private function buildPrompt(ProviderModelCatalog $catalog): string
    {
        $chat = json_encode($catalog->chatModelIds(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $vision = json_encode($catalog->visionModelIds(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return <<<PROMPT
Choose economical models for the following tiers. Endpoint and input capabilities
have already been independently checked; do not infer them from model names.
Return only a JSON object with exactly these fields:
- cheap_model_id: the cheapest chat model for simple high-volume text tasks.
- capable_model_id: a better chat model at a reasonable cost, not the most expensive.
- ocr_model_id: a cheap image-capable chat model for photographed receipts, or null
  when none is available. It MUST belong to the image-capable list below.
Every non-null value must be an exact identifier from the chat list.
Avoid opus, large, medium, sonnet and 70b/72b/405b models for cheap and OCR.
Chat-capable models: {$chat}
Image-capable chat models: {$vision}
PROMPT;
    }

    private function extractJson(string $response): ?string
    {
        if (preg_match('/```(?:json)?\s*\n?(\{.*?\})\n?\s*```/s', $response, $matches)) {
            return $matches[1];
        }
        return preg_match('/(\{.*\})/s', $response, $matches) ? $matches[1] : null;
    }

    public static function isCheap(string $modelId): bool
    {
        return !preg_match('/(?:large|medium|opus|sonnet|(?:^|[-_])(?:70|72|405)b(?:$|[-_]))/i', $modelId);
    }

    private function isReasonable(string $modelId): bool
    {
        return !preg_match('/(?:opus|(?:^|[-_])(?:70|72|405)b(?:$|[-_]))/i', $modelId);
    }

    private function extractDate(string $modelId): string
    {
        if (stripos($modelId, 'latest') !== false) {
            return '99999999';
        }
        if (preg_match('/(\d{8})/', $modelId, $matches)) {
            return $matches[1];
        }
        return preg_match('/(\d{4})$/', $modelId, $matches) ? $matches[1] . '0000' : '00000000';
    }

    /**
     * @param array{cheap: ?string, capable: ?string, ocr: ?string} $tierMap
     * @param array<string, int|string> $metadata
     */
    private function logSelection(
        string $queryModelId,
        string $status,
        string $message,
        array $tierMap,
        array $metadata = []
    ): void {
        $this->journalService?->log('llm_connector', 'tier_models_selected', 'info', $message, [
            'query_model_id' => $queryModelId,
            'tier_selection_status' => $status,
            'selected_tiers' => $tierMap,
            ...$metadata,
        ], $this->actingUserAccountId);
    }
}
