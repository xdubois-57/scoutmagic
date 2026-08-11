<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security\HumanCheck;

/**
 * Accepted, or rejected with a machine-readable reason for the caller to
 * log — never an exception, since a rejection here is an expected outcome
 * (a legitimate but slow human, not a programming error). The reason is
 * for internal use only: HumanCheckService callers must show the same
 * generic French message regardless of $reason, so a bot can never learn
 * which of the three barriers it tripped.
 */
final class HumanCheckResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $reason
    ) {
    }

    public static function accepted(): self
    {
        return new self(true, null);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, $reason);
    }
}
