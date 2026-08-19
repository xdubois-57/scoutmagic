<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/**
 * Internal interface for LLM provider implementations.
 * Private to the module — never imported by consuming modules.
 *
 * Deliberately carries no tier-resolution method: which model backs which
 * tier is decided once, for every provider alike, by
 * Service\OcrModelSelector. Each driver used to also implement its own
 * resolveTiers() heuristic, which nothing ever called — the two disagreed
 * (notably about the OCR tier), so editing a driver's version looked like it
 * changed behaviour while changing nothing at all.
 */
interface LlmProviderInterface
{
    /**
     * List available models from the provider's API.
     *
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listModels(): array;

    /**
     * Send a completion request to a specific model.
     *
     * @param string $modelId The exact model identifier (API ID)
     * @param string $prompt The user prompt
     * @param array<string, mixed> $options Additional options (system_prompt, attachments, response_schema, timeout)
     */
    public function complete(string $modelId, string $prompt, array $options = []): ProviderResponse;
}
