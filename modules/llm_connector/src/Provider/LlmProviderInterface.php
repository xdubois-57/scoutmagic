<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

/**
 * Internal interface for LLM provider implementations.
 * Private to the module — never imported by consuming modules.
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

    /**
     * Given a list of model IDs, return the best model for each tier.
     *
     * @param array<int, string> $modelIds List of available model IDs
     * @return array{cheap: string|null, capable: string|null, ocr: string|null}
     */
    public function resolveTiers(array $modelIds): array;
}
