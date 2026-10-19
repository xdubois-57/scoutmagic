<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Api;

/**
 * The module's first public API (ARCHITECTURE.md §7.5): how « Justesse des
 * tarifs » reads for one scout year, as three counts — what the "Année
 * scoute" workflow shows beside its fees step without ever knowing what a
 * household or a tariff is.
 *
 * Wired as a nullable dependency in the composition root, only when this
 * module is enabled; with it null, the workflow has no fees step at all.
 *
 * Counts only, never a name, an address or an amount: the euros belong on
 * the module's own page, next to the barème they are computed from, and
 * invoices and payments are no part of this question.
 */
interface FeeAccuracySummaryProvider
{
    /**
     * The summary of the module's own report for $scoutYearId — the same
     * service the « Justesse des tarifs » page reads, so the two can never
     * disagree. Meaningful only once that year has been imported: with no
     * member, every count is zero and says nothing.
     */
    public function summaryForYear(int $scoutYearId): FeeAccuracySummary;
}
