<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Turns a header row plus a list of rows into real XLSX bytes, for the
 * support package's three tabular diagnostics (ARCHITECTURE.md §8.47).
 *
 * Every cell is written with an explicit **string** data type. That is not
 * cosmetic: these sheets carry settings values, journal descriptions and
 * task error messages, some of which originate from outside the
 * application, and a value beginning with `=`, `+`, `-` or `@` would
 * otherwise be evaluated as a live formula when a maintainer opens the file
 * (SECURITY.md §23). No column here is genuinely numeric enough to be worth
 * the exception.
 */
final class SupportSpreadsheet
{
    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|null>> $rows
     */
    public static function build(array $headers, array $rows, string $sheetTitle): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::sanitizeSheetTitle($sheetTitle));

        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        foreach (array_values($rows) as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $sheet->setCellValueExplicit(
                    [$columnIndex + 1, $rowIndex + 2],
                    $value ?? '',
                    DataType::TYPE_STRING
                );
            }
        }

        if ($headers !== []) {
            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->setAutoFilter('A1:' . $lastColumn . max(count($rows) + 1, 1));
            foreach ($headers as $index => $header) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $sheet->getColumnDimension($letter)->setWidth(max(12, min(50, mb_strlen($header) + 6)));
            }
        }
        $sheet->freezePane('A2');

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Excel refuses `* : / \ ? [ ]` in a sheet name and caps it at 31
     * characters.
     */
    private static function sanitizeSheetTitle(string $title): string
    {
        $title = (string) preg_replace('/[*:\/\\\\?\[\]]/', '-', $title);
        $title = mb_substr(trim($title), 0, 31);

        return $title !== '' ? $title : 'Feuille';
    }
}
