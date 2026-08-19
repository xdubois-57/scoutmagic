<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;

/**
 * Facade implementing the public LlmConnectorInterface.
 * Resolves tier → provider → model, calls the provider, wraps the response.
 * Never logs prompt/response content (potential user data). Only metadata.
 */
class LlmConnectorService implements LlmConnectorInterface
{
    // Always sent to the provider (LlmRequest::$maxTokens overrides it) —
    // some drivers (Scaleway, Mistral) previously sent no output-length cap
    // at all, silently falling back to the provider's own server-side
    // default, which could be far too small for a long-form request and
    // produce an accepted-but-truncated response with no way to detect it.
    private const DEFAULT_MAX_TOKENS = 4096;

    public function __construct(
        private ProviderRepository $providerRepo,
        private ProviderModelRepository $modelRepo,
        private JournalService $journalService
    ) {
    }

    public function isAvailable(): bool
    {
        $provider = $this->activeProvider();
        if ($provider === null) {
            return false;
        }

        foreach (LlmTier::cases() as $tier) {
            if ($this->modelRepo->findByProviderAndTier($provider['id'], $tier) !== null) {
                return true;
            }
        }

        return false;
    }

    public function isTierAvailable(LlmTier $tier): bool
    {
        $provider = $this->activeProvider();
        if ($provider === null) {
            return false;
        }

        return $this->resolveModel($provider['id'], $tier) !== null;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $provider = $this->activeProvider();
        if ($provider === null) {
            throw LlmException::noProvider();
        }

        $model = $this->resolveModel($provider['id'], $request->tier);
        if ($model === null) {
            throw LlmException::noModel($request->tier);
        }

        $driverInstance = $this->createDriver($provider['driver'], $provider['api_endpoint'], $provider['api_key']);

        $options = [
            'system_prompt' => $request->systemPrompt,
            'attachments' => $request->attachments,
            'response_schema' => $request->responseSchema,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
        ];

        if ($request->timeoutSeconds !== null) {
            $options['timeout'] = $request->timeoutSeconds;
        }

        try {
            $providerResponse = $driverInstance->complete($model['model_id'], $request->prompt, $options);
        } catch (LlmException $e) {
            $this->journalService->log(
                'llm_connector',
                'llm_request_failed',
                'info',
                'LLM request failed',
                [
                    'provider' => $provider['name'],
                    'tier' => $request->tier->value,
                    'model' => $model['model_id'],
                    'error_code' => $e->getCode(),
                ],
                null
            );
            throw $e;
        }

        $this->journalService->log(
            'llm_connector',
            'llm_request_completed',
            'info',
            'LLM request completed',
            [
                'provider' => $provider['name'],
                'tier' => $request->tier->value,
                'model' => $model['model_id'],
                'input_tokens' => $providerResponse->inputTokens,
                'output_tokens' => $providerResponse->outputTokens,
            ],
            null
        );

        // If a response schema was provided, attempt to parse the response as JSON
        $parsed = null;
        if ($request->responseSchema !== null) {
            $decoded = json_decode($this->extractJson($providerResponse->content), true);
            if (is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        return new LlmResponse(
            content: $providerResponse->content,
            parsed: $parsed,
            inputTokens: $providerResponse->inputTokens,
            outputTokens: $providerResponse->outputTokens,
            truncated: $providerResponse->truncated
        );
    }

    /**
     * The active provider, or null when there is none — including when its
     * stored API key cannot be decrypted.
     *
     * ProviderRepository decrypts the key eagerly, so a wrong/rotated
     * encryption key makes findFirstActive() throw DecryptionException. That
     * used to escape isAvailable(), which several callers invoke while
     * rendering a page: it turned an unusable API key into a 500 on the
     * PUBLIC retrospective board (Modules\Retro\Controller\
     * RetroBoardController::show()), the news editor and the gallery config
     * page. An unreadable key means "no AI", never "the site is down".
     *
     * @return array{id: int, name: string, driver: string, api_endpoint: string, api_key: string}|null
     */
    private function activeProvider(): ?array
    {
        try {
            return $this->providerRepo->findFirstActive();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The model backing a tier, applying the OCR → CHEAP fallback (no OCR
     * model assigned still leaves a real chat model to read a receipt with).
     * Shared by complete() and isTierAvailable() so the two can never
     * disagree about whether a tier is usable.
     *
     * @return array{id: int, provider_id: int, model_id: string, display_name: string}|null
     */
    private function resolveModel(int $providerId, LlmTier $tier): ?array
    {
        $model = $this->modelRepo->findByProviderAndTier($providerId, $tier);

        if ($model === null && $tier === LlmTier::OCR) {
            $model = $this->modelRepo->findByProviderAndTier($providerId, LlmTier::CHEAP);
        }

        return $model;
    }

    /**
     * Structured output here is prompt-instructed, not enforced by the
     * provider API, so a model sometimes wraps its JSON in a markdown
     * code fence (or adds stray text around it) despite being told not
     * to. Strips that wrapping so json_decode() sees the object itself —
     * mirrors Service\OcrModelSelector::extractJson() (tier-selection
     * has the same prompt-instructed-JSON fragility).
     */
    private function extractJson(string $content): string
    {
        if (preg_match('/```(?:json)?\s*\n?(\{.*?\})\n?\s*```/s', $content, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }

    /**
     * Instantiate the appropriate provider driver.
     */
    private function createDriver(string $driver, string $apiEndpoint, string $apiKey): LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            'scaleway' => new ScalewayProvider($apiEndpoint, $apiKey),
            default => throw LlmException::apiError("Unknown driver: {$driver}"),
        };
    }
}
