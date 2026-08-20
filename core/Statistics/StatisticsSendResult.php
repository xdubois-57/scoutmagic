<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

/**
 * The outcome of one attempt to report usage statistics.
 *
 * Three outcomes, not two: a *skip* is not a failure. "Reporting is off",
 * "this is a development build", "we already reported today" are all
 * perfectly healthy states, and collapsing them into "failed" would make
 * the Support page cry wolf on every ordinary day.
 */
final class StatisticsSendResult
{
    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly ?int $statusCode = null,
        public readonly ?int $durationMs = null
    ) {
    }

    public static function sent(int $statusCode, int $durationMs): self
    {
        return new self(self::OUTCOME_SENT, null, $statusCode, $durationMs);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::OUTCOME_SKIPPED, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(self::OUTCOME_FAILED, $reason);
    }

    public function isSent(): bool
    {
        return $this->outcome === self::OUTCOME_SENT;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === self::OUTCOME_SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::OUTCOME_FAILED;
    }
}
