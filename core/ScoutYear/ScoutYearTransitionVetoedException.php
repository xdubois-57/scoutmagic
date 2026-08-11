<?php

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * Thrown by ScoutYearAdminService::activatePublicYear() when the
 * registration module (if enabled) reports open registration requests —
 * ARCHITECTURE.md §8.37/§8.38's step-4 veto. Carries the blocking count so
 * the catching controller can build a precise, actionable flash message
 * without a second lookup.
 */
final class ScoutYearTransitionVetoedException extends \RuntimeException
{
    public function __construct(private readonly int $blockingRequestCount)
    {
        parent::__construct("Scout year transition vetoed: {$blockingRequestCount} blocking registration request(s).");
    }

    public function getBlockingRequestCount(): int
    {
        return $this->blockingRequestCount;
    }
}
