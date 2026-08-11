<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * @param bool $truncated True when the provider stopped early because it
     *        hit the max_tokens cap, not because generation finished naturally
     */
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $truncated = false
    ) {
    }
}
