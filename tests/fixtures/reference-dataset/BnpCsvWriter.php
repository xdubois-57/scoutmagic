<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Writes a list of movements in BNP Paribas Fortis's export shape.
 *
 * Copied from a real export via `tests/fixtures/finance/bnp_statement_sample.csv`,
 * and from BnpParser's own column map. Three details are reproduced rather
 * than tidied up, because the parser depends on all three:
 *
 *   - **the UTF-8 BOM**, which readRows() detects and skips;
 *   - **`Nº de séquence` identical on every row**. It looks like a unique id
 *     and is not — which is exactly why the deduplication key lives in the
 *     Détails column instead;
 *   - **the account's own IBAN repeated on every row**, since the format has
 *     no header for it. ImportService::extractSourceIban() reads the first
 *     one and refuses the import if it does not match the account chosen.
 */
final class BnpCsvWriter
{
    private const DELIMITER = ';';

    /** @var list<string> */
    private const HEADERS = [
        'Nº de séquence', "Date d'exécution", 'Date valeur', 'Montant',
        'Devise du compte', 'Numéro de compte', 'Type de transaction',
        'Contrepartie', 'Nom de la contrepartie', 'Communication',
        'Détails', 'Statut', 'Motif du refus',
    ];

    /**
     * @param list<StatementDraft> $lines
     */
    public function write(array $lines, string $accountIban, string $yearLabel): string
    {
        $sequence = UnitBlueprint::referenceYear($yearLabel) + 1 . '-';
        $rows = [implode(self::DELIMITER, self::HEADERS)];

        foreach ($lines as $line) {
            $rows[] = implode(self::DELIMITER, array_map(self::escape(...), [
                $sequence,
                $line->date,
                $line->valueDate,
                $line->amount,
                'EUR',
                BankBlueprint::compactIban($accountIban),
                $line->transactionType,
                BankBlueprint::compactIban($line->counterpartyIban),
                $line->counterpartyName,
                $line->communication,
                self::details($line),
                $line->status,
                $line->refusalReason,
            ]));
        }

        // BOM first, exactly as the bank writes it.
        return "\xEF\xBB\xBF" . implode("\n", $rows) . "\n";
    }

    /**
     * The Détails column, in the bank's own run-on prose. It carries the IBAN
     * with spaces even though the "Contrepartie" column does not — the reason
     * BnpParser normalises the account IBAN instead of trimming it.
     */
    private static function details(StatementDraft $line): string
    {
        $parts = [strtoupper($line->transactionType)];

        if ($line->counterpartyIban !== '') {
            $parts[] = 'AU COMPTE ' . strtoupper($line->counterpartyIban);
        }
        if ($line->counterpartyName !== '') {
            $parts[] = strtoupper($line->counterpartyName);
        }
        if ($line->communication !== '') {
            $parts[] = 'COMMUNICATION : ' . strtoupper($line->communication);
        }

        $parts[] = 'REFERENCE BANQUE : ' . $line->reference;
        $parts[] = 'DATE VALEUR : ' . $line->valueDate;

        // Trailing space included: the real export has one, and a parser that
        // stopped trimming would be caught here rather than in production.
        return implode(' ', $parts) . ' ';
    }

    private static function escape(string $value): string
    {
        if (!str_contains($value, self::DELIMITER)
            && !str_contains($value, '"')
            && !str_contains($value, "\n")
        ) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
