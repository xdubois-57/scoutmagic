<?php

declare(strict_types=1);

namespace Tests\Core\Export;

use Core\Export\TabularSpreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * The site's generic tabular export brick — the one a module's own-domain
 * export builds on, so nobody hand-rolls PhpSpreadsheet a fourth time
 * (member-domain exports go through Core\Member\Export\MemberExportService
 * instead, which owns the canonical columns).
 *
 * The single property that is not negotiable: every cell is written as
 * text. These sheets carry settings values, journal descriptions, task
 * error messages and text typed by the public into a form, and a value
 * beginning with `=`, `+`, `-` or `@` would otherwise open as a live
 * formula (SECURITY.md §23).
 */
class TabularSpreadsheetTest extends TestCase
{
    public function testHeadersAndRowsLandWhereTheyBelong(): void
    {
        $sheet = TabularSpreadsheet::buildSpreadsheet(
            ['Nom', 'Ville'],
            [['Dupont', 'Ottignies'], ['Roskam', 'Wavre']],
            'Demandes'
        )->getActiveSheet();

        $this->assertSame(
            [['Nom', 'Ville'], ['Dupont', 'Ottignies'], ['Roskam', 'Wavre']],
            $sheet->toArray(null, true, true, false)
        );
    }

    public function testEveryCellIsWrittenAsTextIncludingTheFormulaLookalikes(): void
    {
        $sheet = TabularSpreadsheet::buildSpreadsheet(
            ['Valeur'],
            [['=1+1'], ['+32 470 00 00 00'], ['-5'], ['@handle'], ['42']],
            'Test'
        )->getActiveSheet();

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), (string) $cell->getCoordinate());
            }
        }
        // And the text survives verbatim rather than being evaluated.
        $this->assertSame('=1+1', $sheet->getCell('A2')->getValue());
    }

    public function testANullCellBecomesAnEmptyStringRatherThanBreaking(): void
    {
        $sheet = TabularSpreadsheet::buildSpreadsheet(['A', 'B'], [['x', null]], 'Test')->getActiveSheet();

        $this->assertSame([['A', 'B'], ['x', '']], $sheet->toArray(null, true, true, false));
    }

    public function testTheHeaderRowIsFrozenAndFiltered(): void
    {
        $sheet = TabularSpreadsheet::buildSpreadsheet(['A', 'B'], [['1', '2']], 'Test')->getActiveSheet();

        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:B2', $sheet->getAutoFilter()->getRange());
    }

    public function testAnEmptySheetStillProducesAValidWorkbook(): void
    {
        // No headers means no auto-filter and no column widths to set —
        // the guard around that block is what this exercises. The sheet
        // itself is a valid, empty workbook (PhpSpreadsheet always keeps
        // one blank cell), never a fatal.
        $sheet = TabularSpreadsheet::buildSpreadsheet([], [], 'Vide')->getActiveSheet();

        $this->assertSame('Vide', $sheet->getTitle());
        $this->assertNull($sheet->getCell('A1')->getValue());
    }

    /**
     * Excel refuses `* : / \ ? [ ]` in a sheet name and caps it at 31
     * characters — a title coming from a scout year label or an asset
     * name must not be able to produce a file Excel refuses to open.
     */
    public function testTheSheetTitleIsSanitizedAndCapped(): void
    {
        $sheet = TabularSpreadsheet::buildSpreadsheet(['A'], [], 'Demandes/2026 [brouillon] ?*')->getActiveSheet();

        $title = $sheet->getTitle();
        $this->assertLessThanOrEqual(31, mb_strlen($title));
        foreach (['*', ':', '/', '\\', '?', '[', ']'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $title);
        }
    }

    public function testAnEmptyTitleFallsBackRatherThanProducingAnUnnamedSheet(): void
    {
        $sheet = TabularSpreadsheet::buildSpreadsheet(['A'], [], '   ')->getActiveSheet();

        $this->assertNotSame('', trim($sheet->getTitle()));
    }

    /**
     * build() is the bytes variant, kept for the support package, which
     * zips its sheets rather than streaming them. It must answer the same
     * workbook buildSpreadsheet() does.
     */
    public function testBuildReturnsRealXlsxBytesMatchingTheSpreadsheet(): void
    {
        $bytes = TabularSpreadsheet::build(['Nom'], [['Dupont']], 'Test');

        $this->assertNotSame('', $bytes);
        $path = tempnam(sys_get_temp_dir(), 'sm-tabular-test-');
        $this->assertIsString($path);
        file_put_contents($path, $bytes);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame([['Nom'], ['Dupont']], $sheet->toArray(null, true, true, false));
        } finally {
            @unlink($path);
        }
    }
}
