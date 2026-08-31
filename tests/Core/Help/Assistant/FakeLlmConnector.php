<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Assistant;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;

/**
 * A scripted LlmConnectorInterface: the assistant's tests decide what
 * each tier answers, and read back the prompts it was actually sent.
 *
 * The prompts matter as much as the answers here. « No unit data ever
 * enters a prompt » and « the catalogue is filtered by role » are claims
 * about what was SENT, and the only way to test them is to keep it.
 */
final class FakeLlmConnector implements LlmConnectorInterface
{
    /** @var array<string, LlmResponse|LlmException> tier value => scripted outcome */
    private array $scripted = [];

    /** @var LlmRequest[] every request, in order */
    public array $requests = [];

    /** @var array<string, bool> tier value => available */
    private array $tiers = ['cheap' => true, 'capable' => true, 'ocr' => true];

    public function willAnswer(LlmTier $tier, LlmResponse|LlmException $outcome): self
    {
        $this->scripted[$tier->value] = $outcome;

        return $this;
    }

    public function withTierUnavailable(LlmTier $tier): self
    {
        $this->tiers[$tier->value] = false;

        return $this;
    }

    /** The prompt sent to one tier, or null when that tier was never called. */
    public function promptFor(LlmTier $tier): ?string
    {
        foreach ($this->requests as $request) {
            if ($request->tier === $tier) {
                return $request->prompt;
            }
        }

        return null;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function isAvailable(): bool
    {
        return in_array(true, $this->tiers, true);
    }

    public function isTierAvailable(LlmTier $tier): bool
    {
        return $this->tiers[$tier->value] ?? false;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $outcome = $this->scripted[$request->tier->value] ?? null;
        if ($outcome instanceof LlmException) {
            throw $outcome;
        }
        if ($outcome === null) {
            throw new LlmException('Aucune réponse programmée pour ce palier dans le test.');
        }

        return $outcome;
    }

    /**
     * The selection call's shape: a JSON object with an `ids` array,
     * already decoded into `parsed` the way LlmConnectorService does when
     * a responseSchema was given.
     *
     * @param string[] $ids
     */
    public static function selection(array $ids): LlmResponse
    {
        return new LlmResponse(
            content: (string) json_encode(['ids' => $ids]),
            parsed: ['ids' => array_values($ids)],
            inputTokens: 100,
            outputTokens: 10,
        );
    }

    public static function answer(string $text, bool $truncated = false): LlmResponse
    {
        return new LlmResponse(
            content: $text,
            parsed: null,
            inputTokens: 400,
            outputTokens: 60,
            truncated: $truncated,
        );
    }
}
