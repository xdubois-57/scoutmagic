<?php

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
    public function __construct(
        private ProviderRepository $providerRepo,
        private ProviderModelRepository $modelRepo,
        private JournalService $journalService
    ) {
    }

    public function isAvailable(): bool
    {
        $provider = $this->providerRepo->findFirstActive();
        if ($provider === null) {
            return false;
        }

        $cheapModel = $this->modelRepo->findByProviderAndTier($provider['id'], LlmTier::CHEAP);
        $capableModel = $this->modelRepo->findByProviderAndTier($provider['id'], LlmTier::CAPABLE);

        return $cheapModel !== null || $capableModel !== null;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $provider = $this->providerRepo->findFirstActive();
        if ($provider === null) {
            throw LlmException::noProvider();
        }

        $model = $this->modelRepo->findByProviderAndTier($provider['id'], $request->tier);
        
        // Fallback: if OCR tier is requested but no OCR model is assigned, use CHEAP
        if ($model === null && $request->tier === LlmTier::OCR) {
            $model = $this->modelRepo->findByProviderAndTier($provider['id'], LlmTier::CHEAP);
        }
        
        if ($model === null) {
            throw LlmException::noModel($request->tier);
        }

        $driverInstance = $this->createDriver($provider['driver'], $provider['api_endpoint'], $provider['api_key']);

        $options = [
            'system_prompt' => $request->systemPrompt,
            'attachments' => $request->attachments,
            'response_schema' => $request->responseSchema,
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
            $decoded = json_decode($providerResponse->content, true);
            if (is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        return new LlmResponse(
            content: $providerResponse->content,
            parsed: $parsed,
            inputTokens: $providerResponse->inputTokens,
            outputTokens: $providerResponse->outputTokens
        );
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
