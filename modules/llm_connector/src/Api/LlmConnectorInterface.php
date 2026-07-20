<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Api;

/**
 * Public contract for consuming modules.
 * The only entry point into the LLM connector module.
 */
interface LlmConnectorInterface
{
    /**
     * Whether the connector is operational (at least one active provider
     * with at least one model assigned to a tier).
     */
    public function isAvailable(): bool;

    /**
     * Send a completion request to the configured LLM.
     *
     * @throws LlmException When no provider/model is available or the API call fails
     */
    public function complete(LlmRequest $request): LlmResponse;
}
