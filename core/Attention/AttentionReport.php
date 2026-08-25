<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

/**
 * Everything the attention-points page shows, for one display.
 *
 * `$degradedSources` is as much a part of the answer as `$points`: a
 * contributor that failed is stated on the page rather than dropped
 * silently. A page that quietly loses a contributor is a page that
 * quietly stops warning about whatever that contributor watched, and
 * nobody notices — which is the failure mode this design exists to avoid.
 */
final class AttentionReport
{
    /**
     * @param AttentionPoint[] $points ordered, most pressing first
     * @param string[] $degradedSources labels of the contributors that failed
     */
    public function __construct(
        public readonly array $points,
        public readonly array $degradedSources,
        public readonly \DateTimeImmutable $computedAt
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    public function count(): int
    {
        return count($this->points);
    }
}
