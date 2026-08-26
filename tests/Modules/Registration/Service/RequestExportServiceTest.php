<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Service\RequestExportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;

/**
 * The export's own rules, without a controller or a database: which
 * columns exist, what each cell says, and — the one that matters —
 * that nothing is ever written as anything but text.
 */
class RequestExportServiceTest extends TestCase
{
    private RequestExportService $service;

    protected function setUp(): void
    {
        $this->service = new RequestExportService();
    }

    private function request(array $overrides = []): RegistrationRequest
    {
        $defaults = [
            'id' => 1,
            'scoutYearId' => 2,
            'parentName' => 'Marie Dupont',
            'childLastName' => 'Dupont',
            'childFirstName' => 'Noa',
            'gender' => 'F',
            'birthDate' => '2020-06-01',
            'street' => 'Rue des Bruyères',
            'number' => '14',
            'postalCode' => '1340',
            'city' => 'Ottignies',
            'email' => 'famille@example.test',
            'phone1' => '010123456',
            'phone2' => null,
            'remarks' => null,
            'desiredSectionId' => null,
            'status' => RegistrationRequest::STATUS_PENDING,
            'receivedAt' => new \DateTimeImmutable('2026-02-04 09:00:00'),
        ];
        $values = array_merge($defaults, $overrides);

        return new RegistrationRequest(
            $values['id'],
            $values['scoutYearId'],
            $values['parentName'],
            $values['childLastName'],
            $values['childFirstName'],
            $values['gender'],
            $values['birthDate'],
            $values['street'],
            $values['number'],
            $values['postalCode'],
            $values['city'],
            $values['email'],
            $values['phone1'],
            $values['phone2'],
            $values['remarks'],
            $values['desiredSectionId'],
            $values['status'],
            $values['receivedAt'],
            $overrides['intendedSectionId'] ?? null,
            null,
            $overrides['internalNotes'] ?? null,
            null,
            $overrides['acceptedEmailSentAt'] ?? null,
            $overrides['refusedEmailSentAt'] ?? null,
            null
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, string>>
     */
    private function sheetRows(array $rows): array
    {
        $values = $this->service->buildSpreadsheet($rows)->getActiveSheet()->toArray(null, true, true, false);

        return array_map(static fn(array $r) => array_map(static fn($v) => (string) $v, $r), $values);
    }

    public function testTheContactColumnIsHeadedWithinTheMailMergeAliasSet(): void
    {
        // ARCHITECTURE.md §8.62: an export of people a unit cannot
        // re-import as an audience is a dead end they hand-edit.
        $this->assertContains('Email', RequestExportService::headers());
    }

    public function testTheHeaderRowNeverOffersAColumnForTheStaffsInternalNotes(): void
    {
        foreach (RequestExportService::headers() as $header) {
            $this->assertStringNotContainsStringIgnoringCase('note', $header);
        }
    }

    public function testARowCarriesWhatTheFamilyWroteAndWhatTheUnitDecided(): void
    {
        $rows = $this->sheetRows([[
            'request' => $this->request(['remarks' => 'Allergie aux arachides.']),
            'slot_label' => 'Baladins — 1ᵉ année',
            'desired_section_label' => 'Baladins 1',
            'intended_section_label' => 'Baladins 2',
            'sibling_count' => 2,
        ]]);

        $this->assertCount(2, $rows);
        $row = $rows[1];
        $this->assertContains('04/02/2026', $row);
        $this->assertContains('En attente', $row);
        $this->assertContains('Dupont', $row);
        $this->assertContains('Noa', $row);
        $this->assertContains('01/06/2020', $row);
        $this->assertContains('Baladins — 1ᵉ année', $row);
        $this->assertContains('Baladins 1', $row);
        $this->assertContains('Baladins 2', $row);
        $this->assertContains('2', $row);
        $this->assertContains('famille@example.test', $row);
        $this->assertContains('Allergie aux arachides.', $row);
    }

    public function testInternalNotesNeverReachACell(): void
    {
        $rows = $this->sheetRows([[
            'request' => $this->request(['internalNotes' => 'Parents séparés.']),
            'slot_label' => '',
            'sibling_count' => 0,
        ]]);

        $this->assertStringNotContainsString('Parents séparés.', implode('|', $rows[1]));
    }

    /**
     * A registration form is public, so a remark can begin with `=`. The
     * cell has to be typed as a string or it opens as a live formula in
     * the chief's spreadsheet (SECURITY.md §23).
     */
    public function testEveryCellIsTypedAsAStringSoNothingBecomesALiveFormula(): void
    {
        $sheet = $this->service->buildSpreadsheet([[
            'request' => $this->request([
                'remarks' => '=HYPERLINK("http://evil.test","cliquez")',
                'street' => '+32 rue du Test',
            ]),
            'slot_label' => '-1',
            'sibling_count' => 0,
        ]])->getActiveSheet();

        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertSame(
                    DataType::TYPE_STRING,
                    $cell->getDataType(),
                    'Cell ' . $cell->getCoordinate() . ' is not written as text'
                );
            }
        }
    }

