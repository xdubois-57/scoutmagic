<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

/**
 * The three `settings` keys recording the outcome of the last reporting
 * attempt (ARCHITECTURE.md §8.46).
 *
 * A tiny holder rather than constants on either side, because both the
 * writer (Core\Statistics\StatisticsSender) and the reader
 * (Core\Http\Controller\SupportController) need the same names — and a
 * service reaching into a controller for a constant would be exactly the
 * layering inversion this codebase does not allow.
 */
final class StatisticsStateSettings
{
    public const LAST_SUCCESS_AT = 'statistics_last_success_at';
    public const LAST_FAILURE_AT = 'statistics_last_failure_at';
    public const LAST_FAILURE_REASON = 'statistics_last_failure_reason';
}
