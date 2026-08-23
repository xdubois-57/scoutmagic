<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;

/**
 * Anthropic Messages API provider implementation.
 * Calls /v1/models and /v1/messages on the configured endpoint.
 */
class AnthropicProvider implements LlmProviderInterface
{
    private const DEFAULT_TIMEOUT = 30;
    private const API_VERSION = '2023-06-01';

    /** Page size requested from /v1/models (the API's own maximum). */
    private const MODELS_PAGE_SIZE = 1000;

    /** Hard stop on pagination, so a misbehaving has_more can never loop forever. */
    private const MAX_MODEL_PAGES = 20;

    private HttpTransport $http;

    public function __construct(
        private string $apiEndpoint,
        private string $apiKey
    ) {
        $this->http = new HttpTransport();
    }

    /**
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
            'Content-Type: application/json',
        ];
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModels(): array
    {
        $base = rtrim($this->apiEndpoint, '/') . '/v1/models?limit=' . self::MODELS_PAGE_SIZE;

        $models = [];
        $afterId = null;

        // This endpoint paginates (has_more / last_id, 20 per page by
        // default). Reading only the first page silently hid every model past
        // it — and since Controller\ConfigController::testConnection() prunes
        // with deleteModelsNotIn(), an unread page's models were deleted from
        // the local catalogue on every connection test.
        for ($page = 0; $page < self::MAX_MODEL_PAGES; $page++) {
            $url = $afterId === null ? $base : $base . '&after_id=' . rawurlencode($afterId);
            $response = $this->http->getJson($url, $this->headers(), self::DEFAULT_TIMEOUT);

            if (!isset($response['data']) || !is_array($response['data'])) {
                throw LlmException::apiError('Invalid response from the Anthropic models endpoint.');
            }

            foreach ($response['data'] as $model) {
                $id = (string) ($model['id'] ?? '');
                if ($id === '') {
                    // A model with no usable identifier cannot be called and
                    // must never reach llm_provider_models.model_id.
                    continue;
                }
                $models[] = [
                    'id' => $id,
                    'display_name' => (string) ($model['display_name'] ?? $id),
                ];
            }

            $lastId = isset($response['last_id']) ? (string) $response['last_id'] : '';
            if (($response['has_more'] ?? false) !== true || $lastId === '') {
                break;
            }
            $afterId = $lastId;
        }

        return $models;
    }

    public function complete(string $modelId, string $prompt, array $options = []): ProviderResponse
    {
        $url = rtrim($this->apiEndpoint, '/') . '/v1/messages';
        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $systemPrompt = $options['system_prompt'] ?? null;
        $attachments = $options['attachments'] ?? [];
        $responseSchema = $options['response_schema'] ?? null;

        $content = $this->buildContentBlocks($prompt, $attachments);

        $effectiveSystem = $this->buildSystemPrompt($systemPrompt, $responseSchema);

        $body = $this->buildRequestBody($modelId, $content, $effectiveSystem, $options);

        $response = $this->http->postJson($url, $this->headers(), $body, $timeout);

        // A non-2xx never reaches this point (HttpTransport rejects it); this
        // still catches an error object returned with a 200.
        if (isset($response['error'])) {
            $errorMsg = $response['error']['message'] ?? 'Unknown error';
            $errorType = $response['error']['type'] ?? '';

            if ($errorType === 'rate_limit_error') {
                throw LlmException::rateLimited($errorMsg);
            }

            throw LlmException::apiError($errorMsg);
        }

        $outputText = $this->extractTextContent($response);
        $inputTokens = (int) ($response['usage']['input_tokens'] ?? 0);
        $outputTokens = (int) ($response['usage']['output_tokens'] ?? 0);
        $truncated = ($response['stop_reason'] ?? null) === 'max_tokens';

        return new ProviderResponse(
            content: $outputText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            truncated: $truncated
        );
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRequestBody(string $modelId, array $content, ?string $effectiveSystem, array $options): array
    {
        $body = [
            'model' => $modelId,
            // Always sent explicitly — see ScalewayProvider::buildRequestBody()
            // for why this can never be omitted.
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096),
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        if ($effectiveSystem !== null) {
            $body['system'] = $effectiveSystem;
        }

        return $body;
    }

    /**
     * Build content blocks for the Messages API (text, image, document).
     *
     * @param array<int, array{data: string, mime_type: string}> $attachments
     * @return array<int, array<string, mixed>>
     */
    private function buildContentBlocks(string $prompt, array $attachments): array
    {
        $blocks = [];

        foreach ($attachments as $attachment) {
            $mimeType = $attachment['mime_type'];
            $data = $attachment['data'];

            if (str_starts_with($mimeType, 'image/')) {
                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mimeType,
                        'data' => $data,
                    ],
                ];
            } elseif ($mimeType === 'application/pdf') {
                $blocks[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mimeType,
                        'data' => $data,
                    ],
                ];
            }
        }

        $blocks[] = ['type' => 'text', 'text' => $prompt];

        return $blocks;
    }

    /**
     * Build the effective system prompt, optionally appending JSON schema instructions.
     *
     * @param array<string, mixed>|null $responseSchema
     */
    private function buildSystemPrompt(?string $systemPrompt, ?array $responseSchema): ?string
    {
        $parts = [];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $parts[] = $systemPrompt;
        }

        if ($responseSchema !== null) {
            $schemaJson = json_encode($responseSchema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $parts[] = "You MUST respond with valid JSON conforming exactly to this schema:\n" . $schemaJson . "\nDo not include any text outside the JSON object.";
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
    }

    /**
     * Extract text content from the Messages API response.
     *
     * @param array<string, mixed> $response
     */
    private function extractTextContent(array $response): string
    {
        $content = $response['content'] ?? [];
        $texts = [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $texts[] = $block['text'] ?? '';
            }
        }

        return implode("\n", $texts);
    }
}
