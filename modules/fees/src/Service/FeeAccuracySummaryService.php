<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Modules\Fees\Api\FeeAccuracySummary;
use Modules\Fees\Api\FeeAccuracySummaryProvider;

/**
 * The module's implementation of its own Api\FeeAccuracySummaryProvider
 * (ARCHITECTURE.md §7.5). A façade that computes nothing of its own: it
 * counts the buckets of FeeAccuracyService::report(), exactly as
 * FeesAttentionProvider does, because a second notion of "which households
 * are wrong" would drift from the page within a season.
 */
class FeeAccuracySummaryService implements FeeAccuracySummaryProvider
{
    public function __construct(private FeeAccuracyService $accuracy)
    {
    }

    public function summaryForYear(int $scoutYearId): FeeAccuracySummary
    {
        $report = $this->accuracy->report($scoutYearId);

        return new FeeAccuracySummary(
            count($report->toCorrect),
            $report->withoutAddressCount(),
            count($report->ignored),
            $report->householdCount
        );
    }
}
