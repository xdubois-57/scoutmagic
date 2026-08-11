<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * Core\Member\FeeEstimationService's result — carries the household size
 * alongside the suggested category so a calling screen can explain where
 * the suggestion comes from (never present it as an unexplained decision).
 */
final class FeeEstimate
{
    public function __construct(
        public readonly HouseholdFeeCategory $category,
        public readonly int $householdSize
    ) {
    }
}
