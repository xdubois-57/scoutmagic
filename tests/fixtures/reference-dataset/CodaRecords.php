<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Builds CODA 2 records field by field, at the positions the Febelfin
 * standard gives them (1-based, as the standard counts), so the caller
 * states what a record MEANS and the layout lives in one place — shared by
 * the dataset's CodaWriter and Tests\Modules\Finance\Parser\CodaParserTest.
 * Every record is exactly 128 characters of UTF-8; the caller converts the
 * joined file to ISO-8859-1 or CP850, as a bank would.
 */
final class CodaRecords
{
    public static function header(string $date = '010927'): string
    {
        return self::record([[1, '0'], [2, '0000'], [6, $date], [12, '000'], [15, '05'], [35, 'UNITE SCOUTE'], [128, '2']]);
    }

    /**
     * Record 1. $millis is the signed balance in thousandths of a euro.
     */
    public static function oldBalance(string $iban, string $statementNo, int $millis, string $date, string $structure = '2'): string
    {
        $account = $structure === '2' ? str_pad($iban, 34) . 'EUR' : str_pad($iban, 37);

        return self::record([
            [1, '1'], [2, $structure], [3, $statementNo], [6, $account],
            [43, $millis < 0 ? '1' : '0'], [44, sprintf('%015d', abs($millis))], [59, $date],
            [65, 'UNITE SCOUTE'], [126, $statementNo],
        ]);
    }

    /**
     * Record 2.1.
     *
     * @param string $communication for a structured one, its kind and
     *                              digits ("101" . twelve digits)
     */
    public static function movement(
        string $seq,
        string $detail,
        string $bankRef,
        int $millis,
        string $valueDate,
        string $entryDate,
        string $communication,
        bool $structured = false,
        string $globalisation = '0',
        string $statementNo = '001'
    ): string {
        return self::record([
            [1, '21'], [3, $seq], [7, $detail], [11, $bankRef], [32, $millis < 0 ? '1' : '0'],
            [33, sprintf('%015d', abs($millis))], [48, $valueDate], [54, '00150000'],
            [62, $structured ? '1' : '0'], [63, $communication], [116, $entryDate], [122, $statementNo],
            [125, $globalisation], [126, '1'], [128, '0'],
        ]);
    }

    /** Record 2.2: the communication's second part. */
    public static function movement2(string $seq, string $detail, string $communication): string
    {
        return self::record([[1, '22'], [3, $seq], [7, $detail], [11, $communication], [126, '1'], [128, '0']]);
    }

    /** Record 2.3: the counterparty, and the communication's last part. */
    public static function movement3(string $seq, string $detail, string $iban, string $name, string $communication = ''): string
    {
        return self::record([
            [1, '23'], [3, $seq], [7, $detail], [11, str_pad($iban, 34) . 'EUR'], [48, $name], [83, $communication],
            [126, '0'], [128, '0'],
        ]);
    }

    /** Record 3.1: information attached to a movement. */
    public static function information(string $seq, string $detail, string $bankRef, string $communication): string
    {
        return self::record([
            [1, '31'], [3, $seq], [7, $detail], [11, $bankRef], [32, '00150000'], [40, '0'], [41, $communication],
            [126, '0'], [128, '0'],
        ]);
    }

    /** Record 8. */
    public static function newBalance(string $iban, string $statementNo, int $millis, string $date): string
    {
        return self::record([
            [1, '8'], [2, $statementNo], [5, str_pad($iban, 34) . 'EUR'], [42, $millis < 0 ? '1' : '0'],
            [43, sprintf('%015d', abs($millis))], [58, $date], [128, '0'],
        ]);
    }

    public static function trailer(): string
    {
        return self::record([[1, '9'], [128, '2']]);
    }

    /**
     * @param list<array{0: int, 1: string}> $fields 1-based position => value
     */
    private static function record(array $fields): string
    {
        $chars = array_fill(0, 128, ' ');
        foreach ($fields as [$position, $value]) {
            foreach (mb_str_split($value) as $i => $char) {
                $chars[$position - 1 + $i] = $char;
            }
        }

        return implode('', array_slice($chars, 0, 128));
    }
}
