<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;

/**
 * Mistral AI provider implementation.
 * Calls /v1/models and /v1/chat/completions on the configured endpoint.
 */
class MistralProvider implements LlmProviderInterface
{
    private const DEFAULT_TIMEOUT = 30;

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
            $id = (string) ($model['id'] ?? '');
            $models[] = [
                'id' => $id,
                'display_name' => $id,
            ];
        }

        return $models;
    }

    public function complete(string $modelId, string $prompt, array $options = []): ProviderResponse
    {
        $url = rtrim($this->apiEndpoint, '/') . '/v1/chat/completions';
        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $systemPrompt = $options['system_prompt'] ?? null;
        $responseSchema = $options['response_schema'] ?? null;

        $messages = [];

        $effectiveSystem = $this->buildSystemPrompt($systemPrompt, $responseSchema);
        if ($effectiveSystem !== null) {
            $messages[] = ['role' => 'system', 'content' => $effectiveSystem];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $body = [
            'model' => $modelId,
            'messages' => $messages,
        ];

        $response = $this->httpPost($url, $body, $timeout);

        if (isset($response['error'])) {
            $errorMsg = $response['error']['message'] ?? 'Unknown error';
            throw LlmException::apiError($errorMsg);
        }

        $outputText = '';
        $choices = $response['choices'] ?? [];
        if (!empty($choices)) {
            $outputText = (string) ($choices[0]['message']['content'] ?? '');
        }

        $inputTokens = (int) ($response['usage']['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($response['usage']['completion_tokens'] ?? 0);

        return new ProviderResponse(
            content: $outputText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens
        );
    }

    /**
     * @param array<int, string> $modelIds
     * @return array{cheap: string|null, capable: string|null}
     */
    public function resolveTiers(array $modelIds): array
    {
        $bestSmall = null;
        $bestLarge = null;

        foreach ($modelIds as $id) {
            $lower = strtolower($id);

            // Skip non-chat models, fine-tuned models, embedding models
            if (str_contains($lower, 'embed')
                || str_contains($lower, 'moderation')
                || str_contains($lower, 'codestral')
            ) {
                continue;
            }

            if (str_contains($lower, 'small')) {
                if ($bestSmall === null || $this->extractDate($id) > $this->extractDate($bestSmall)) {
                    $bestSmall = $id;
                }
            } elseif (str_contains($lower, 'large') || str_contains($lower, 'medium')) {
                if ($bestLarge === null || $this->extractDate($id) > $this->extractDate($bestLarge)) {
                    $bestLarge = $id;
                }
            }
        }

        return [
            'cheap' => $bestSmall,
            'capable' => $bestLarge,
        ];
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
     * Extract a YYYYMMDD date from a model ID, or a YYMM pattern.
     */
    private function extractDate(string $modelId): string
    {
        if (preg_match('/(\d{8})/', $modelId, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d{4})$/', $modelId, $matches)) {
            return $matches[1] . '0000';
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
                    'Authorization: Bearer ' . $this->apiKey,
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
                    'Authorization: Bearer ' . $this->apiKey,
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
