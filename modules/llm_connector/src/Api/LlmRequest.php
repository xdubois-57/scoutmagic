<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Api;

/**
 * DTO representing a request to the LLM connector.
 * This is the public contract — consuming modules build one of these.
 */
class LlmRequest
{
    /**
     * @param LlmTier $tier Which capability tier to use
     * @param string $prompt The user prompt
     * @param array<int, array{data: string, mime_type: string}> $attachments Base64-encoded files with MIME type
     * @param string|null $systemPrompt Optional system prompt
     * @param array<string, mixed>|null $responseSchema JSON Schema to force structured output
     * @param int|null $timeoutSeconds Optional override for the provider's HTTP timeout
     *        (falls back to the provider's own default when null). Use for
     *        requests with unusually large prompts that may need more time.
     * @param int|null $maxTokens Optional override for the provider's output-length
     *        cap (falls back to LlmConnectorService::DEFAULT_MAX_TOKENS when
     *        null — every provider is always sent an explicit value, never
     *        left to its own server-side default). Use a higher value for
     *        requests expecting a long-form response (e.g. a full generated
     *        document) — the default is tuned for short/typical replies.
     */
    public function __construct(
        public readonly LlmTier $tier,
        public readonly string $prompt,
        public readonly array $attachments = [],
        public readonly ?string $systemPrompt = null,
        public readonly ?array $responseSchema = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?int $maxTokens = null
    ) {
    }
}
