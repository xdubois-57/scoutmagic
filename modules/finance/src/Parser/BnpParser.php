<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Parser;

use Core\Service\DateInput;
use Modules\Finance\Api\FinanceException;
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
     * The header row this export always starts with (after its BOM) — the
     * column names are BNP Fortis's, and nothing else writes them.
     */
    public function recognizes(string $filePath): bool
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }
        $first = (string) fgets($handle, 4096);
        fclose($handle);

        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            $first = substr($first, 3);
        }

        return str_contains($first, ';') && str_contains($first, 'Numéro de compte')
            && str_contains($first, "Date d'exécution");
    }

    /**
     * A BNP CSV states no balance: the first import of an account needs one
     * typed by hand.
     */
    public function closingBalances(string $filePath): array
    {
        return [];
    }

    /**
     * A BNP export covers one account: its IBAN is repeated on every row.
     *
     * Normalized (uppercase, no spaces or punctuation), because
     * Service\ImportService looks the account up by the blind index of
     * the IBAN Service\IbanNormalizer stored in exactly that form. A trim()
     * alone was enough only as long as this column happens to arrive
     * unformatted; the "Détails" column of the same export does write
     * IBANs as "BE00 0000 0000 0002", and a space-separated value here
     * once failed every import of the right file with a misleading "IBAN
     * mismatch".
     */
    public function extractAccountIbans(string $filePath): array
    {
        $iban = $this->findAccountIban($this->readRows($filePath));
        if ($iban === null) {
            throw new FinanceException("Impossible de trouver l'IBAN du compte dans le fichier BNP.");
        }

        return [$iban];
    }

    public function parse(string $filePath): array
    {
        $rows = $this->readRows($filePath);
        $accountIban = $this->findAccountIban($rows);
        if ($accountIban === null) {
            throw new FinanceException("Impossible de trouver l'IBAN du compte dans le fichier BNP.");
        }

        $lines = [];

        foreach ($rows as $index => $row) {
            $status = trim($row[self::COL_STATUS] ?? '');
            if ($status !== '' && $status !== 'Accepté') {
                // Refused/pending lines never happened on the account — skip them.
                continue;
            }

            $dateStr = trim($row[self::COL_EXECUTION_DATE] ?? '');
            // exact: false — the shape of the bank's own CSV is BNP's
            // decision, not this project's, so a day it writes without a
            // leading zero is still that day.
            $date = DateInput::parse('!d/m/Y', $dateStr, exact: false);
            if ($date === null) {
                throw new FinanceException("Date invalide dans le relevé BNP : \"{$dateStr}\".");
            }

            // +2: the header is line 1, and $rows counts from 0.
            $amount = $this->parseAmount((string) ($row[self::COL_AMOUNT] ?? ''), $index + 2);

            $communication = trim($row[self::COL_COMMUNICATION] ?? '');
            $label = $communication !== '' ? $communication : trim($row[self::COL_DETAILS] ?? '');

            $counterpartyAccount = trim($row[self::COL_COUNTERPARTY_ACCOUNT] ?? '');
            $counterpartyName = trim($row[self::COL_COUNTERPARTY_NAME] ?? '');

            $lines[] = new StatementLine(
                accountIban: $accountIban,
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
            $valueDate = DateInput::parse('!d/m/Y', $valueDateRaw, exact: false);
            if ($valueDate !== null && $valueDate->format('Y-m-d') !== $executionDateStr) {
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
     *
     * The refusal names the line, never the value: no amount leaves the
     * import, not even in an error message (specifications.md §48.3).
     */
    private function parseAmount(string $raw, int $line): float
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
            throw new FinanceException("Montant invalide dans le relevé BNP (ligne {$line}).");
        }

        return (float) $normalized;
    }

    /**
     * The first non-empty "Numéro de compte" — the column the counterparty
     * columns (COL_COUNTERPARTY_ACCOUNT, COL_COUNTERPARTY_NAME) sit next
     * to and must never be confused with.
     *
     * @param array<int, array<int, string>> $rows
     */
    private function findAccountIban(array $rows): ?string
    {
        foreach ($rows as $row) {
            $iban = IbanNormalizer::normalize($row[self::COL_ACCOUNT_NUMBER] ?? '');
            if ($iban !== '') {
                return $iban;
            }
        }

        return null;
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
