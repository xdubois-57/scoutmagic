<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmTier;

/**
 * Scaleway Generative APIs provider implementation.
 * Uses OpenAI-compatible endpoints (/v1/models and /v1/chat/completions).
 * European cloud — data stays in EU (France).
 */
class ScalewayProvider implements LlmProviderInterface
{
    private const DEFAULT_TIMEOUT = 30;

    private HttpTransport $http;
    /** @var array<string, true> */
    private array $rejectedChatModels = [];

    public function __construct(
        private string $apiEndpoint,
        private string $apiKey,
        /** @var list<string> Models carrying fresh image-probe evidence from the resolver. */
        private array $verifiedImageModels = []
    ) {
        $this->http = new HttpTransport();
    }

    public static function capabilityScope(string $endpoint, string $key): string
    {
        return hash('sha256', rtrim($endpoint, '/') . "\0" . $key);
    }

    public function probeScope(): string
    {
        return self::capabilityScope($this->apiEndpoint, $this->apiKey);
    }

    /** @return list<string> */
    public function rejectedChatModels(): array
    {
        return array_keys($this->rejectedChatModels);
    }

    /** The sole image preflight bypass sends a synthetic challenge, never caller data. */
    public function probeVision(string $modelId, VisionProbeChallenge $challenge): ModelProbeStatus
    {
        if (ScalewayModelCapabilities::forModel($modelId)['chat'] !== true) {
            return ModelProbeStatus::CHAT_UNSUPPORTED;
        }
        $content = $this->buildUserContent('Read the eight decimal digits from the image. Output only those digits.', [
            ['data' => $challenge->imageBase64, 'mime_type' => 'image/png'],
        ]);
        $body = $this->buildRequestBody($modelId, [['role' => 'user', 'content' => $content]], ['max_tokens' => 32]);
        try {
            $response = $this->http->postJson(
                rtrim($this->apiEndpoint, '/') . '/v1/chat/completions', $this->headers(), $body, 5
            );
            if (isset($response['error'])) {
                return ($response['error']['type'] ?? null) === 'route_not_supported'
                    ? ModelProbeStatus::CHAT_UNSUPPORTED : ModelProbeStatus::UNVERIFIED;
            }
            [$answer, $truncated] = $this->parseChoice($response);
            return !$truncated && trim($answer) === $challenge->answer
                ? ModelProbeStatus::VISION_VERIFIED : ModelProbeStatus::UNVERIFIED;
        } catch (LlmException $error) {
            return self::isUnsupportedRoute($error)
                ? ModelProbeStatus::CHAT_UNSUPPORTED : ModelProbeStatus::UNVERIFIED;
        }
    }

    public static function isUnsupportedRoute(LlmException $error): bool
    {
        $detail = json_decode($error->detail ?? '', true);
        return $error->getCode() === LlmException::API_ERROR
            && ($detail['error']['type'] ?? null) === 'route_not_supported';
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

    public function listModels(): ProviderModelCatalog
    {
        $url = rtrim($this->apiEndpoint, '/') . '/v1/models';
        $response = $this->http->getJson($url, $this->headers(), self::DEFAULT_TIMEOUT);

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw LlmException::apiError('Invalid response from the Scaleway models endpoint.');
        }

        $models = [];
        foreach ($response['data'] as $model) {
            if (!is_array($model) || !is_string($model['id'] ?? null) || $model['id'] === '') {
                // A model with no usable identifier cannot be called and must
                // never reach llm_provider_models.model_id.
                continue;
            }
            $id = $model['id'];
            $displayName = is_string($model['name'] ?? null) ? $model['name'] : $id;
            $capabilities = ScalewayModelCapabilities::forModel($id);
            $models[] = [
                'id' => $id,
                'display_name' => $displayName,
                'chat' => $capabilities['chat'],
                'vision' => null,
            ];
        }

        return new ProviderModelCatalog($models);
    }

    public function complete(string $modelId, string $prompt, array $options = []): ProviderResponse
    {
        // Also protect assignments saved before capability-aware discovery.
        // This guard runs before any prompt or image can leave the site.
        $capabilities = ScalewayModelCapabilities::forModel($modelId);
        if ($capabilities['chat'] !== true || isset($this->rejectedChatModels[$modelId])) {
            throw LlmException::noModel(LlmTier::CHEAP);
        }
        foreach ($options['attachments'] ?? [] as $attachment) {
            if (str_starts_with($attachment['mime_type'], 'image/')
                && !in_array($modelId, $this->verifiedImageModels, true)) {
                throw LlmException::noModel(LlmTier::OCR);
            }
        }

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

        try {
            $response = $this->http->postJson($url, $this->headers(), $body, $timeout);
            // Some endpoints return an error object with HTTP 200.
            if (isset($response['error'])) {
                throw LlmException::apiError(json_encode($response, JSON_THROW_ON_ERROR));
            }
        } catch (LlmException $error) {
            if (self::isUnsupportedRoute($error)) {
                $this->rejectedChatModels[$modelId] = true;
            }
            throw $error;
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
            // Always sent explicitly — LlmConnectorService::complete() always
            // populates $options['max_tokens'], but the 4096 fallback here
            // guards a provider called directly with no options too. Never
            // omitted: without it, this API falls back to its own
            // server-side default, which can be far too small for a
            // long-form request and silently truncate an otherwise-valid
            // (HTTP 200) response.
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
            $parts[] = "You MUST respond with valid JSON conforming exactly to this schema:\n"
                . $schemaJson
                . "\nDo not include any text outside the JSON object.";
        }

        return $parts !== [] ? implode("\n\n", $parts) : null;
    }
}
