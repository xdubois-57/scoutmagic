<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Value\StoredInvoice;

/**
 * The season, read in the order it happened: the November deposit, the
 * final invoices of January and February, the last quarter's
 * regularisation.
 *
 * **The running total sums each invoice's own TOTAL, and that is the whole
 * subtlety.** The deposit is deducted inside the final invoice by a
 * negative line, so a final of 1 069,00 € already has the 300,00 € deposit
 * taken out of it. Adding the deposit to the final's *gross* would count
 * that 300 twice — 1 669,00 € for a season that cost 1 369,00 €. Summing
 * the totals, which each already net out their own negative lines, is what
 * gives the amount the unit actually paid.
 */
class InvoiceSeasonService
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    /**
     * @return array<array{invoice: StoredInvoice, running_total_cents: int}>
     */
    public function sequence(int $scoutYearId): array
    {
        $rows = [];
        $running = 0;
        foreach ($this->invoices->findAllForYear($scoutYearId) as $invoice) {
            $running += $invoice->totalCents;
            $rows[] = ['invoice' => $invoice, 'running_total_cents' => $running];
        }

        return $rows;
    }

    /** What the season has cost so far, deposits already netted out. */
    public function netTotalCents(int $scoutYearId): int
    {
        $total = 0;
        foreach ($this->invoices->findAllForYear($scoutYearId) as $invoice) {
            $total += $invoice->totalCents;
        }

        return $total;
    }
}
