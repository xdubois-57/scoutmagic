<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Export;

use Core\Security\Role;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generic, reusable core mechanism turning a list of MemberExportRow into
 * a real .xlsx file: canonical columns (MemberExportColumns), filtered to
 * whatever the exporting user's role may see, multi-value fields joined
 * deterministically, dates as real Excel dates, and every textual value
 * written with an explicit string data type so a value beginning with
 * =, +, - or @ can never be written as a live Excel formula (SECURITY.md —
 * formula injection). Any screen that needs a member export builds its own
 * MemberExportRow[] (its own filter/selection) and calls build() — the
 * column set itself is never redefined by a caller.
 */
final class MemberExportService
{
    private const MULTI_VALUE_SEPARATOR = '; ';

    /**
     * @param MemberExportRow[] $rows
     */
    public function build(array $rows, Role $viewerRole, string $sheetTitle = 'Membres'): string
    {
        $fields = MemberExportColumns::forRole($viewerRole);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sanitizeSheetTitle($sheetTitle));

        foreach ($fields as $index => $field) {
            $column = $index + 1;
            $sheet->setCellValueExplicit([$column, 1], $field->header, DataType::TYPE_STRING);
        }

        foreach (array_values($rows) as $rowIndex => $row) {
            $excelRow = $rowIndex + 2;
            foreach ($fields as $index => $field) {
                $column = $index + 1;
                $this->writeCell($sheet, $column, $excelRow, $field, $row);
            }
        }

        $lastColumnLetter = Coordinate::stringFromColumnIndex(count($fields));
        $lastRow = count($rows) + 1;
        if (count($fields) > 0) {
            $sheet->setAutoFilter('A1:' . $lastColumnLetter . max($lastRow, 1));
        }
        $sheet->freezePane('A2');

        foreach ($fields as $index => $field) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $width = max(12, min(40, mb_strlen($field->header) + 4));
            $sheet->getColumnDimension($columnLetter)->setWidth($width);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();
    }

    private function writeCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $column, int $row, MemberExportField $field, MemberExportRow $data): void
    {
        $value = ($field->resolver)($data);

        switch ($field->type) {
            case MemberExportField::TYPE_MULTI:
                /** @var string[] $value */
                $joined = implode(self::MULTI_VALUE_SEPARATOR, array_filter($value, fn(string $v) => $v !== ''));
                $sheet->setCellValueExplicit([$column, $row], $joined, DataType::TYPE_STRING);
                return;

            case MemberExportField::TYPE_BOOL:
                $sheet->setCellValueExplicit([$column, $row], $value === true ? 'Oui' : 'Non', DataType::TYPE_STRING);
                return;

            case MemberExportField::TYPE_INT:
                if ($value === null) {
                    $sheet->setCellValueExplicit([$column, $row], '', DataType::TYPE_STRING);
                    return;
                }
                $sheet->setCellValueExplicit([$column, $row], (int) $value, DataType::TYPE_NUMERIC);
                return;

            case MemberExportField::TYPE_DATE:
                if ($value === null || $value === '') {
                    $sheet->setCellValueExplicit([$column, $row], '', DataType::TYPE_STRING);
                    return;
                }
                // Converted through a DateTimeInterface, never through a
                // Unix timestamp: PhpSpreadsheet reads an int as seconds
                // since the epoch in UTC, so `strtotime('2015-03-21')` —
                // local midnight — came back out of the spreadsheet as
                // 2015-03-20 the moment PHP stopped running on UTC. A
                // DateTimeInterface is read by its calendar components, so
                // the date written is the date given, on any clock.
                try {
                    $date = new \DateTimeImmutable((string) $value);
                } catch (\Exception) {
                    $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING);
                    return;
                }
                $sheet->setCellValueExplicit([$column, $row], ExcelDate::PHPToExcel($date), DataType::TYPE_NUMERIC);
                $coordinate = Coordinate::stringFromColumnIndex($column) . $row;
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                return;

            case MemberExportField::TYPE_TEXT:
            default:
                $sheet->setCellValueExplicit([$column, $row], $value === null ? '' : (string) $value, DataType::TYPE_STRING);
                return;
        }
    }

    /**
     * Excel sheet titles: max 31 characters, and forbid : \ / ? * [ ].
     */
    private function sanitizeSheetTitle(string $title): string
    {
        $clean = (string) preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $title);
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'Membres';
        }
        return mb_substr($clean, 0, 31);
    }
}
