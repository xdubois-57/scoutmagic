<?php

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

    public function __construct(
        private string $apiEndpoint,
        private string $apiKey
    ) {
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModels(): array
    {
        $url = rtrim($this->apiEndpoint, '/') . '/v1/models';
        $response = $this->httpGet($url);

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw LlmException::apiError('Invalid response from models endpoint.');
        }

        $models = [];
        foreach ($response['data'] as $model) {
            $models[] = [
                'id' => (string) ($model['id'] ?? ''),
                'display_name' => (string) ($model['display_name'] ?? $model['id'] ?? ''),
            ];
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

        $body = [
            'model' => $modelId,
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        if ($effectiveSystem !== null) {
            $body['system'] = $effectiveSystem;
        }

        $response = $this->httpPost($url, $body, $timeout);

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

        return new ProviderResponse(
            content: $outputText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens
        );
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

    /**
     * @param array<int, string> $modelIds
     * @return array{cheap: string|null, capable: string|null, ocr: string|null}
     */
    public function resolveTiers(array $modelIds): array
    {
        $bestHaiku = null;
        $bestSonnet = null;
        $bestOcr = null;

        foreach ($modelIds as $id) {
            $lower = strtolower($id);

            if (str_contains($lower, 'ocr')) {
                if ($bestOcr === null || $this->extractDate($id) > $this->extractDate($bestOcr)) {
                    $bestOcr = $id;
                }
            }

            if (str_contains($lower, 'haiku')) {
                if ($bestHaiku === null || $this->extractDate($id) > $this->extractDate($bestHaiku)) {
                    $bestHaiku = $id;
                }
            } elseif (str_contains($lower, 'sonnet')) {
                if ($bestSonnet === null || $this->extractDate($id) > $this->extractDate($bestSonnet)) {
                    $bestSonnet = $id;
                }
            }
        }

        return [
            'cheap' => $bestHaiku,
            'capable' => $bestSonnet,
            'ocr' => $bestOcr,
        ];
    }

    /**
     * Extract the YYYYMMDD date suffix from an Anthropic model ID.
     * Models with "latest" in the name are considered the most recent.
     * e.g. "claude-3-5-haiku-20241022" → "20241022"
     * e.g. "claude-haiku-latest" → "99999999"
     */
    private function extractDate(string $modelId): string
    {
        if (stripos($modelId, 'latest') !== false) {
            return '99999999';
        }
        if (preg_match('/(\d{8})/', $modelId, $matches)) {
            return $matches[1];
        }
        return '00000000';
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGet(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                    'Content-Type: application/json',
                ]),
                'timeout' => self::DEFAULT_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw LlmException::timeout();
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw LlmException::apiError('Invalid JSON response from provider.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function httpPost(string $url, array $data, int $timeout): array
    {
        $jsonBody = json_encode($data, JSON_UNESCAPED_UNICODE);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonBody),
                ]),
                'content' => $jsonBody,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw LlmException::timeout();
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw LlmException::apiError('Invalid JSON response from provider.');
        }

        return $decoded;
    }
}
