<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * One line of a bank statement, before it is written in BNP's shape.
 *
 * `amount` is a formatted string rather than a float on purpose: two of the
 * required cases are about the FORMATTING of the number — a thousands
 * separator (`1.234,56`) and a dot-decimal (`35.98`, which
 * BnpParser::parseAmount() must read as 35.98 and not 3598) — and a float
 * cannot carry that distinction.
 *
 * `reference` becomes the `REFERENCE BANQUE : …` fragment of the Détails
 * column, which is the bank's only truly unique per-line value and therefore
 * ImportService's deduplication key. "Nº de séquence" is not usable: BNP
 * Fortis writes the same string on every row of an export.
 */
final class StatementDraft
{
    public function __construct(
        public readonly string $date,
        public readonly string $valueDate,
        public readonly string $amount,
        public readonly string $transactionType,
        public readonly string $counterpartyIban,
        public readonly string $counterpartyName,
        public readonly string $communication,
        public readonly string $reference,
        public readonly string $status = 'Accepté',
        public readonly string $refusalReason = '',
    ) {
    }
}