    public function testTheEmailColumnSaysWhichDecisionMailWentOutAndWhen(): void
    {
        $accepted = $this->sheetRows([[
            'request' => $this->request([
                'status' => RegistrationRequest::STATUS_ACCEPTED,
                'acceptedEmailSentAt' => new \DateTimeImmutable('2026-03-01 08:00:00'),
            ]),
            'slot_label' => '',
            'sibling_count' => 0,
        ]])[1];
        $refused = $this->sheetRows([[
            'request' => $this->request([
                'status' => RegistrationRequest::STATUS_REFUSED,
                'refusedEmailSentAt' => new \DateTimeImmutable('2026-03-02 08:00:00'),
            ]),
            'slot_label' => '',
            'sibling_count' => 0,
        ]])[1];

        $this->assertContains('01/03/2026', $accepted);
        $this->assertContains('02/03/2026', $refused);
    }

    public function testADecidedRequestWhoseMailHasNotGoneOutSaysSo(): void
    {
        // The decoupling rule (specifications §17.2): a family sees
        // nothing until the mail is actually sent, so the export has to
        // make the gap visible rather than leaving the cell blank.
        $row = $this->sheetRows([[
            'request' => $this->request(['status' => RegistrationRequest::STATUS_ACCEPTED]),
            'slot_label' => '',
            'sibling_count' => 0,
        ]])[1];

        $this->assertContains('Non envoyé', $row);
    }

    public function testAPendingRequestHasNoMailColumnValueAtAll(): void
    {
        $row = $this->sheetRows([[
            'request' => $this->request(),
            'slot_label' => '',
            'sibling_count' => 0,
        ]])[1];

        $this->assertNotContains('Non envoyé', $row);
    }

    public function testAnUnparseableBirthDateIsWrittenThroughRatherThanDropped(): void
    {
        $row = $this->sheetRows([[
            'request' => $this->request(['birthDate' => 'inconnue']),
            'slot_label' => '',
            'sibling_count' => 0,
        ]])[1];

        $this->assertContains('inconnue', $row);
    }

    public function testARowThatIsNotARegistrationRequestIsSkippedRatherThanFatal(): void
    {
        $rows = $this->sheetRows([
            ['request' => null, 'slot_label' => '', 'sibling_count' => 0],
            ['request' => $this->request(), 'slot_label' => '', 'sibling_count' => 0],
        ]);

        // Header + the one real row.
        $this->assertCount(2, $rows);
    }

    public function testEveryStatusHasAFrenchLabelOfItsOwn(): void
    {
        $labels = [];
        foreach ([
            RegistrationRequest::STATUS_PENDING,
            RegistrationRequest::STATUS_ACCEPTED,
            RegistrationRequest::STATUS_REFUSED,
            RegistrationRequest::STATUS_WITHDRAWN,
            RegistrationRequest::STATUS_ENCODED,
        ] as $status) {
            $row = $this->sheetRows([[
                'request' => $this->request(['status' => $status]),
                'slot_label' => '',
                'sibling_count' => 0,
            ]])[1];
            $labels[] = $row[array_search('État', RequestExportService::headers(), true)];
        }

        $this->assertSame($labels, array_unique($labels));
        $this->assertNotContains('pending', $labels);
    }
}
