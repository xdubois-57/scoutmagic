<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Service\ListAddressImportException;
use Modules\MassMail\Service\ListAddressImportService;
use Modules\MassMail\Service\ListAddressService;
use Modules\MassMail\Service\MailingListException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * The Excel round trip, against real `.xlsx` files written to disk and
 * read back through the real reader — a fixture built with the same
 * library the importer uses is the only way to know the two agree.
 *
 * The heart of it is the two-step shape: an upload ANALYSES and writes
 * nothing, a confirmation writes. Replacing a list wholesale is the one
 * operation of this module with no way back, and a misspelled header
 * would otherwise cost three hundred addresses in silence.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListAddressImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private ListAddressImportService $service;
    private ListAddressService $addressService;
    private ListAddressRepository $repository;
    private SettingService $settings;
    private int $listId;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);

        $this->repository = new ListAddressRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->addressService = new ListAddressService(
            $this->repository,
            new MailingListRepository($this->pdo),
            $this->settings,
            $this->createMock(JournalService::class)
        );
        $this->service = new ListAddressImportService(
            $this->repository,
            $this->addressService,
            $this->createMock(JournalService::class)
        );

        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description) VALUES (?, ?)');
        $stmt->execute(['Ma liste', 'Description.']);
        $this->listId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    // --- Export ---------------------------------------------------------

    public function testTheExportCarriesTheThreeColumnsAndEveryAddress(): void
    {
        $this->addressService->add($this->listId, 'Commune de Wavre', 'jeunesse@wavre.be');
        $this->addressService->add($this->listId, null, 'cure@paroisse.be');
        $this->addressService->unsubscribeEverywhere('cure@paroisse.be');

        $rows = $this->readBack($this->service->export($this->listId));

        $this->assertSame(['Nom', 'Adresse', 'Désinscrit'], $rows[0]);
        // Sorted by name then address: the nameless one first.
        $this->assertSame(['', 'cure@paroisse.be', 'Oui'], $rows[1]);
        $this->assertSame(['Commune de Wavre', 'jeunesse@wavre.be', 'Non'], $rows[2]);
    }

    /**
     * SECURITY.md §23: a name is whatever somebody typed, and a leading
     * `=` in a general-typed cell is a live formula the moment a chief
     * opens the file.
     */
    public function testAValueThatLooksLikeAFormulaIsWrittenAsText(): void
    {
        $this->addressService->add($this->listId, '=HYPERLINK("http://x","cliquez")', 'piege@test.be');

        $spreadsheet = $this->service->export($this->listId);
        $cell = $spreadsheet->getActiveSheet()->getCell('A2');

        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=HYPERLINK("http://x","cliquez")', $cell->getValue());
    }

    public function testTheExportOfAnEmptyListIsJustItsHeaders(): void
    {
        $rows = $this->readBack($this->service->export($this->listId));

        $this->assertCount(1, $rows);
        $this->assertSame(['Nom', 'Adresse', 'Désinscrit'], $rows[0]);
    }

    /** A file this module wrote must be a file this module accepts. */
    public function testWhatTheExportWritesIsWhatTheImportReads(): void
    {
        $this->addressService->add($this->listId, 'Commune de Wavre', 'jeunesse@wavre.be');
        $this->addressService->add($this->listId, null, 'cure@paroisse.be');

        $preview = $this->service->analyse($this->listId, $this->write($this->service->export($this->listId)));

        $this->assertSame([], $preview->errors);
        $this->assertSame(
            ['added' => 0, 'unchanged' => 2, 'removed' => 0, 'kept_unsubscribed' => 0],
            $preview->summary
        );
    }

    // --- Analysis: structural refusals ----------------------------------

    /**
     * The whole reason columns are found by header: by position, a file
     * whose address column is misspelled would replace every address with
     * a column of names, silently.
     */
    public function testAMisspelledAddressHeaderRefusesTheWholeFileAndWritesNothing(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');
        $path = $this->spreadsheet([['Nom', 'Adrese'], ['Curé', 'cure@paroisse.be']]);

        try {
            $this->service->analyse($this->listId, $path);
            $this->fail('a misspelled address header should refuse the file');
        } catch (ListAddressImportException $e) {
            $this->assertStringContainsString('Adresse', $e->errors[0]);
            $this->assertStringContainsString('en-tête', $e->errors[0]);
        }

        $this->assertSame(1, $this->repository->countForList($this->listId)['total']);
    }

    public function testAFileWithNoAddressColumnAtAllIsRefused(): void
    {
        $path = $this->spreadsheet([['Nom', 'Téléphone'], ['Curé', '010 22 33 44']]);

        $this->expectException(ListAddressImportException::class);
        $this->service->analyse($this->listId, $path);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $path = $this->spreadsheet([]);

        $this->expectException(ListAddressImportException::class);
        $this->service->analyse($this->listId, $path);
    }

    public function testSomethingThatIsNotASpreadsheetIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-not-xlsx-');
        $this->tempFiles[] = (string) $path;
        file_put_contents((string) $path, 'ceci n\'est pas un classeur');

        $this->expectException(ListAddressImportException::class);
        $this->service->analyse($this->listId, (string) $path);
    }

    /** A column order nobody promised, and a header nobody spelled the same way twice. */
    public function testTheColumnsAreFoundWhereverTheyAreAndHoweverTheyAreSpelled(): void
    {
        $path = $this->spreadsheet([
            ['Désinscrit', 'E-MAIL', 'Contact'],
            ['Non', 'jeunesse@wavre.be', 'Commune'],
        ]);

        $preview = $this->service->analyse($this->listId, $path);

        $this->assertSame(
            [['name' => 'Commune', 'email' => 'jeunesse@wavre.be']],
            $preview->addresses
        );
    }

    // --- Analysis: per-line problems ------------------------------------

    public function testAnInvalidAddressIsReportedAndTheOthersStillPass(): void
    {
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Commune', 'jeunesse@wavre.be'],
            ['Cassé', 'pas-une-adresse'],
            ['Curé', 'cure@paroisse.be'],
        ]);

        $preview = $this->service->analyse($this->listId, $path);

        $this->assertCount(1, $preview->errors);
        $this->assertStringContainsString('Ligne 3', $preview->errors[0]);
        $this->assertSame(
            ['jeunesse@wavre.be', 'cure@paroisse.be'],
            array_column($preview->addresses, 'email')
        );
    }

    public function testTheSameAddressTwiceInTheFileBecomesOneLine(): void
    {
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Commune', 'jeunesse@wavre.be'],
            ['La commune', 'Jeunesse@Wavre.BE'],
        ]);

        $preview = $this->service->analyse($this->listId, $path);

        $this->assertCount(1, $preview->addresses);
        $this->assertSame(1, $preview->duplicates);
    }

    public function testABlankLineBetweenTwoBlocksIsNotAnError(): void
    {
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Commune', 'jeunesse@wavre.be'],
            ['', ''],
            ['Curé', 'cure@paroisse.be'],
        ]);

        $preview = $this->service->analyse($this->listId, $path);

        $this->assertSame([], $preview->errors);
        $this->assertCount(2, $preview->addresses);
    }

    public function testTheAnalysisCountsWhatAConfirmationWouldDoWithoutDoingIt(): void
    {
        $this->addressService->add($this->listId, 'Reste', 'reste@test.be');
        $this->addressService->add($this->listId, 'Part', 'part@test.be');
        $this->addressService->add($this->listId, 'Désinscrite', 'partie@test.be');
        $this->addressService->unsubscribeEverywhere('partie@test.be');

        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Reste', 'reste@test.be'],
            ['Nouvelle', 'nouvelle@test.be'],
        ]);

        $preview = $this->service->analyse($this->listId, $path);

        $this->assertSame(
            ['added' => 1, 'unchanged' => 1, 'removed' => 1, 'kept_unsubscribed' => 1],
            $preview->summary
        );
        // And nothing has moved: the analysis writes nothing.
        $this->assertSame(['total' => 3, 'unsubscribed' => 1], $this->repository->countForList($this->listId));
    }

    public function testTheCapRefusesTheFileAtAnalysisTimeSoNobodyIsAskedToConfirmIt(): void
    {
        $this->registerMax(2);
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Un', 'un@test.be'],
            ['Deux', 'deux@test.be'],
            ['Trois', 'trois@test.be'],
        ]);

        $this->expectException(MailingListException::class);
        $this->expectExceptionMessage('Rien n\'a été modifié.');
        $this->service->analyse($this->listId, $path);
    }

    // --- Applying -------------------------------------------------------

    public function testApplyingReplacesTheListAndReturnsWhatItDid(): void
    {
        $this->addressService->add($this->listId, 'Reste', 'reste@test.be');
        $this->addressService->add($this->listId, 'Part', 'part@test.be');

        $summary = $this->service->apply($this->listId, [
            ['name' => 'Reste', 'email' => 'reste@test.be'],
            ['name' => 'Nouvelle', 'email' => 'nouvelle@test.be'],
        ], null);

        $this->assertSame(
            ['added' => 1, 'unchanged' => 1, 'removed' => 1, 'kept_unsubscribed' => 0],
            $summary
        );
        $this->assertSame(
            ['nouvelle@test.be', 'reste@test.be'],
            array_column($this->repository->findForList($this->listId), 'email')
        );
    }

    /**
     * D3, the whole point of it: an unsubscribed row survives a wholesale
     * replacement, and no import re-subscribes it — not by naming it, not
     * by leaving it out.
     */
    public function testAnUnsubscribedAddressSurvivesTheReplacementWhateverTheFileSays(): void
    {
        $this->addressService->add($this->listId, 'Partie', 'partie@test.be');
        $this->addressService->unsubscribeEverywhere('partie@test.be');

        // Named in the file, and « Non » in the Désinscrit column.
        $this->service->apply($this->listId, [['name' => 'Partie', 'email' => 'partie@test.be']], null);
        $this->assertSame(1, $this->repository->countForList($this->listId)['unsubscribed']);

        // Absent from the file entirely.
        $this->service->apply($this->listId, [['name' => 'Autre', 'email' => 'autre@test.be']], null);
        $this->assertSame(['total' => 2, 'unsubscribed' => 1], $this->repository->countForList($this->listId));
    }

    public function testApplyingCorrectsANameWithoutDuplicatingTheRow(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');

        $this->service->apply($this->listId, [['name' => 'Commune de Wavre', 'email' => 'jeunesse@wavre.be']], null);

        $addresses = $this->repository->findForList($this->listId);
        $this->assertCount(1, $addresses);
        $this->assertSame('Commune de Wavre', $addresses[0]->name);
    }

    /**
     * The confirmation arrives in a request of its own and the file it
     * came from is gone, so what it carries is input like any other.
     */
    public function testTheConfirmationRevalidatesWhatItCarries(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->apply($this->listId, [['name' => null, 'email' => 'pas-une-adresse']], null);
    }

    public function testTheConfirmationDeduplicatesWhatItCarries(): void
    {
        $this->service->apply($this->listId, [
            ['name' => 'Un', 'email' => 'meme@test.be'],
            ['name' => 'Deux', 'email' => 'MEME@test.be'],
        ], null);

        $this->assertSame(1, $this->repository->countForList($this->listId)['total']);
    }

    public function testTheCapIsAskedAgainOnTheConfirmationAndNothingIsWrittenWhenItRefuses(): void
    {
        $this->registerMax(1);
        $this->addressService->add($this->listId, 'Déjà', 'deja@test.be');
        $this->addressService->unsubscribeEverywhere('deja@test.be');

        try {
            $this->service->apply($this->listId, [
                ['name' => 'Un', 'email' => 'un@test.be'],
                ['name' => 'Deux', 'email' => 'deux@test.be'],
            ], null);
            $this->fail('the cap should refuse the replacement');
        } catch (MailingListException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }

        $this->assertSame(['total' => 1, 'unsubscribed' => 1], $this->repository->countForList($this->listId));
    }

    // --- Helpers --------------------------------------------------------

    private function registerMax(int $value): void
    {
        $this->settings->register(
            ListAddressService::SETTING_MAX_ADDRESSES,
            (string) $value,
            'number',
            'Adresses par liste de diffusion (maximum)',
            'Plafond.',
            'mass_mail'
        );
        $this->settings->clearCache();
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @return string the path of a real .xlsx file, deleted in tearDown()
     */
    private function spreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        return $this->write($spreadsheet);
    }

    private function write(Spreadsheet $spreadsheet): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sm-list-addresses-') . '.xlsx';
        $this->tempFiles[] = $path;
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readBack(Spreadsheet $spreadsheet): array
    {
        $path = $this->write($spreadsheet);
        $loaded = (new XlsxReader())->load($path);

        try {
            $rows = $loaded->getSheet(0)->toArray(null, true, true, false);
        } finally {
            $loaded->disconnectWorksheets();
        }

        return array_map(
            static fn(array $row) => array_map(static fn($cell) => (string) ($cell ?? ''), array_values($row)),
            $rows
        );
    }
}
