<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     *
     * Never throws: a provider whose stored API key cannot be decrypted (the
     * encryption key changed, e.g. after restoring a backup onto another
     * install) counts as unavailable, not as an error. Callers use this to
     * decide whether to offer a feature at all, and several do so while
     * rendering a page — including the public retrospective board.
     */
    public function isAvailable(): bool;

    /**
     * Whether a request for this specific tier would find a model.
     *
     * isAvailable() only answers "is anything configured at all", so a caller
     * that always asks for one particular tier (RGPD generation is the only
     * consumer of CAPABLE) could pass that check and still have complete()
     * throw NO_MODEL. Applies the same OCR → CHEAP fallback complete() does.
     * Never throws, for the same reason as isAvailable().
     */
    public function isTierAvailable(LlmTier $tier): bool;

    /**
     * Send a completion request to the configured LLM.
     *
     * @throws LlmException When no provider/model is available or the API call fails
     */
    public function complete(LlmRequest $request): LlmResponse;
}
