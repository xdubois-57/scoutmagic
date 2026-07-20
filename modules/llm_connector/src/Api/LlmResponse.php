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
     */
    public function __construct(
        public readonly string $content,
        public readonly ?array $parsed,
        public readonly int $inputTokens,
        public readonly int $outputTokens
    ) {
    }
}
