<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * One data line of a Desk export, as read — before any grouping by Tiers.
 *
 * Kept next to the grouped members so that {@see DeskImportValidator} can
 * refuse a file line by line: grouping throws away exactly what a refusal
 * has to name (which line, how many cells it really had, what a later
 * line of the same Tiers said differently).
 */
final class DeskCsvRow
{
    /**
     * @param int $lineNumber the physical line in the file, the header being
     *                        line 1 — the number a spreadsheet shows beside it
     * @param int $fieldCount how many cells the line really carried, which
     *                        $values cannot tell: it is padded or cut to the
     *                        header
     * @param array<string, string> $values trimmed, keyed by header
     * @param bool $extraCellsEmpty whether every cell past the header's last
     *                              column is empty — Desk ends each line with
     *                              a « ; », which reads as one empty cell more
     */
    public function __construct(
        public readonly int $lineNumber,
        public readonly int $fieldCount,
        public readonly array $values,
        public readonly bool $extraCellsEmpty = true
    ) {
    }

    public function value(string $column): string
    {
        return $this->values[$column] ?? '';
    }
}
