<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Modules\MassMail\Service\AddressReactivationService;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\ContactGroupImportException;
use Modules\MassMail\Service\ContactGroupImportService;
use Modules\MassMail\Service\ListBlockService;
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
 * nothing, « Enregistrer » writes. Replacing a contact group wholesale is
 * the one operation of this module with no way back, and a misspelled
 * header would otherwise cost three hundred addresses in silence.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactGroupImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private ContactGroupImportService $service;
    private ContactRepository $repository;
    private ListBlockRepository $blocks;
    private SettingService $settings;
    private SuppressedAddressRepository $suppressedRepository;
    private int $listId;
    private int $blockId;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);

        $this->repository = new ContactRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->blocks = new ListBlockRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->suppressedRepository = new SuppressedAddressRepository($this->pdo);
        $this->service = new ContactGroupImportService(
            $this->blocks,
            $this->repository,
            new ListBlockService(
                $this->blocks,
                $this->repository,
                new MailingListRepository($this->pdo),
                $this->createStub(JournalService::class)
            ),
            $this->settings,
            $this->createStub(JournalService::class),
            $this->suppressedRepository
        );

        $this->listId = (new MailingListRepository($this->pdo))->create('Ma liste', 'Description.', null);
        $this->blockId = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    // --- Export ---------------------------------------------------------

    public function testTheExportCarriesTheThreeColumnsAndEveryContact(): void
    {
        $this->repository->create($this->blockId, 'Commune de Wavre', 'jeunesse@wavre.be');
        $this->repository->create($this->blockId, null, 'cure@paroisse.be');
        $this->repository->unsubscribeEverywhere('cure@paroisse.be');

        $rows = $this->readBack($this->service->export($this->blockId));

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
        $this->repository->create($this->blockId, '=HYPERLINK("http://x","cliquez")', 'piege@test.be');

        $spreadsheet = $this->service->export($this->blockId);
        $cell = $spreadsheet->getActiveSheet()->getCell('A2');

        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('=HYPERLINK("http://x","cliquez")', $cell->getValue());
    }

    public function testTheExportOfAnEmptyGroupIsJustItsHeaders(): void
    {
        $rows = $this->readBack($this->service->export($this->blockId));

        $this->assertCount(1, $rows);
        $this->assertSame(['Nom', 'Adresse', 'Désinscrit'], $rows[0]);
    }

    /** A file this module wrote must be a file this module accepts. */
    public function testWhatTheExportWritesIsWhatTheImportReads(): void
    {
        $this->repository->create($this->blockId, 'Commune de Wavre', 'jeunesse@wavre.be');
        $this->repository->create($this->blockId, null, 'cure@paroisse.be');

        $preview = $this->service->analyse($this->blockId, $this->write($this->service->export($this->blockId)));

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
        $this->repository->create($this->blockId, 'Commune', 'jeunesse@wavre.be');
        $path = $this->spreadsheet([['Nom', 'Adrese'], ['Curé', 'cure@paroisse.be']]);

        try {
            $this->service->analyse($this->blockId, $path);
            $this->fail('a misspelled address header should refuse the file');
        } catch (ContactGroupImportException $e) {
            $this->assertStringContainsString('Adresse', $e->errors[0]);
            $this->assertStringContainsString('en-tête', $e->errors[0]);
        }

        $this->assertSame(1, $this->repository->countForBlock($this->blockId)['total']);
    }

    public function testAFileWithNoAddressColumnAtAllIsRefused(): void
    {
        $path = $this->spreadsheet([['Nom', 'Téléphone'], ['Curé', '010 22 33 44']]);

        $this->expectException(ContactGroupImportException::class);
        $this->service->analyse($this->blockId, $path);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $path = $this->spreadsheet([]);

        $this->expectException(ContactGroupImportException::class);
        $this->service->analyse($this->blockId, $path);
    }

    public function testSomethingThatIsNotASpreadsheetIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-not-xlsx-');
        $this->tempFiles[] = (string) $path;
        file_put_contents((string) $path, 'ceci n\'est pas un classeur');

        $this->expectException(ContactGroupImportException::class);
        $this->service->analyse($this->blockId, (string) $path);
    }

    /** A column order nobody promised, and a header nobody spelled the same way twice. */
    public function testTheColumnsAreFoundWhereverTheyAreAndHoweverTheyAreSpelled(): void
    {
        $path = $this->spreadsheet([
            ['Désinscrit', 'E-MAIL', 'Contact'],
            ['Non', 'jeunesse@wavre.be', 'Commune'],
        ]);

        $preview = $this->service->analyse($this->blockId, $path);

        $this->assertSame(
            [['name' => 'Commune', 'email' => 'jeunesse@wavre.be']],
            $preview->contacts
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

        $preview = $this->service->analyse($this->blockId, $path);

        $this->assertCount(1, $preview->errors);
        $this->assertStringContainsString('Ligne 3', $preview->errors[0]);
        $this->assertSame(
            ['jeunesse@wavre.be', 'cure@paroisse.be'],
            array_column($preview->contacts, 'email')
        );
    }

    public function testTheSameAddressTwiceInTheFileBecomesOneLine(): void
    {
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Commune', 'jeunesse@wavre.be'],
            ['La commune', 'Jeunesse@Wavre.BE'],
        ]);

        $preview = $this->service->analyse($this->blockId, $path);

        $this->assertCount(1, $preview->contacts);
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

        $preview = $this->service->analyse($this->blockId, $path);

        $this->assertSame([], $preview->errors);
        $this->assertCount(2, $preview->contacts);
    }

    public function testTheAnalysisCountsWhatTheSaveWouldDoWithoutDoingIt(): void
    {
        $this->repository->create($this->blockId, 'Reste', 'reste@test.be');
        $this->repository->create($this->blockId, 'Part', 'part@test.be');
        $this->repository->create($this->blockId, 'Désinscrite', 'partie@test.be');
        $this->repository->unsubscribeEverywhere('partie@test.be');

        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Reste', 'reste@test.be'],
            ['Nouvelle', 'nouvelle@test.be'],
        ]);

        $preview = $this->service->analyse($this->blockId, $path);

        $this->assertSame(
            ['added' => 1, 'unchanged' => 1, 'removed' => 1, 'kept_unsubscribed' => 1],
            $preview->summary
        );
        // And nothing has moved: the analysis writes nothing.
        $this->assertSame(['total' => 3, 'unsubscribed' => 1], $this->repository->countForBlock($this->blockId));
    }

    public function testTheCapRefusesTheFileAtAnalysisTimeSoNobodyIsOfferedToSaveIt(): void
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
        $this->service->analyse($this->blockId, $path);
    }

    /**
     * The round trip of a group sitting AT the cap, which is exactly the
     * size of group this feature exists for. `export()` writes the
     * unsubscribed rows into the file, and `replaceForBlock()` counts such
     * a row as unchanged rather than as an addition — so counting every
     * unsubscribed row on top of the file's own count would refuse an
     * untouched export coming straight back.
     */
    public function testAnUntouchedExportComesBackEvenWhenTheGroupSitsAtTheCap(): void
    {
        $this->repository->create($this->blockId, 'Un', 'un@test.be');
        $this->repository->create($this->blockId, 'Deux', 'deux@test.be');
        $this->repository->unsubscribeEverywhere('deux@test.be');
        $this->registerMax(2);

        // The file the export produces: both rows, the unsubscribed one
        // included.
        $path = $this->spreadsheet([
            ['Nom', 'Adresse', 'Désinscrit'],
            ['Un', 'un@test.be', 'Non'],
            ['Deux', 'deux@test.be', 'Oui'],
        ]);

        $preview = $this->service->analyse($this->blockId, $path);
        $this->assertCount(2, $preview->contacts);

        $summary = $this->service->apply($this->blockId, $preview->contacts, null);
        $this->assertSame(0, $summary['added']);
        $this->assertSame(2, $summary['unchanged']);
        $this->assertSame(['total' => 2, 'unsubscribed' => 1], $this->repository->countForBlock($this->blockId));
    }

    /**
     * A duplicate header is a structural problem: reading from whichever
     * column came first is the silent wrong-column outcome that matching
     * by header exists to prevent.
     */
    public function testTwoColumnsClaimingTheSameRoleRefuseTheWholeFile(): void
    {
        $path = $this->spreadsheet([
            ['Nom', 'Adresse', 'E-mail'],
            ['Commune', 'jeunesse@wavre.be', 'autre@wavre.be'],
        ]);

        try {
            $this->service->analyse($this->blockId, $path);
            $this->fail('A duplicate header must refuse the file.');
        } catch (ContactGroupImportException $e) {
            $this->assertCount(1, $e->errors, 'one message per role, however many extra columns');
            $this->assertStringContainsString('deux colonnes « Adresse »', $e->errors[0]);
        }

        $this->assertSame(['total' => 0, 'unsubscribed' => 0], $this->repository->countForBlock($this->blockId));
    }

    /** Every structural problem at once, as the charter for this import says. */
    public function testStructuralProblemsAreListedTogether(): void
    {
        $path = $this->spreadsheet([
            ['Nom', 'Contact'],
            ['Commune', 'Wavre'],
        ]);

        try {
            $this->service->analyse($this->blockId, $path);
            $this->fail('A file with no address column must be refused.');
        } catch (ContactGroupImportException $e) {
            $this->assertCount(2, $e->errors);
            $this->assertStringContainsString('deux colonnes « Nom »', implode(' ', $e->errors));
            $this->assertStringContainsString('doit contenir une colonne « Adresse »', implode(' ', $e->errors));
        }
    }

    // --- Applying -------------------------------------------------------

    public function testApplyingReplacesTheGroupAndReturnsWhatItDid(): void
    {
        $this->repository->create($this->blockId, 'Reste', 'reste@test.be');
        $this->repository->create($this->blockId, 'Part', 'part@test.be');

        $summary = $this->service->apply($this->blockId, [
            ['name' => 'Reste', 'email' => 'reste@test.be'],
            ['name' => 'Nouvelle', 'email' => 'nouvelle@test.be'],
        ], null);

        $this->assertSame(
            ['added' => 1, 'unchanged' => 1, 'removed' => 1, 'kept_unsubscribed' => 0],
            $summary
        );
        $this->assertSame(
            ['nouvelle@test.be', 'reste@test.be'],
            array_column($this->repository->findForBlock($this->blockId), 'email')
        );
    }

    /**
     * D3, the whole point of it: an unsubscribed row survives a wholesale
     * replacement, and no import re-subscribes it — not by naming it, not
     * by leaving it out.
     */
    public function testAnUnsubscribedContactSurvivesTheReplacementWhateverTheFileSays(): void
    {
        $this->repository->create($this->blockId, 'Partie', 'partie@test.be');
        $this->repository->unsubscribeEverywhere('partie@test.be');

        // Named in the file, and « Non » in the Désinscrit column.
        $this->service->apply($this->blockId, [['name' => 'Partie', 'email' => 'partie@test.be']], null);
        $this->assertSame(1, $this->repository->countForBlock($this->blockId)['unsubscribed']);

        // Absent from the file entirely.
        $this->service->apply($this->blockId, [['name' => 'Autre', 'email' => 'autre@test.be']], null);
        $this->assertSame(['total' => 2, 'unsubscribed' => 1], $this->repository->countForBlock($this->blockId));
    }

    /**
     * D3, from the other side: `replaceForBlock()` protects the
     * unsubscribed rows of THIS group, but an address unsubscribed
     * elsewhere arrives as a brand-new line and would be created active.
     * The suppression table is the one place that knows.
     */
    public function testAFileNeverReopensADoorSomebodyAskedToBeShut(): void
    {
        $this->suppressedRepository->suppress('cure@paroisse.be');

        $this->service->apply($this->blockId, [
            ['name' => 'Commune', 'email' => 'jeunesse@wavre.be'],
            ['name' => 'Curé', 'email' => 'CURE@paroisse.be'],
        ], null);

        $byEmail = [];
        foreach ($this->repository->findForBlock($this->blockId) as $address) {
            $byEmail[$address->email] = $address;
        }

        $this->assertNull($byEmail['jeunesse@wavre.be']->unsubscribedAt);
        $this->assertNotNull($byEmail['cure@paroisse.be']->unsubscribedAt);
        $this->assertSame(
            ['jeunesse@wavre.be'],
            array_map(
                static fn($a): string => $a->email,
                $this->repository->findActiveForList($this->listId)
            )
        );
    }

    /**
     * A line born unsubscribed is not an address added: the preview the
     * chief confirms and the summary the save returns (and journals) both
     * count it among the unsubscribed — and say the same thing.
     */
    public function testAnExplicitReactivationSurvivesALaterRealExcelRoundTrip(): void
    {
        $this->repository->create($this->blockId, 'Commune', 'jeunesse@wavre.be');
        $this->repository->unsubscribeEverywhere('jeunesse@wavre.be');
        $this->suppressedRepository->suppress('jeunesse@wavre.be');
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = $this->createStub(JournalService::class);
        $reactivation = new \Core\Member\EmailAddressReactivationService(
            new \Core\Member\MemberEmailRepository($this->pdo, $encryption), $journal,
            new AddressReactivationService($this->pdo, $this->repository, $this->suppressedRepository, $journal)
        );
        $reactivation->reactivate('jeunesse@wavre.be', null);
        $path = $this->spreadsheet([['Nom', 'Adresse'], ['Commune corrigée', 'JEUNESSE@wavre.be']]);
        $preview = $this->service->analyse($this->blockId, $path);
        $this->assertSame(0, $preview->summary['kept_unsubscribed']);
        $this->service->apply($this->blockId, $preview->contacts, null);
        $this->assertFalse($this->repository->findForBlock($this->blockId)[0]->isUnsubscribed());
        $this->assertSame('Commune corrigée', $this->repository->findForBlock($this->blockId)[0]->name);
    }

    public function testASuppressedLineIsCountedAsUnsubscribedByThePreviewAndTheSave(): void
    {
        $this->repository->create($this->blockId, 'Reste', 'reste@test.be');
        $this->suppressedRepository->suppress('cure@paroisse.be');
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Reste', 'reste@test.be'],
            ['Commune', 'jeunesse@wavre.be'],
            ['Curé', 'CURE@paroisse.be'],
        ]);
        $expected = ['added' => 1, 'unchanged' => 1, 'removed' => 0, 'kept_unsubscribed' => 1];

        $preview = $this->service->analyse($this->blockId, $path);
        $this->assertSame($expected, $preview->summary);

        $this->assertSame($expected, $this->service->apply($this->blockId, $preview->contacts, null));
        $this->assertSame(['total' => 3, 'unsubscribed' => 1], $this->repository->countForBlock($this->blockId));
    }

    /**
     * A group not created yet holds nothing, so a suppressed line in its
     * file is counted the same way.
     */
    public function testASuppressedLineInTheFileOfANewGroupIsNotCountedAsAdded(): void
    {
        $this->suppressedRepository->suppress('cure@paroisse.be');
        $path = $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Commune', 'jeunesse@wavre.be'],
            ['Curé', 'cure@paroisse.be'],
        ]);

        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 0, 'kept_unsubscribed' => 1],
            $this->service->analyse(null, $path)->summary
        );
    }

    /**
     * The flag goes through ListBlockService, which journals it, and stays
     * the global unsubscribe: any other group still holding the address
     * active is flagged with it.
     */
    public function testTheSuppressionPassIsJournalledAndReachesEveryGroup(): void
    {
        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with('mass_mail', 'list_contact_unsubscribed', 'info', $this->anything(), ['rows' => 2]);
        $service = new ContactGroupImportService(
            $this->blocks,
            $this->repository,
            new ListBlockService($this->blocks, $this->repository, new MailingListRepository($this->pdo), $journal),
            $this->settings,
            $this->createStub(JournalService::class),
            $this->suppressedRepository
        );
        $other = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Autres', null);
        $this->repository->create($other, 'Curé', 'cure@paroisse.be');
        $this->suppressedRepository->suppress('cure@paroisse.be');

        $service->apply($this->blockId, [['name' => 'Curé', 'email' => 'cure@paroisse.be']], null);

        $this->assertSame(['total' => 1, 'unsubscribed' => 1], $this->repository->countForBlock($other));
    }

    public function testApplyingCorrectsANameWithoutDuplicatingTheRow(): void
    {
        $this->repository->create($this->blockId, 'Commune', 'jeunesse@wavre.be');

        $this->service->apply($this->blockId, [['name' => 'Commune de Wavre', 'email' => 'jeunesse@wavre.be']], null);

        $addresses = $this->repository->findForBlock($this->blockId);
        $this->assertCount(1, $addresses);
        $this->assertSame('Commune de Wavre', $addresses[0]->name);
    }

    /**
     * The save arrives in a request of its own and the file it
     * came from is gone, so what it carries is input like any other.
     */
    public function testTheSaveRevalidatesWhatItCarries(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->apply($this->blockId, [['name' => null, 'email' => 'pas-une-adresse']], null);
    }

    public function testTheSaveDeduplicatesWhatItCarries(): void
    {
        $this->service->apply($this->blockId, [
            ['name' => 'Un', 'email' => 'meme@test.be'],
            ['name' => 'Deux', 'email' => 'MEME@test.be'],
        ], null);

        $this->assertSame(1, $this->repository->countForBlock($this->blockId)['total']);
    }

    public function testTheCapIsAskedAgainOnTheSaveAndNothingIsWrittenWhenItRefuses(): void
    {
        $this->registerMax(1);
        $this->repository->create($this->blockId, 'Déjà', 'deja@test.be');
        $this->repository->unsubscribeEverywhere('deja@test.be');

        try {
            $this->service->apply($this->blockId, [
                ['name' => 'Un', 'email' => 'un@test.be'],
                ['name' => 'Deux', 'email' => 'deux@test.be'],
            ], null);
            $this->fail('the cap should refuse the replacement');
        } catch (MailingListException $e) {
            $this->assertStringContainsString('maximum', $e->getMessage());
        }

        $this->assertSame(['total' => 1, 'unsubscribed' => 1], $this->repository->countForBlock($this->blockId));
    }

    public function testApplyingStampsWhenTheFileReplacedTheContacts(): void
    {
        $this->assertNull($this->blocks->findById($this->blockId)?->fileReplacedAt);

        $this->service->apply($this->blockId, [['name' => null, 'email' => 'un@test.be']], null);

        $this->assertNotNull($this->blocks->findById($this->blockId)?->fileReplacedAt);
    }

    /** The cap is per GROUP: two groups each at the cap are fine together. */
    public function testTheCapIsCountedPerGroup(): void
    {
        $this->registerMax(2);
        $other = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Autres', null);

        $this->service->apply($this->blockId, [
            ['name' => null, 'email' => 'un@test.be'],
            ['name' => null, 'email' => 'deux@test.be'],
        ], null);
        $this->service->apply($other, [
            ['name' => null, 'email' => 'trois@test.be'],
            ['name' => null, 'email' => 'quatre@test.be'],
        ], null);

        $this->assertSame(2, $this->repository->countForBlock($this->blockId)['total']);
        $this->assertSame(2, $this->repository->countForBlock($other)['total']);
    }

    public function testAMemberGroupHasNoFileToReplace(): void
    {
        $member = $this->blocks->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);

        $this->expectException(MailingListException::class);
        $this->service->apply($member, [['name' => null, 'email' => 'un@test.be']], null);
    }

    // --- Creating and saving a group ------------------------------------

    /** Before a group exists: the empty template, and a file whose every line is an addition. */
    public function testAGroupNotCreatedYetHasAnEmptyTemplateAndAnAnalysisOfItsOwn(): void
    {
        $this->assertSame([['Nom', 'Adresse', 'Désinscrit']], $this->readBack($this->service->export(null)));

        $preview = $this->service->analyse(null, $this->spreadsheet([
            ['Nom', 'Adresse'],
            ['Commune', 'jeunesse@wavre.be'],
            ['Curé', 'cure@paroisse.be'],
        ]));

        $this->assertSame(
            ['added' => 2, 'unchanged' => 0, 'removed' => 0, 'kept_unsubscribed' => 0],
            $preview->summary
        );
        // Analysing wrote nothing: still the one group setUp() created.
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    public function testCreatingAGroupWritesTheNameAndTheContactsTogether(): void
    {
        $block = $this->service->createGroup($this->listId, '  Partenaires de la fête  ', [
            ['name' => 'Commune', 'email' => 'jeunesse@wavre.be'],
            ['name' => null, 'email' => 'CURE@paroisse.be'],
        ], 3);

        $this->assertTrue($block->isContactGroup());
        $this->assertSame('Partenaires de la fête', $block->name);
        $this->assertSame(3, $block->createdBy);
        $this->assertNotNull($block->fileReplacedAt);
        $this->assertSame(
            ['cure@paroisse.be', 'jeunesse@wavre.be'],
            array_map(static fn($c) => $c->email, $this->repository->findForBlock($block->id))
        );
    }

    /**
     * §2.1: a contact group carries a name. Refused before anything is
     * written — no empty group left behind.
     */
    public function testAGroupWithoutANameIsRefusedAndNothingIsWritten(): void
    {
        try {
            $this->service->createGroup($this->listId, '   ', [['name' => null, 'email' => 'un@test.be']], null);
            $this->fail('a nameless contact group must be refused');
        } catch (MailingListException $e) {
            $this->assertSame('Le nom du groupe de contacts est obligatoire.', $e->getMessage());
        }

        $this->assertSame(1, count($this->blocks->findForList($this->listId)));
    }

    public function testANameOverOneHundredFiftyCharactersIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createGroup($this->listId, str_repeat('a', 151), [], null);
    }

    public function testAGroupWhoseFileIsRefusedIsNotCreatedAtAll(): void
    {
        $this->registerMax(1);

        try {
            $this->service->createGroup($this->listId, 'Trop grand', [
                ['name' => null, 'email' => 'un@test.be'],
                ['name' => null, 'email' => 'deux@test.be'],
            ], null);
            $this->fail('the cap should refuse the new group');
        } catch (MailingListException $e) {
            $this->assertStringContainsString('Contacts par groupe (maximum)', $e->getMessage());
        }

        $this->assertSame(1, count($this->blocks->findForList($this->listId)));
    }

    public function testCreatingAGroupOnAnUnknownListIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createGroup(999, 'Partenaires', [], null);
    }

    public function testSavingAGroupCanRenameItWithoutTouchingItsContacts(): void
    {
        $this->repository->create($this->blockId, 'Commune', 'jeunesse@wavre.be');

        $result = $this->service->updateGroup($this->blockId, 'Partenaires locaux', null, null);

        $this->assertSame('Partenaires locaux', $result['block']->name);
        $this->assertNull($result['summary']);
        $this->assertNull($result['block']->fileReplacedAt);
        $this->assertSame(1, $this->repository->countForBlock($this->blockId)['total']);
    }

    public function testSavingAGroupAppliesTheNameAndTheFileTogether(): void
    {
        $this->repository->create($this->blockId, 'Commune', 'jeunesse@wavre.be');

        $result = $this->service->updateGroup(
            $this->blockId,
            'Partenaires locaux',
            [['name' => 'Curé', 'email' => 'cure@paroisse.be']],
            null
        );

        $this->assertSame('Partenaires locaux', $result['block']->name);
        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 1, 'kept_unsubscribed' => 0],
            $result['summary']
        );
        $this->assertNotNull($result['block']->fileReplacedAt);
    }

    /** A refused file leaves the group exactly as it was — its name included. */
    public function testARefusedFileLeavesTheNameUnchangedToo(): void
    {
        try {
            $this->service->updateGroup($this->blockId, 'Autre nom', [['name' => null, 'email' => 'cassé']], null);
            $this->fail('an invalid address must refuse the save');
        } catch (MailingListException) {
        }

        $this->assertSame('Partenaires', $this->blocks->findById($this->blockId)?->name);
    }

    /** §2.1: a block's kind never changes, and a member group has no name to save. */
    public function testAMemberGroupCannotBeSavedAsAContactGroup(): void
    {
        $member = $this->blocks->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);

        $this->expectException(MailingListException::class);
        $this->service->updateGroup($member, 'Un nom', null, null);
    }

    // --- Helpers --------------------------------------------------------

    private function registerMax(int $value): void
    {
        $this->settings->register(
            ContactGroupImportService::SETTING_MAX_CONTACTS,
            (string) $value,
            'number',
            'Contacts par groupe (maximum)',
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
        $path = (string) tempnam(sys_get_temp_dir(), 'sm-contact-group-') . '.xlsx';
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
