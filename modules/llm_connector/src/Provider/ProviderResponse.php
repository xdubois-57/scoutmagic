<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/**
 * Internal DTO returned by a provider implementation.
 * Not part of the public API — only used within the module.
 */
class ProviderResponse
{
    /**
     * @param string $content Raw text content
     * @param int $inputTokens Input tokens consumed
     * @param int $outputTokens Output tokens produced
     */
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens,
        public readonly int $outputTokens
    ) {
    }
}
