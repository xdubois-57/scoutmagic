<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Support;

use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;

/**
 * A connector that answers whatever a test wants and records what it was
 * asked. Implements the module's PUBLISHED contract and nothing else
 * (ARCHITECTURE.md §7.5) — which is the point: a consumer that can be
 * tested against `Api\LlmConnectorInterface` alone is a consumer that never
 * named an internal.
 */
final class FakeLlmConnector implements LlmConnectorInterface
{
    public int $calls = 0;

    /** @var LlmTier[] */
    public array $tiersAsked = [];

    public ?LlmRequest $lastRequest = null;

    public function __construct(
        private bool $tierAvailable,
        private string $content = '{}',
        private ?LlmException $throw = null
    ) {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isTierAvailable(LlmTier $tier): bool
    {
        $this->tiersAsked[] = $tier;

        return $this->tierAvailable;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->calls++;
        $this->lastRequest = $request;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new LlmResponse($this->content, null, 0, 0);
    }
}
