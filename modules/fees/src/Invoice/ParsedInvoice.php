<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * A federation invoice, read.
 *
 * `$ignoredRowCount` is not noise: every reading counts the rows that
 * matched neither of the two shapes it knows (a page header, a footer, a
 * subtotal). The number itself means nothing — a jump in it from one
 * import to the next is the signal that the template changed, which is the
 * only warning this parser can give before it starts reading a document it
 * no longer understands.
 */
final class ParsedInvoice
{
    /** @param InvoiceLine[] $lines */
    public function __construct(
        public readonly ?string $documentNumber,
        public readonly ?string $issueDate,
        public readonly array $lines,
        public readonly int $totalCents,
        public readonly ?string $iban,
        public readonly ?string $structuredCommunication,
        public readonly ?string $templateNumber,
        public readonly int $ignoredRowCount
    ) {
    }

    /** @return InvoicePerson[] every name the document lists, in reading order */
    public function people(): array
    {
        $people = [];
        foreach ($this->lines as $line) {
            foreach ($line->people as $person) {
                $people[] = $person;
            }
        }

        return $people;
    }

    /** The sum of the line amounts — equal to the printed total, or the reading was refused. */
    public function recomputedTotalCents(): int
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $total += $line->amountCents;
        }

        return $total;
    }

    /** @return string[] the section codes the document names, without duplicates */
    public function sectionCodes(): array
    {
        $codes = [];
        foreach ($this->lines as $line) {
            if ($line->sectionCode !== null) {
                $codes[$line->sectionCode] = true;
            }
        }

        return array_keys($codes);
    }
}
