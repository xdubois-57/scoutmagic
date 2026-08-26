<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Turns a header row plus a list of rows into real XLSX bytes.
 *
 * The site's generic tabular export brick. There are exactly two ways to
 * produce a spreadsheet of people here, and this is the second one:
 *
 * - **Member-domain data** goes through Core\Member\Export\
 *   MemberExportService, which owns the canonical column list
 *   (MemberExportColumns) so no screen ever redefines member columns.
 * - **A module's own domain** — form responses, registration requests,
 *   finance movements — keeps its own columns, which
 *   MemberExportColumns' docblock explicitly refuses to grow, and builds
 *   them through THIS class rather than hand-rolling PhpSpreadsheet a
 *   fourth time. ARCHITECTURE.md §8.62's alias rule still applies to the
 *   headers: name the contact/identifier column within the mail-merge
 *   importer's alias set so the file round-trips as an audience.
 *
 * Every cell is written with an explicit **string** data type. That is not
 * cosmetic: these sheets carry settings values, journal descriptions, task
 * error messages and text typed by the public into a form, and a value
 * beginning with `=`, `+`, `-` or `@` would otherwise be evaluated as a
 * live formula when someone opens the file (SECURITY.md §23) — the
 * CSV/XLSX formula-injection class. A caller wanting a genuinely numeric
 * or date-typed column wants MemberExportService's typed fields, not a
 * exception carved into this one.
 *
 * It used to be Core\Support\SupportSpreadsheet, scoped by its name and
 * its docblock to the support package's three diagnostics
 * (ARCHITECTURE.md §8.48) — which are still four of its call sites. The
 * class was already generic; only the namespace claimed otherwise, and
 * that claim was about to cost a fourth copy of the same twenty lines.
 */
final class TabularSpreadsheet
{
    /**
     * The serialized workbook. Kept for the callers that want the bytes —
     * the support package zips them, it never streams them.
     *
     * A download route wants buildSpreadsheet() instead, handed to
     * Core\Http\SpreadsheetResponse::download(): that streams from a temp
     * file rather than holding the whole workbook as a PHP string on top
     * of the object graph.
     *
     * @param array<int, string> $headers
     * @param array<int, array<int, string|null>> $rows
     */
    public static function build(array $headers, array $rows, string $sheetTitle): string
    {
        $spreadsheet = self::buildSpreadsheet($headers, $rows, $sheetTitle);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $output = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return $output;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|null>> $rows
     */
    public static function buildSpreadsheet(array $headers, array $rows, string $sheetTitle): Spreadsheet
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

        return $spreadsheet;
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
