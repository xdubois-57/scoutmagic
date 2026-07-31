<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Api;

/**
 * DTO representing a response from the LLM connector.
 */
class LlmResponse
{
    /**
     * @param string $content Raw text content from the model
     * @param array<string, mixed>|null $parsed Decoded JSON if responseSchema was provided
     * @param int $inputTokens Number of input tokens consumed
     * @param int $outputTokens Number of output tokens produced
     * @param bool $truncated True when the provider stopped generating because it
     *        hit the max_tokens cap (finish_reason "length" / stop_reason
     *        "max_tokens") rather than finishing naturally — $content is
     *        then an incomplete response, not a full one. A caller expecting
     *        a long-form reply should treat this as a failure (e.g. via
     *        LlmRequest::$maxTokens) rather than use $content as-is.
     */
    public function __construct(
        public readonly string $content,
        public readonly ?array $parsed,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $truncated = false
    ) {
    }
}
