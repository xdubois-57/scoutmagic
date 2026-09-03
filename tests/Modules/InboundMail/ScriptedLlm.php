<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;

/**
 * A model that answers one scripted choice — what the « last resort »
 * pickers of the consuming modules (`Mail\BookingChoiceByModel`,
 * `Mail\StayChoiceByModel`) are tested against. Implements the
 * connector's PUBLISHED contract and nothing else (§7.5).
 */
final class ScriptedLlm implements LlmConnectorInterface
{
    public int $calls = 0;

    public ?LlmRequest $lastRequest = null;

    public function __construct(
        private ?string $choice,
        private bool $available = true,
        private ?LlmException $throw = null
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function isTierAvailable(LlmTier $tier): bool
    {
        return $this->available;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->calls++;
        $this->lastRequest = $request;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $parsed = ['choice' => $this->choice ?? ''];

        return new LlmResponse((string) json_encode($parsed), $parsed, 0, 0);
    }
}
