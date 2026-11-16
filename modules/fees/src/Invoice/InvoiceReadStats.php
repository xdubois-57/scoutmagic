<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * What a reading saw, in counts and yes/no — never a word of the document
 * (#1327).
 *
 * A refused invoice cannot be attached to a bug report: it lists children,
 * their birth dates and the unit's bank details. What the journal keeps
 * instead is enough to tell the support WHICH shape of document came in —
 * one row per line or cells glued together, decimal quantities, page-break
 * repetitions, a total found or not — so a new layout can be recognised
 * without anybody sending the PDF.
 */
final class InvoiceReadStats
{
    public int $extractedLines = 0;
    public int $nonEmptyLines = 0;
    public ?int $pageCount = null;
    public int $tariffRows = 0;
    public int $distinctTariffLines = 0;
    public int $repeatedTariffRows = 0;
    public int $personRows = 0;
    public int $orphanPersonRows = 0;
    public int $ignoredRows = 0;
    /** @var int[] line numbers (in the extracted text) of rows that look financial and were not read */
    public array $unrecognisedFinancialRows = [];
    public int $gluedTariffRows = 0;
    public int $spacedTariffRows = 0;
    public int $decimalQuantities = 0;
    public int $negativeQuantities = 0;
    public int $groupSeparatedNumbers = 0;
    public bool $tabsSeen = false;
    public bool $totalFound = false;
    public bool $documentNumberFound = false;

    /**
     * @return array<string, int|bool|null|int[]>
     */
    public function toArray(): array
    {
        return [
            'extracted_lines' => $this->extractedLines,
            'non_empty_lines' => $this->nonEmptyLines,
            'pages' => $this->pageCount,
            'tariff_rows' => $this->tariffRows,
            'distinct_tariff_lines' => $this->distinctTariffLines,
            'repeated_tariff_rows' => $this->repeatedTariffRows,
            'person_rows' => $this->personRows,
            'orphan_person_rows' => $this->orphanPersonRows,
            'ignored_rows' => $this->ignoredRows,
            'unrecognised_financial_rows' => count($this->unrecognisedFinancialRows),
            'unrecognised_financial_row_numbers' => array_slice($this->unrecognisedFinancialRows, 0, 20),
            'glued_tariff_rows' => $this->gluedTariffRows,
            'spaced_tariff_rows' => $this->spacedTariffRows,
            'decimal_quantities' => $this->decimalQuantities,
            'negative_quantities' => $this->negativeQuantities,
            'group_separated_numbers' => $this->groupSeparatedNumbers,
            'tabs_seen' => $this->tabsSeen,
            'total_found' => $this->totalFound,
            'document_number_found' => $this->documentNumberFound,
        ];
    }
}
