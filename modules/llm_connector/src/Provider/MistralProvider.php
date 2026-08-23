<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ];
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModels(): array
    {
        $url = rtrim($this->apiEndpoint, '/') . '/v1/models';
        $response = $this->http->getJson($url, $this->headers(), self::DEFAULT_TIMEOUT);

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw LlmException::apiError('Invalid response from the Mistral models endpoint.');
        }

        $models = [];
        foreach ($response['data'] as $model) {
            $id = (string) ($model['id'] ?? '');
            if ($id === '') {
                // A model with no usable identifier cannot be called and must
                // never reach llm_provider_models.model_id.
                continue;
            }
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
        $attachments = $options['attachments'] ?? [];
        $responseSchema = $options['response_schema'] ?? null;

        $messages = [];

        $effectiveSystem = $this->buildSystemPrompt($systemPrompt, $responseSchema);
        if ($effectiveSystem !== null) {
            $messages[] = ['role' => 'system', 'content' => $effectiveSystem];
        }

        $messages[] = ['role' => 'user', 'content' => $this->buildUserContent($prompt, $attachments)];

        $body = $this->buildRequestBody($modelId, $messages, $options);

        $response = $this->http->postJson($url, $this->headers(), $body, $timeout);

        // A non-2xx never reaches this point (HttpTransport rejects it);
        // this still catches an error object returned with a 200.
        if (isset($response['error'])) {
            $errorMsg = $response['error']['message'] ?? 'Unknown error';
            throw LlmException::apiError($errorMsg);
        }

        [$outputText, $truncated] = $this->parseChoice($response);

        $inputTokens = (int) ($response['usage']['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($response['usage']['completion_tokens'] ?? 0);

        return new ProviderResponse(
            content: $outputText,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            truncated: $truncated
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRequestBody(string $modelId, array $messages, array $options): array
    {
        return [
            'model' => $modelId,
            'messages' => $messages,
            // See ScalewayProvider::buildRequestBody() — same "never omit
            // this, the provider's own default may silently truncate a
            // long-form response" reasoning applies here.
            'max_tokens' => (int) ($options['max_tokens'] ?? 4096),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array{0: string, 1: bool} [content, truncated]
     */
    private function parseChoice(array $response): array
    {
        $choices = $response['choices'] ?? [];
        if (empty($choices)) {
            return ['', false];
        }

        $content = (string) ($choices[0]['message']['content'] ?? '');
        $truncated = ($choices[0]['finish_reason'] ?? null) === 'length';

        return [$content, $truncated];
    }

    /**
     * OpenAI-compatible multi-part content: image attachments as
     * image_url blocks (data URI), followed by the text prompt. Falls
     * back to a plain string when there are no image attachments,
     * preserving the exact prior wire format for non-multimodal calls.
     * Non-image attachments (e.g. a PDF) are skipped — the chat
     * completions endpoint has no document type, unlike Anthropic's
     * Messages API (Provider\AnthropicProvider::buildContentBlocks()).
     *
     * @param array<int, array{data: string, mime_type: string}> $attachments
     * @return string|array<int, array<string, mixed>>
     */
    private function buildUserContent(string $prompt, array $attachments): string|array
    {
        $blocks = [];
        foreach ($attachments as $attachment) {
            if (!str_starts_with($attachment['mime_type'], 'image/')) {
                continue;
            }
            $blocks[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:' . $attachment['mime_type'] . ';base64,' . $attachment['data']],
            ];
        }

        if ($blocks === []) {
            return $prompt;
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
}
