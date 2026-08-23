<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\IbanNormalizer;

/**
 * BNP Paribas Fortis CSV export parser — semicolon-delimited, UTF-8 with
 * BOM, amounts with comma decimal separator, one row per transaction with
 * the account's own IBAN repeated on every row (column "Numéro de
 * compte" — there is no dedicated header row for it). Columns confirmed
 * against a real export: "Nº de séquence;Date d'exécution;Date
 * valeur;Montant;Devise du compte;Numéro de compte;Type de
 * transaction;Contrepartie;Nom de la contrepartie;Communication;
 * Détails;Statut;Motif du refus".
 *
 * "Nº de séquence" is not usable as a dedup key — BNP Fortis exports it
 * identically ("2026-") on every row. The bank's true unique per-line
 * reference is embedded inside "Détails" as "REFERENCE BANQUE : <digits>".
 *
 * **Changing anything here changes the reference dataset too.**
 * `tests/fixtures/reference-dataset/` holds six committed statements built to
 * this exact shape, deliberately containing the cases this parser exists to
 * survive: a `Refusé` line, a thousands separator, a dot-decimal, a line with
 * no communication, a transfer between two of the unit's own accounts, and the
 * tail of each year repeated at the head of the next so the REFERENCE BANQUE
 * deduplication is exercised. Its README is the manual, AGENTS.md § Reference
 * dataset says what to check, and `Tests\Integration\ReferenceDatasetFormatTest`
 * fails on the pull request that breaks it.
 */
final class BnpParser implements BankStatementParserInterface
{
    private const COL_EXECUTION_DATE = 1;
    private const COL_VALUE_DATE = 2;
    private const COL_AMOUNT = 3;
    private const COL_ACCOUNT_NUMBER = 5;
    private const COL_TRANSACTION_TYPE = 6;
    private const COL_COUNTERPARTY_ACCOUNT = 7;
    private const COL_COUNTERPARTY_NAME = 8;
    private const COL_COMMUNICATION = 9;
    private const COL_DETAILS = 10;
    private const COL_STATUS = 11;

    /**
     * Normalized (uppercase, no spaces or punctuation), because
     * Service\ImportService::verifyIban() compares its blind index to the
     * account's own IBAN — which Service\IbanNormalizer already stored in
     * exactly that form. A trim() alone was enough only as long as this
     * column happens to arrive unformatted; the "Détails" column of the
     * same export does write IBANs as "BE00 0000 0000 0002", and a
     * space-separated value here would have failed every import of the
     * right file with a misleading "IBAN mismatch" and no way to override.
     */
    public function extractSourceIban(string $filePath): string
    {
        foreach ($this->readRows($filePath) as $row) {
            $iban = IbanNormalizer::normalize($row[self::COL_ACCOUNT_NUMBER] ?? '');
            if ($iban !== '') {
                return $iban;
            }
        }

        throw new FinanceException("Impossible de trouver l'IBAN du compte dans le fichier BNP.");
    }

    public function parse(string $filePath): array
    {
        $lines = [];

        foreach ($this->readRows($filePath) as $row) {
            $status = trim($row[self::COL_STATUS] ?? '');
            if ($status !== '' && $status !== 'Accepté') {
                // Refused/pending lines never happened on the account — skip them.
                continue;
            }

            $dateStr = trim($row[self::COL_EXECUTION_DATE] ?? '');
            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateStr);
            if ($date === false) {
                throw new FinanceException("Date invalide dans le relevé BNP : \"{$dateStr}\".");
            }

            $amount = $this->parseAmount((string) ($row[self::COL_AMOUNT] ?? ''));

            $communication = trim($row[self::COL_COMMUNICATION] ?? '');
            $label = $communication !== '' ? $communication : trim($row[self::COL_DETAILS] ?? '');

            $counterpartyAccount = trim($row[self::COL_COUNTERPARTY_ACCOUNT] ?? '');
            $counterpartyName = trim($row[self::COL_COUNTERPARTY_NAME] ?? '');

            $lines[] = new StatementLine(
                bankReference: $this->extractBankReference((string) ($row[self::COL_DETAILS] ?? '')),
                transactionDate: $date,
                amount: $amount,
                label: $label,
                counterpartyAccount: $counterpartyAccount !== '' ? $counterpartyAccount : null,
                counterpartyName: $counterpartyName !== '' ? $counterpartyName : null,
                extraDetails: $this->buildExtraDetails($row, $date->format('Y-m-d'))
            );
        }

        return $lines;
    }

    /**
     * Every column BNP's export provides that doesn't get its own
     * dedicated StatementLine field, concatenated into one string —
     * "Date valeur" only when it actually differs from the execution
     * date (it's usually identical, and repeating it would be noise),
     * plus "Type de transaction".
     *
     * @param array<int, string> $row
     */
    private function buildExtraDetails(array $row, string $executionDateStr): ?string
    {
        $parts = [];

        $valueDateRaw = trim($row[self::COL_VALUE_DATE] ?? '');
        if ($valueDateRaw !== '') {
            $valueDate = \DateTimeImmutable::createFromFormat('!d/m/Y', $valueDateRaw);
            if ($valueDate !== false && $valueDate->format('Y-m-d') !== $executionDateStr) {
                $parts[] = 'Date valeur : ' . $valueDateRaw;
            }
        }

        $transactionType = trim($row[self::COL_TRANSACTION_TYPE] ?? '');
        if ($transactionType !== '') {
            $parts[] = 'Type : ' . $transactionType;
        }

        return $parts !== [] ? implode(' ; ', $parts) : null;
    }

    /**
     * Belgian/French formatting: "." as thousands separator, "," as
     * decimal separator (e.g. "1.234,56" or plain "35,98").
     *
     * The "." is only stripped when a "," is actually present. Stripping it
     * unconditionally silently turned a dot-decimal "35.98" into 3598,00 €
     * — no error, just a wrong amount, and a wrong balance checkpoint
     * behind it. With no comma in the value, a single "." is read as the
     * decimal separator instead.
     */
    private function parseAmount(string $raw): float
    {
        $raw = trim($raw);

        if (str_contains($raw, ',')) {
            $normalized = str_replace(['.', ','], ['', '.'], $raw);
        } else {
            // No decimal comma: a lone "." is the decimal point, but
            // several ("1.234.567") can only be thousands separators.
            $normalized = substr_count($raw, '.') > 1 ? str_replace('.', '', $raw) : $raw;
        }

        if ($normalized === '' || !is_numeric($normalized)) {
            throw new FinanceException("Montant invalide dans le relevé BNP : \"{$raw}\".");
        }

        return (float) $normalized;
    }

    private function extractBankReference(string $details): string
    {
        if (preg_match('/REFERENCE BANQUE\s*:\s*(\S+)/u', $details, $matches) === 1) {
            return $matches[1];
        }

        // Not observed in real exports, but the format doesn't guarantee
        // it — a stable hash of the row keeps deduplication working.
        return 'bnp-' . substr(sha1($details), 0, 24);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readRows(string $filePath): array
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new FinanceException('Impossible de lire le fichier de relevé.');
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ';', '"', '\\');
        if ($header === false) {
            fclose($handle);
            throw new FinanceException('Le fichier de relevé est vide ou illisible.');
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
