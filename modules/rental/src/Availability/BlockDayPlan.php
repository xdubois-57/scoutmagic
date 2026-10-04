<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Availability;

/**
 * What a calendar gesture changes (`BlockDayPlanner::plan()`): the periods
 * it rebuilds, the ones they replace, and the days that actually changed.
 */
final class BlockDayPlan
{
    /**
     * @param int[] $replacedBlockIds The periods the gesture touched or bordered, all rebuilt.
     * @param list<array{start: string, end: string, reason: string|null}> $periods What replaces them.
     * @param array<string, string|null> $changed Each day whose state changed, with the reason it carries now (a
     *     blocked day) or carried before (a released one) — what an undo needs to put everything back.
     */
    public function __construct(
        public readonly array $replacedBlockIds,
        public readonly array $periods,
        public readonly array $changed
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->changed === [];
    }
}
