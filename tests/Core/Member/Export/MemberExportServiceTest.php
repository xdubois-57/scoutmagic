<?php

declare(strict_types=1);

namespace Tests\Core\Member\Export;

use Core\Member\Export\MemberExportColumns;
use Core\Member\Export\MemberExportRow;
use Core\Member\Export\MemberExportService;
use Core\Security\Role;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class MemberExportServiceTest extends TestCase
{
    private MemberExportService $service;

    protected function setUp(): void
    {
        $this->service = new MemberExportService();
    }

    private function row(array $overrides = []): MemberExportRow
    {
        $defaults = [
            'memberId' => 42,
            'memberYearId' => 142,
            'deskId' => '00042',
            'scoutYearLabel' => '2025-2026',
            'firstName' => 'Émile',
            'lastName' => "D'Öllivier",
            'totem' => 'Renard Rusé',
            'quali' => null,
            'gender' => 'M',
            'birthDate' => '2015-03-21',
            'emails' => ['emile@example.com', 'parent@example.com'],
            'phone' => '024651234',
            'mobile' => '0470123456',
            'street' => 'Rue de la Station',
            'number' => '12',
            'box' => null,
            'postalCode' => '1000',
            'city' => 'Bruxelles',
            'country' => 'Belgique',
            'sectionName' => 'Les Renards',
            'sectionCode' => 'LOU01',
            'branchName' => 'Louveteaux',
            'roleBucketLabel' => 'Animé',
            'functionLabels' => ['Animé', 'Second'],
            'isActive' => true,
            'scoutYearOffset' => 0,
            'formationLevel' => null,
            'supplementaryInsurance' => null,
            'leaving' => false,
            'leavingComment' => null,
            'handicap' => null,
            'movementStatusLabel' => 'Continuité',
            'previousSectionName' => null,
            'previousBranchName' => null,
        ];
        $data = array_merge($defaults, $overrides);
        return new MemberExportRow(...$data);
    }

    /**
     * @return array{spreadsheet: Spreadsheet, path: string}
     */
    private function buildAndReload(array $rows, Role $role = Role::ADMIN, string $sheetTitle = 'Membres'): array
    {
        $xlsx = $this->service->build($rows, $role, $sheetTitle);
        $this->assertNotEmpty($xlsx, 'The generated .xlsx must not be empty.');

        $path = sys_get_temp_dir() . '/member-export-test-' . uniqid() . '.xlsx';
        file_put_contents($path, $xlsx);

        $spreadsheet = IOFactory::load($path);

        return ['spreadsheet' => $spreadsheet, 'path' => $path];
    }

    public function testGeneratesAValidXlsxFileReadableByPhpSpreadsheet(): void
    {
        $result = $this->buildAndReload([$this->row()]);
        $sheet = $result['spreadsheet']->getActiveSheet();

        $this->assertGreaterThan(1, $sheet->getHighestRow());
        unlink($result['path']);
    }

    public function testHeaderRowContainsTheCanonicalColumnNamesInStableOrder(): void
    {
        $result = $this->buildAndReload([$this->row()], Role::ADMIN);
        $sheet = $result['spreadsheet']->getActiveSheet();

        $expectedHeaders = array_map(fn($f) => $f->header, MemberExportColumns::forRole(Role::ADMIN));
        $actualHeaders = [];
        foreach ($sheet->getRowIterator(1, 1) as $r) {
            foreach ($r->getCellIterator() as $cell) {
                $actualHeaders[] = $cell->getValue();
            }
        }

        $this->assertSame($expectedHeaders, $actualHeaders);
        unlink($result['path']);
    }

    public function testMultipleEmailsAreJoinedDeterministically(): void
    {
        $result = $this->buildAndReload([$this->row(['emails' => ['a@example.com', 'b@example.com']])]);
        $value = $this->cellValueByHeader($result['spreadsheet'], 'Email(s)', 2);

        $this->assertSame('a@example.com; b@example.com', $value);
        unlink($result['path']);
    }

    public function testMultipleFunctionsAreJoinedWithMainFunctionFirst(): void
    {
        $result = $this->buildAndReload([$this->row(['functionLabels' => ['Chef de section', 'Trésorier']])]);

        $this->assertSame('Chef de section', $this->cellValueByHeader($result['spreadsheet'], 'Fonction principale', 2));
        $this->assertSame('Chef de section; Trésorier', $this->cellValueByHeader($result['spreadsheet'], 'Fonctions', 2));
        unlink($result['path']);
    }

    public function testSectionAndBranchColumnsArePresent(): void
    {
        $result = $this->buildAndReload([$this->row()]);
        $this->assertSame('Les Renards', $this->cellValueByHeader($result['spreadsheet'], 'Section', 2));
        $this->assertSame('Louveteaux', $this->cellValueByHeader($result['spreadsheet'], 'Branche', 2));
        $this->assertSame('2025-2026', $this->cellValueByHeader($result['spreadsheet'], 'Année scoute', 2));
        unlink($result['path']);
    }

    public function testBirthDateIsWrittenAsARealExcelDate(): void
    {
        $result = $this->buildAndReload([$this->row(['birthDate' => '2015-03-21'])]);
        $sheet = $result['spreadsheet']->getActiveSheet();
        $columnIndex = $this->columnIndexForHeader($sheet, 'Date de naissance');
        $cell = $sheet->getCell([$columnIndex, 2]);

        $this->assertTrue(\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell));
        $phpDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($cell->getValue());
        $this->assertSame('2015-03-21', $phpDate->format('Y-m-d'));
        unlink($result['path']);
    }

    public function testNullValuesAreWrittenAsEmptyCellsNotTheStringNull(): void
    {
        $result = $this->buildAndReload([$this->row(['quali' => null, 'birthDate' => null])]);

        $quali = $this->cellValueByHeader($result['spreadsheet'], 'Qualification', 2);
        $birthDate = $this->cellValueByHeader($result['spreadsheet'], 'Date de naissance', 2);
        // An empty string cell is legitimately read back as either '' or
        // null by PhpSpreadsheet — the one thing that must never happen is
        // the literal 4-character string "null".
        $this->assertTrue($quali === '' || $quali === null);
        $this->assertTrue($birthDate === '' || $birthDate === null);
        unlink($result['path']);
    }

    public function testLeadingZerosArePreservedInTextualIdentifiers(): void
    {
        $result = $this->buildAndReload([$this->row(['deskId' => '00042'])]);
        $sheet = $result['spreadsheet']->getActiveSheet();
        $columnIndex = $this->columnIndexForHeader($sheet, 'Identifiant Desk');
        $cell = $sheet->getCell([$columnIndex, 2]);

        $this->assertSame('00042', $cell->getValue());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType());
        unlink($result['path']);
    }

    public function testAccentedAndSpecialCharactersRoundTripCorrectly(): void
    {
        $result = $this->buildAndReload([$this->row(['firstName' => 'Émile', 'lastName' => "D'Öllivier & Co"])]);

        $this->assertSame('Émile', $this->cellValueByHeader($result['spreadsheet'], 'Prénom', 2));
        $this->assertSame("D'Öllivier & Co", $this->cellValueByHeader($result['spreadsheet'], 'Nom', 2));
        unlink($result['path']);
    }

    public function testFormulaInjectionAttemptsAreStoredAsLiteralTextNeverAsAFormula(): void
    {
        $dangerous = ['=1+1', '+CMD|calc', '-2+3', '@SUM(A1:A2)'];
        $rows = array_map(fn(string $v) => $this->row(['totem' => $v]), $dangerous);

        $result = $this->buildAndReload($rows);
        $sheet = $result['spreadsheet']->getActiveSheet();
        $columnIndex = $this->columnIndexForHeader($sheet, 'Totem');

        foreach ($dangerous as $i => $value) {
            $cell = $sheet->getCell([$columnIndex, $i + 2]);
            $this->assertSame($value, $cell->getValue(), "The raw text must be preserved unchanged for {$value}");
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType(), "Must never be stored as a formula for {$value}");
            $this->assertFalse($cell->isFormula(), "Must never be interpreted as a formula for {$value}");
        }
        unlink($result['path']);
    }

    public function testAdminSeesMoreColumnsThanIntendantIncludingHandicap(): void
    {
        $adminResult = $this->buildAndReload([$this->row(['handicap' => 'Allergie'])], Role::ADMIN);
        $intendantResult = $this->buildAndReload([$this->row(['handicap' => 'Allergie'])], Role::INTENDANT);

        $adminHeaders = $this->headerRow($adminResult['spreadsheet']);
        $intendantHeaders = $this->headerRow($intendantResult['spreadsheet']);

        $this->assertContains('Situation de handicap', $adminHeaders);
        $this->assertNotContains('Situation de handicap', $intendantHeaders);
        $this->assertGreaterThan(count($intendantHeaders), count($adminHeaders));

        unlink($adminResult['path']);
        unlink($intendantResult['path']);
    }

    public function testChiefSeesTheLeavingColumnsButIntendantDoesNot(): void
    {
        $chiefResult = $this->buildAndReload([$this->row(['leaving' => true, 'leavingComment' => 'Déménagement'])], Role::CHIEF);
        $intendantResult = $this->buildAndReload([$this->row(['leaving' => true, 'leavingComment' => 'Déménagement'])], Role::INTENDANT);

        $this->assertContains('Motif de départ', $this->headerRow($chiefResult['spreadsheet']));
        $this->assertNotContains('Motif de départ', $this->headerRow($intendantResult['spreadsheet']));

        unlink($chiefResult['path']);
        unlink($intendantResult['path']);
    }

    public function testNoRawEncryptedOrTechnicalColumnsAreEverExposed(): void
    {
        $result = $this->buildAndReload([$this->row()], Role::SUPERADMIN);
        $headers = $this->headerRow($result['spreadsheet']);

        foreach ($headers as $header) {
            $lower = mb_strtolower($header);
            $this->assertStringNotContainsString('blind', $lower);
            $this->assertStringNotContainsString('hash', $lower);
            $this->assertStringNotContainsString('token', $lower);
            $this->assertStringNotContainsString('encrypted', $lower);
            $this->assertStringNotContainsString('secret', $lower);
        }
        unlink($result['path']);
    }

    public function testFreezePaneAndAutoFilterAreApplied(): void
    {
        $result = $this->buildAndReload([$this->row(), $this->row()]);
        $sheet = $result['spreadsheet']->getActiveSheet();

        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertNotSame('', $sheet->getAutoFilter()->getRange());
        unlink($result['path']);
    }

    public function testSheetTitleIsSanitizedAndBoundedTo31Characters(): void
    {
        $result = $this->buildAndReload([$this->row()], Role::ADMIN, str_repeat('A', 50) . ':bad/name');
        $title = $result['spreadsheet']->getActiveSheet()->getTitle();

        $this->assertLessThanOrEqual(31, mb_strlen($title));
        $this->assertStringNotContainsString(':', $title);
        $this->assertStringNotContainsString('/', $title);
        unlink($result['path']);
    }

    private function headerRow(Spreadsheet $spreadsheet): array
    {
        $headers = [];
        foreach ($spreadsheet->getActiveSheet()->getRowIterator(1, 1) as $r) {
            foreach ($r->getCellIterator() as $cell) {
                $headers[] = $cell->getValue();
            }
        }
        return $headers;
    }

    private function columnIndexForHeader(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $header): int
    {
        foreach ($sheet->getRowIterator(1, 1) as $r) {
            $index = 1;
            foreach ($r->getCellIterator() as $cell) {
                if ($cell->getValue() === $header) {
                    return $index;
                }
                $index++;
            }
        }
        $this->fail("Header not found: {$header}");
    }

    private function cellValueByHeader(Spreadsheet $spreadsheet, string $header, int $row): mixed
    {
        $sheet = $spreadsheet->getActiveSheet();
        $columnIndex = $this->columnIndexForHeader($sheet, $header);
        return $sheet->getCell([$columnIndex, $row])->getValue();
    }
}
