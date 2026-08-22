<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\AudienceImportException;
use Modules\MassMail\Service\AudienceImportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AudienceImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private AudienceImportService $service;
    private AudienceRepository $audienceRepository;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->audienceRepository = new AudienceRepository($this->pdo, $encryption);
        $this->service = new AudienceImportService(
            $this->audienceRepository,
            new MemberResolutionRepository($this->pdo, $encryption),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function createMember(string $deskId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, array<int, string>> $rows Header row included.
     */
    private function writeXlsx(array $rows, string $sheetTitle = 'Feuille1', ?callable $configure = null): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $colIndex => $value) {
                $sheet->setCellValueExplicit([$colIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
            }
        }
        if ($configure !== null) {
            $configure($spreadsheet);
        }

        $path = tempnam(sys_get_temp_dir(), 'mm_audience_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function testImportsAMixedTiersAndEmailFile(): void
    {
        $memberId = $this->createMember('456789');
        $path = $this->writeXlsx([
            ['Tiers', 'Email', 'Prenom', 'Montant'],
            ['456789', '', 'Louis', '145'],
            ['', 'parent@example.be', 'Emma', '80'],
        ], 'Camp');

        $result = $this->service->import($path, 'convocations.xlsx', 7);

        $this->assertSame([], $result->warnings);
        $audience = $result->audience;
        $this->assertSame('convocations.xlsx', $audience->sourceFilename);
        $this->assertSame('Camp', $audience->sheetName);
        $this->assertSame(['Tiers', 'Email', 'Prenom', 'Montant'], $audience->columns);
        $this->assertSame(2, $audience->rowCount);
        $this->assertSame(7, $audience->createdBy);

        $rows = $this->audienceRepository->findRowsByAudience($audience->id);
        $this->assertCount(2, $rows);
        $this->assertSame($memberId, $rows[0]->memberId);
        $this->assertNull($rows[0]->email);
        $this->assertSame(2, $rows[0]->rowIndex);
        $this->assertSame(['Tiers' => '456789', 'Email' => '', 'Prenom' => 'Louis', 'Montant' => '145'], $rows[0]->data);
        $this->assertNull($rows[1]->memberId);
        $this->assertSame('parent@example.be', $rows[1]->email);
    }

    public function testRowDataIsStoredEncrypted(): void
    {
        $this->createMember('456789');
        $path = $this->writeXlsx([['Tiers', 'Prenom'], ['456789', 'Louis']]);

        $this->service->import($path, 'f.xlsx', null);

        $raw = $this->pdo->query('SELECT data_encrypted FROM mass_mail_audience_rows')->fetchColumn();
        $this->assertStringNotContainsString('Louis', (string) $raw);
    }

    public function testAcceptsExportAliasHeadersAndMultiAddressCells(): void
    {
        $memberId = $this->createMember('123');
        $path = $this->writeXlsx([
            ['Identifiant Desk', 'Contact'],
            ['123', ''],
            ['', 'a@example.be; b@example.be'],
        ]);

        $result = $this->service->import($path, 'export-membres.xlsx', null);

        $rows = $this->audienceRepository->findRowsByAudience($result->audience->id);
        $this->assertSame($memberId, $rows[0]->memberId);
        $this->assertSame('a@example.be; b@example.be', $rows[1]->email);
    }

    public function testTiersWinsOverEmailOnTheSameRow(): void
    {
        $memberId = $this->createMember('456789');
        $path = $this->writeXlsx([
            ['Tiers', 'Email'],
            ['456789', 'ignored@example.be'],
        ]);

        $rows = $this->audienceRepository->findRowsByAudience($this->service->import($path, 'f.xlsx', null)->audience->id);
        $this->assertSame($memberId, $rows[0]->memberId);
        $this->assertNull($rows[0]->email);
    }

    public function testRefusesTheWholeFileListingEveryOffendingLine(): void
    {
        $this->createMember('456789');
        $path = $this->writeXlsx([
            ['Tiers', 'Email', 'Prenom'],
            ['456789', '', 'OK'],
            ['', 'jean.dupont@', 'Bad email'],
            ['789123', '', 'Unknown tiers'],
            ['', '', 'Neither'],
        ]);

        try {
            $this->service->import($path, 'f.xlsx', null);
            $this->fail('Expected AudienceImportException');
        } catch (AudienceImportException $e) {
            $this->assertCount(3, $e->errors);
            $this->assertStringContainsString('Ligne 3', $e->errors[0]);
            $this->assertStringContainsString('jean.dupont@', $e->errors[0]);
            $this->assertStringContainsString('Ligne 4', $e->errors[1]);
            $this->assertStringContainsString('789123', $e->errors[1]);
            $this->assertStringContainsString('Ligne 5', $e->errors[2]);
        }

        // All-or-nothing: nothing was stored.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_audiences')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_audience_rows')->fetchColumn());
    }

    public function testRefusesAFileWithNeitherTiersNorEmailColumn(): void
    {
        $path = $this->writeXlsx([['Prenom', 'Montant'], ['Louis', '145']]);

        $this->expectException(AudienceImportException::class);
        $this->service->import($path, 'f.xlsx', null);
    }

    public function testRefusesDuplicateHeaders(): void
    {
        $path = $this->writeXlsx([['Email', 'E-mail'], ['a@example.be', 'b@example.be']]);

        try {
            $this->service->import($path, 'f.xlsx', null);
            $this->fail('Expected AudienceImportException');
        } catch (AudienceImportException $e) {
            $this->assertStringContainsString('même en-tête', $e->errors[0]);
        }
    }

    public function testRefusesAnEmptyOrHeaderOnlyFile(): void
    {
        $path = $this->writeXlsx([['Email']]);

        $this->expectException(AudienceImportException::class);
        $this->service->import($path, 'f.xlsx', null);
    }

    public function testRefusesAFileThatIsNotXlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mm_notxlsx_');
        file_put_contents($path, 'this is not a spreadsheet');
        $this->tempFiles[] = $path;

        $this->expectException(AudienceImportException::class);
        $this->service->import($path, 'f.xlsx', null);
    }

    public function testSkipsEntirelyEmptyRows(): void
    {
        $path = $this->writeXlsx([
            ['Email', 'Prenom'],
            ['a@example.be', 'A'],
            ['', ''],
            ['b@example.be', 'B'],
        ]);

        $result = $this->service->import($path, 'f.xlsx', null);

        $this->assertSame(2, $result->audience->rowCount);
        $rows = $this->audienceRepository->findRowsByAudience($result->audience->id);
        // Excel line numbers survive the skip — the second data row is line 4.
        $this->assertSame(4, $rows[1]->rowIndex);
    }

    public function testOnlyTheFirstSheetIsRead(): void
    {
        // Second sheet full of invalid rows — must not matter.
        $path = $this->writeXlsx(
            [['Email'], ['ok@example.be']],
            'Feuille1',
            function (Spreadsheet $spreadsheet): void {
                $second = $spreadsheet->createSheet();
                $second->setTitle('Ignorée');
                $second->setCellValue('A1', 'Email');
                $second->setCellValue('A2', 'not-an-email');
            }
        );

        $result = $this->service->import($path, 'f.xlsx', null);

        $this->assertSame(1, $result->audience->rowCount);
    }

    public function testWarnsOnDuplicateTiersAndAddresses(): void
    {
        $this->createMember('456789');
        $path = $this->writeXlsx([
            ['Tiers', 'Email'],
            ['456789', ''],
            ['456789', ''],
            ['', 'dup@example.be'],
            ['', 'DUP@example.be'],
        ]);

        $result = $this->service->import($path, 'f.xlsx', null);

        $this->assertCount(2, $result->warnings);
        $this->assertStringContainsString('Tiers', $result->warnings[0]);
        $this->assertStringContainsString('adresse email', $result->warnings[1]);
    }

    public function testImportIsJournaledWithoutPersonalData(): void
    {
        $this->createMember('456789');
        $path = $this->writeXlsx([['Tiers'], ['456789']]);

        $this->service->import($path, 'f.xlsx', null);

        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'audience_imported'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringNotContainsString('456789', (string) $row['context']);
    }
}
