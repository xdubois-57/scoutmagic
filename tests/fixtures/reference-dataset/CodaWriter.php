<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Modules\Finance\Parser\BnpParser;
use Modules\Finance\Service\StructuredCommunicationService;

/**
 * Writes CodaBlueprint as the bytes a Belgian bank would hand over: CODA 2
 * records of 128 characters (CodaRecords), CRLF line ends, ISO-8859-1.
 *
 * The camps account's old balance is not declared: it is the ledger's own
 * figure after the committed BNP statements (ledgerMillis()). A hand-typed
 * figure would drift the day a statement is regenerated, and the import
 * would report a discrepancy the dataset invented.
 */
final class CodaWriter
{
    /**
     * @param array<string, string> $bnpFiles the generated BNP statements,
     *                                        relative path => bytes
     */
    public function write(array $bnpFiles): string
    {
        $campsOpening = self::ledgerMillis($bnpFiles, CodaBlueprint::UNIT_ACCOUNT);

        $records = [CodaRecords::header(self::ddmmyy(CodaBlueprint::OPENING_DATE))];
        $records = [...$records, ...$this->statement(
            BankBlueprint::compactIban(BankBlueprint::ACCOUNTS[CodaBlueprint::UNIT_ACCOUNT]['iban']),
            CodaBlueprint::STATEMENT_NUMBERS['camps'],
            $campsOpening,
            CodaBlueprint::MOVEMENTS['camps'],
        )];
        $records = [...$records, ...$this->statement(
            BankBlueprint::compactIban(BankBlueprint::sectionIban(CodaBlueprint::SECTION_INDEX)),
            CodaBlueprint::STATEMENT_NUMBERS['section'],
            self::millis(CodaBlueprint::SECTION_OPENING),
            CodaBlueprint::MOVEMENTS['section'],
        )];
        $records[] = CodaRecords::trailer();

        return mb_convert_encoding(implode("\r\n", $records) . "\r\n", 'ISO-8859-1', 'UTF-8');
    }

    /**
     * @param list<array<string, mixed>> $movements
     * @return list<string>
     */
    private function statement(string $iban, string $number, int $openingMillis, array $movements): array
    {
        $records = [CodaRecords::oldBalance($iban, $number, $openingMillis, self::ddmmyy(CodaBlueprint::OPENING_DATE))];
        $balance = $openingMillis;

        foreach ($movements as $index => $movement) {
            $seq = sprintf('%04d', $index + 1);
            $date = self::ddmmyy((string) $movement['date']);
            $millis = self::millis((float) $movement['amount']);
            $reference = sprintf('%s%s%04d', $number, substr($date, 0, 4), $index + 1);
            $details = $movement['details'] ?? [];

            if (($movement['structured'] ?? false) === true) {
                $digits = preg_replace('/\D/', '', StructuredCommunicationService::format(CodaBlueprint::STRUCTURED_BASE));
                $records[] = CodaRecords::movement($seq, '0000', $reference, $millis, $date, $date, '101' . $digits, true, '0', $number);
                $records[] = CodaRecords::movement2($seq, '0000', '');
            } else {
                $chunks = self::chunks((string) $movement['communication']);
                $records[] = CodaRecords::movement(
                    $seq, '0000', $reference, $millis, $date, $date, $chunks[0], false, $details !== [] ? '1' : '0', $number
                );
                $records[] = CodaRecords::movement2($seq, '0000', $chunks[1]);
            }
            $records[] = CodaRecords::movement3(
                $seq,
                '0000',
                BankBlueprint::compactIban((string) $movement['counterpartyIban']),
                (string) $movement['counterpartyName'],
                ($movement['structured'] ?? false) === true ? '' : self::chunks((string) $movement['communication'])[2],
            );

            if (isset($movement['information'])) {
                $records[] = CodaRecords::information($seq, '0000', $reference, (string) $movement['information']);
            }

            // A globalised batch: its details follow the global line, same
            // sequence number, detail numbers from 0001 — read by nothing
            // that counts money.
            foreach ($details as $detailIndex => $detailAmount) {
                $records[] = CodaRecords::movement(
                    $seq,
                    sprintf('%04d', $detailIndex + 1),
                    $reference,
                    self::millis((float) $detailAmount),
                    $date,
                    $date,
                    'Remboursement ' . ($detailIndex + 1),
                    false,
                    '0',
                    $number,
                );
            }

            $balance += $millis;
        }

        $records[] = CodaRecords::newBalance($iban, $number, $balance, self::ddmmyy(CodaBlueprint::CLOSING_DATE));

        return $records;
    }

    /**
     * The account's balance, in thousandths, once its BNP statements are
     * imported — computed the way Modules\Finance\Service\BalanceService
     * computes it. The first file's typed opening is the balance AFTER that
     * statement, a checkpoint on its last line's date; every later line
     * the real BnpParser reads, once per REFERENCE BANQUE and dated after
     * that checkpoint, is added to it. The overlap lines a later file
     * repeats fall on or before it, exactly as they do on the site.
     *
     * @param array<string, string> $bnpFiles
     */
    public static function ledgerMillis(array $bnpFiles, string $accountHandle): int
    {
        $parser = new BnpParser();
        /** @var array<string, array{date: string, millis: int}> $byReference */
        $byReference = [];
        $checkpointDate = null;

        foreach (UnitBlueprint::YEARS as $year) {
            $bytes = $bnpFiles[BankBlueprint::fileFor($year, $accountHandle)] ?? null;
            if ($bytes === null) {
                throw new \RuntimeException("Relevé BNP manquant pour {$year}/{$accountHandle}.");
            }
            $path = (string) tempnam(sys_get_temp_dir(), 'refdataset-coda-ledger');
            try {
                file_put_contents($path, $bytes);
                foreach ($parser->parse($path) as $line) {
                    $date = $line->transactionDate->format('Y-m-d');
                    $byReference[$line->bankReference] = ['date' => $date, 'millis' => self::millis($line->amount)];
                    if ($year === UnitBlueprint::YEARS[0] && ($checkpointDate === null || $date > $checkpointDate)) {
                        $checkpointDate = $date;
                    }
                }
            } finally {
                @unlink($path);
            }
        }

        $balance = self::millis(BankBlueprint::ACCOUNTS[$accountHandle]['opening']);
        foreach ($byReference as $line) {
            if ($line['date'] > $checkpointDate) {
                $balance += $line['millis'];
            }
        }

        return $balance;
    }

    /**
     * A communication cut the way CODA carries it: 53 characters in record
     * 2.1, 53 in 2.2, 43 in 2.3.
     *
     * @return array{string, string, string}
     */
    private static function chunks(string $text): array
    {
        if (mb_strlen($text) > 149) {
            throw new \LogicException("Une communication CODA tient en 149 caractères : « {$text} ».");
        }

        return [mb_substr($text, 0, 53), mb_substr($text, 53, 53), mb_substr($text, 106, 43)];
    }

    private static function millis(float $euros): int
    {
        return (int) round($euros * 1000);
    }

    /** "31/08/2027" → "310827". */
    private static function ddmmyy(string $date): string
    {
        [$day, $month, $year] = explode('/', $date);

        return $day . $month . substr($year, 2, 2);
    }
}
