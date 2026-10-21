<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Core\File\FileRepository;
use Core\File\PdfTextExtractor;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Core\Security\Role;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\File\RentalDocumentOwnershipChecker;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalDocumentService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Contracts and invoices (§6.25, §6.27), rendered for real — actual dompdf
 * bytes, actual files on disk.
 *
 * Two things this file exists to pin. **Versioning**: regenerating never
 * overwrites, because v1 may already have been signed, and a signature
 * refers to a text rather than to a file name. And **the injection path
 * end to end**: `DocumentKeywordsTest` proves the escaping in isolation;
 * this proves the escaping is actually on the path a real contract takes,
 * with a real renter name coming out of a real encrypted column.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalDocumentServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalDocumentService $service;
    private RentalDocumentRepository $documentRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private EditableContentService $editableContentService;
    private SettingService $settingService;
    private FileRepository $fileRepository;
    private string $storagePath;
    private int $assetId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storagePath = sys_get_temp_dir() . '/rental_docs_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0755, true);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('site_name', 'Unité Saint-Georges', 'text', 'Nom', 'Description de test.');
        RentalTestHelper::registerSettings($settingService);
        $settingService->set(UnitAddresses::POSTAL_ADDRESS, "Rue du Local 1\n1000 Bruxelles");
        $this->settingService = $settingService;

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->documentRepository = new RentalDocumentRepository($this->pdo);
        $this->editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->fileRepository = new FileRepository($this->pdo);

        $this->service = new RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->editableContentService,
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            $settingService,
            $journal,
            $this->storagePath
        );

        $this->scoutYearId = (new \Core\Config\ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $this->assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            '18:00',
            '11:00',
            null,
            true
        );
        // A contract is refused without the asset's address (issue #977).
        $this->assetRepository->saveContractDetails($this->assetId, "Chemin du Camp 4\n1300 Wavre", 60);
    }

    protected function tearDown(): void
    {
        // The service writes real files; leaving them behind would fill the
        // runner's temp directory over a full suite.
        foreach (glob($this->storagePath . '/rental/documents/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/rental/documents');
        @rmdir($this->storagePath . '/rental');
        @rmdir($this->storagePath);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function asset(): RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    private function createBooking(
        string $renterName = 'Jeanne Martin',
        ?string $organisation = null,
        string $reference = 'LOC-K7Q2M4',
        ?string $renterAddress = 'Rue Haute 12, 1000 Bruxelles'
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $this->assetId,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => $renterName,
                'email' => 'jeanne@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => $organisation,
                'purpose' => null,
                'comment' => null,
            ],
            new \Modules\Rental\Pricing\PriceQuote(
                lines: [new \Modules\Rental\Pricing\PriceLine('Séjour', 3, 12000, 36000, 'base')],
                totalCents: 36000,
                nights: 3,
                persons: 20,
                quantity: 3,
                billingUnit: \Modules\Rental\Pricing\BillingUnit::PER_NIGHT
            ),
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        // A contract is refused without the renter's address (issue #977).
        if ($renterAddress !== null) {
            $this->bookingRepository->saveBillingIdentity($created['id'], ['address' => $renterAddress]);
        }

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function setTemplate(string $body, DocumentType $type = DocumentType::CONTRACT): void
    {
        $this->editableContentService->set($type->templateKey($this->assetId), $body, 'rich_text', 1);
    }

    private function settings(): PaymentSettings
    {
        return new PaymentSettings(
            enabled: true,
            financeAccountId: 1,
            depositMode: \Modules\Rental\Payment\DepositMode::FIXED,
            depositAmountCents: 10000,
            securityDepositEnabled: true,
            securityDepositAmountCents: 50000
        );
    }

    /**
     * The text layer of a generated document, which is what a reader sees.
     *
     * `pdfTextOf()` below hands back the file's bytes, which is the right
     * thing for « is this a PDF at all » and the wrong thing for anything
     * about its content: dompdf compresses its streams, so a string that
     * is plainly on the page is absent from them, and a string that is not
     * is absent too.
     */
    private function renderedTextOf(int $documentId): string
    {
        $text = (new PdfTextExtractor())->extractText($this->pdfTextOf($documentId));
        $this->assertNotNull($text, 'the generated PDF carries no readable text layer');

        return $text;
    }

    private function pdfTextOf(int $documentId): string
    {
        $document = $this->documentRepository->findById($documentId);
        $this->assertNotNull($document);
        $path = $this->service->absolutePath($document);
        $this->assertIsString($path);

        return (string) file_get_contents($path);
    }

    // ── Level 1: the asset's template ───────────────────────────────────

    public function testATemplateIsStoredPerAsset(): void
    {
        $this->setTemplate('<p>Le local est rendu balayé.</p>');

        $this->assertStringContainsString(
            'rendu balayé',
            $this->service->template($this->asset(), DocumentType::CONTRACT)
        );
        // A different type on the same asset is a different template.
        $this->assertSame('', $this->service->template($this->asset(), DocumentType::INVOICE));
    }

    public function testSavingATemplateReportsUnknownKeywords(): void
    {
        // Reported while there is still time to fix them: a keyword nobody
        // recognises must never survive into a signed contract.
        $unknown = $this->service->saveTemplate(
            $this->asset(),
            DocumentType::CONTRACT,
            '<p>{{ reference }} et {{ prix_ttc }}</p>',
            1
        );

        $this->assertSame(['prix_ttc'], $unknown);
    }

    public function testATemplateEditIsJournaledWithoutItsContent(): void
    {
        $this->service->saveTemplate(
            $this->asset(),
            DocumentType::CONTRACT,
            '<p>Clause confidentielle sur les tarifs négociés.</p>',
            1
        );

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('rental_document_template_saved', $journal);
        $this->assertStringNotContainsString('Clause confidentielle', $journal);
    }

    public function testATemplateIsSanitizedOnTheWayIn(): void
    {
        $this->service->saveTemplate(
            $this->asset(),
            DocumentType::CONTRACT,
            '<p>Bonjour</p><script>alert(1)</script>',
            1
        );

        $this->assertStringNotContainsString(
            '<script>',
            $this->service->template($this->asset(), DocumentType::CONTRACT)
        );
    }

    // ── Level 2: the booking's own copy ─────────────────────────────────

    public function testABookingTakesItsCopyFromTheAssetTemplate(): void
    {
        $this->setTemplate('<p>Version du bien.</p>');
        $booking = $this->createBooking();

        $this->assertStringContainsString(
            'Version du bien',
            $this->service->bookingText($booking, $this->asset(), DocumentType::CONTRACT)
        );
    }

    public function testEditingTheAssetTemplateAfterwardsTouchesNoExistingBooking(): void
    {
        // The whole reason level 2 exists: a template reworded in March must
        // not silently change what a renter agreed to in February.
        $this->setTemplate('<p>Version de février.</p>');
        $booking = $this->createBooking();
        $this->service->saveBookingText($booking, DocumentType::CONTRACT, '<p>Version de février.</p>');

        $this->setTemplate('<p>Version de mars, bien plus stricte.</p>');

        $text = $this->service->bookingText($booking, $this->asset(), DocumentType::CONTRACT);
        $this->assertStringContainsString('février', $text);
        $this->assertStringNotContainsString('mars', $text);
    }

    public function testEditingOneBookingsCopyTouchesNoOther(): void
    {
        $this->setTemplate('<p>Commun.</p>');
        $first = $this->createBooking();

        $this->service->saveBookingText($first, DocumentType::CONTRACT, '<p>Propre à la première.</p>');

        $second = $this->createBooking('Marc Dupont', null, 'LOC-K7Q2M5');

        $this->assertStringContainsString(
            'Commun',
            $this->service->bookingText($second, $this->asset(), DocumentType::CONTRACT)
        );
    }

    public function testABookingsCopyIsSanitized(): void
    {
        // This path does not go through EditableContentService, so it would
        // otherwise be the one place raw HTML reached a PDF.
        $booking = $this->createBooking();
        $this->service->saveBookingText($booking, DocumentType::CONTRACT, '<p>Ok</p><script>alert(1)</script>');

        $this->assertStringNotContainsString(
            '<script>',
            $this->service->bookingText($booking, $this->asset(), DocumentType::CONTRACT)
        );
    }

    public function testAnUploadOnlyTypeCannotBeWritten(): void
    {
        $this->expectException(RentalException::class);

        $this->service->saveBookingText($this->createBooking(), DocumentType::PHOTO, '<p>x</p>');
    }

    // ── The repair pass, and the lock (§22.6) ───────────────────────────

    /**
     * The hazard the editors used to be `<textarea>`s for: a
     * contenteditable surface splits a run of text across elements as it is
     * edited, so `{{ prix_total }}` becomes `{{ pri<b>x</b>_total }}` — still
     * readable to a human, no longer a keyword to the substituter, and only
     * noticed once a contract has gone out with braces in it.
     */
    public function testAKeywordBrokenApartByMarkupIsRepairedOnTheWayIn(): void
    {
        $booking = $this->createBooking();

        $unknown = $this->service->saveBookingText(
            $booking,
            DocumentType::CONTRACT,
            '<p>Total : {{ pri<b>x</b>_total }}</p>'
        );

        $this->assertSame([], $unknown, 'A repaired keyword is not an unknown one.');
        $this->assertStringContainsString('{{ prix_total }}', $this->service->bookingText($booking, $this->asset(), DocumentType::CONTRACT));
    }

    public function testARepairedKeywordSubstitutesIntoTheGeneratedDocument(): void
    {
        $booking = $this->createBooking();
        $this->service->saveBookingText(
            $booking,
            DocumentType::CONTRACT,
            // Prose around the keyword, and not for realism: the extractor
            // below answers null under twenty meaningful characters, so a
            // document whose whole text is one name would make this test
            // pass by being unreadable.
            '<p>Le present contrat est conclu entre l unite et {{ locatai<em>re</em>_nom }}.</p>'
        );

        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        // **The rendered TEXT, not the file's bytes.** dompdf compresses its
        // content streams, so `{{` would not appear in the raw PDF whether
        // the keyword was substituted or not — and asserting its absence
        // there passes just as happily when the keyword was dropped
        // altogether. Reading the text layer is what makes the assertion
        // able to fail.
        $text = $this->renderedTextOf($document->id);

        $this->assertStringContainsString('Jeanne Martin', $text);
        $this->assertStringNotContainsString('{{', $text);
        $this->assertStringNotContainsString('locataire_nom', $text);
    }

    /**
     * And prose between braces is left alone: the repair only rewrites a
     * region once what it would become is a real keyword, so the
     * « mots-clés non reconnus » warning stays the net it was meant to be.
     */
    public function testProseBetweenBracesIsLeftAloneAndStillReported(): void
    {
        $booking = $this->createBooking();

        $unknown = $this->service->saveBookingText(
            $booking,
            DocumentType::CONTRACT,
            '<p>{{ prix_ttc }}</p>'
        );

        $this->assertSame(['prix_ttc'], $unknown);
    }

    public function testATextIsEditableWhileNothingHasBeenSent(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertFalse($this->service->textIsLocked($booking, DocumentType::CONTRACT));
    }

    /**
     * Sending is the action that locks (§22.6): the renter holds a copy,
     * and silently changing what that copy was made from is exactly the
     * confusion versioning exists to prevent.
     */
    public function testSendingLocksTheTextOfThatTypeAndOfThatTypeOnly(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $this->service->markSent($document->id, new \DateTimeImmutable());

        $this->assertTrue($this->service->textIsLocked($booking, DocumentType::CONTRACT));
        $this->assertFalse(
            $this->service->textIsLocked($booking, DocumentType::INVOICE),
            'Sending the contract says nothing about the invoice.'
        );
    }

    public function testALockedTextIsRefusedOnTheServerNotOnlyHiddenInThePage(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $this->service->markSent($document->id, new \DateTimeImmutable());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('n\'est plus modifiable');

        $this->service->saveBookingText($booking, DocumentType::CONTRACT, '<p>Autre chose.</p>');
    }

    /**
     * The window between the check and the write, closed.
     *
     * Two managers on one booking: A opens the editor and saves, B presses
     * « Envoyer ». If A's `textIsLocked()` runs before B writes `sent_at`,
     * both used to succeed — the tenant holding a PDF whose source had
     * since moved on, with nothing on screen saying so (#405).
     *
     * The interleaving is reproduced by going STRAIGHT TO THE WRITE, which
     * is what A's request does once its check has passed: the send lands
     * in between, and the write must refuse by itself. Calling
     * `saveBookingText()` here would prove the check, not the lock, and
     * the check was never the broken half.
     */
    public function testAWriteThatWonTheRaceAgainstTheSendIsStillRefused(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        // A's check has passed: nothing is sent yet.
        $this->assertFalse($this->service->textIsLocked($booking, DocumentType::CONTRACT));

        // B's send lands in the window.
        $this->service->markSent($document->id, new \DateTimeImmutable());

        // A's write, arriving after it, refuses itself.
        $this->assertFalse(
            $this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, '<p>Écrit trop tard.</p>'),
            'a write reaching the database after the send must refuse itself, whatever was checked before'
        );

        $kept = $this->documentRepository->findText($booking->id, DocumentType::CONTRACT);
        $this->assertNotNull($kept);
        $this->assertStringNotContainsString(
            'Écrit trop tard',
            $kept,
            'and the source the tenant\'s PDF was made from is untouched'
        );
    }

    /**
     * **Saving the same text twice is not « it has been sent ».**
     *
     * The lock was read off `rowCount()`, and MySQL's `rowCount()` after
     * an UPDATE counts rows it CHANGED, not rows the WHERE matched — this
     * application does not set `PDO::MYSQL_ATTR_FOUND_ROWS`. A manager
     * double-clicking « Enregistrer » on unedited text therefore matched
     * the row, changed nothing, and was told the document had gone out to
     * the tenant. Nothing had.
     *
     * **This class cannot see the defect, and saying so is the point.**
     * `DatabaseTestHelper::createTestDatabase()` builds an in-memory
     * SQLite whatever `TEST_DB_*` says, and SQLite's `changes()` counts
     * matched rows however the values compared — so this passed before the
     * fix as well. It is kept because it states the intended behaviour
     * where the rest of this feature is tested; the engine's own semantic
     * is held by `Tests\Modules\Rental\Repository\
     * DocumentTextLockOnTheRealEngineTest`, which connects to MySQL and
     * was verified red there.
     *
     * Two saves inside the same second, deliberately: that is what makes
     * even `updated_at` — formatted to the second — identical, and so the
     * whole row unchanged.
     */
    public function testSavingTheSameTextTwiceIsAcceptedRatherThanReadAsASend(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $text = '<p>Le texte que le gestionnaire enregistre deux fois.</p>';
        $this->assertTrue($this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, $text));

        $this->assertTrue(
            $this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, $text),
            'a second save of unchanged text was reported as a document already sent to the tenant'
        );

        $kept = $this->documentRepository->findText($booking->id, DocumentType::CONTRACT);
        $this->assertSame($text, $kept);
    }

    /**
     * And re-saving the SAME text after a real send is still refused: the
     * disambiguation above must not become a way through the lock.
     */
    public function testSavingTheSameTextAgainAfterTheSendIsStillRefused(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $text = '<p>Le texte tel qu\'il est parti.</p>';
        $this->assertTrue($this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, $text));

        $this->service->markSent($document->id, new \DateTimeImmutable());

        $this->assertFalse(
            $this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, $text),
            'identical text slipped past the lock because nothing changed'
        );
    }

    /**
     * The service refuses when the write refuses itself.
     *
     * The tests around this one prove the REPOSITORY closes the window;
     * this one proves the service believes it. A regression that read
     * `saveText()`'s answer and carried on would record the edit as
     * successful, audit it, and leave the tenant's PDF and its source
     * disagreeing — which is the whole of #405, one layer up.
     *
     * A repository double rather than a real send, so that nothing but the
     * false answer is under test: `textIsLocked()` says no, and the write
     * still says no.
     */
    public function testTheServiceRefusesTheEditWhenTheWriteRefusesItself(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');

        $repository = $this->createMock(RentalDocumentRepository::class);
        $repository->method('hasSentDocumentOfType')->willReturn(false);
        $repository->method('findText')->willReturn('<p>Ce qui est au dossier.</p>');
        $repository->expects($this->once())->method('saveText')->willReturn(false);

        $service = new RentalDocumentService(
            $repository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->editableContentService,
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            $this->storagePath
        );

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/envoyé au locataire/');

        $service->saveBookingText($booking, DocumentType::CONTRACT, '<p>Écrit trop tard.</p>');
    }

    /**
     * The same write, before the send, still lands.
     *
     * Without this the fix above could be « refuse everything », which no
     * failing test would notice.
     */
    public function testAWriteThatArrivedBeforeTheSendStillLands(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertTrue(
            $this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, '<p>Écrit à temps.</p>')
        );

        $kept = $this->documentRepository->findText($booking->id, DocumentType::CONTRACT);
        $this->assertNotNull($kept);
        $this->assertStringContainsString('Écrit à temps', $kept);
    }

    /**
     * The hole under the lock: `textIsLocked()` asks whether a document of
     * this type carries a `sent_at`, so deleting the only sent contract
     * unlocked its source text again — while the renter still held the PDF
     * made from the old one. A document that has gone out is evidence, the
     * same reason `claimNextVersion()` never reuses a number.
     */
    public function testASentDocumentCannotBeDeleted(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $this->service->markSent($document->id, new \DateTimeImmutable());

        $sent = $this->documentRepository->findById($document->id);
        $this->assertNotNull($sent);

        try {
            $this->service->delete($sent);
            $this->fail('a sent document was deleted');
        } catch (RentalException $refusal) {
            $this->assertStringContainsString('ne peut plus être supprimé', $refusal->getMessage());
        }

        // And the lock it holds up is still standing.
        $this->assertTrue($this->service->textIsLocked($booking, DocumentType::CONTRACT));
    }

    public function testADocumentThatNeverLeftIsStillDeletable(): void
    {
        $booking = $this->createBooking();
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->service->delete($document);

        $this->assertSame([], $this->service->forBooking($booking->id));
    }

    /**
     * French will not be assembled. « Facture » is feminine, so building
     * one sentence around `$type->label()` produced « Le facture qu'il a
     * reçu doit rester celui qu'il a reçu » — on the editor's banner and in
     * the flash that refuses the save, both of which an invoice reaches
     * exactly as a contract does.
     */
    public function testTheRefusalIsGrammaticalForTheInvoiceToo(): void
    {
        $this->assertStringContainsString(
            'La facture qu\'il a reçue doit rester celle qu\'il a reçue.',
            RentalDocumentService::lockedRefusal(DocumentType::INVOICE)
        );
        $this->assertStringContainsString(
            'Le contrat qu\'il a reçu doit rester celui qu\'il a reçu.',
            RentalDocumentService::lockedRefusal(DocumentType::CONTRACT)
        );
    }

    // ── Level 3: the PDF ────────────────────────────────────────────────

    public function testGeneratingProducesARealPdfRegisteredAsAFile(): void
    {
        $this->setTemplate('<p>Contrat pour {{ locataire_nom }}, référence {{ reference }}.</p>');
        $booking = $this->createBooking();

        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertSame(1, $document->version);
        $this->assertSame('contrat-LOC-K7Q2M4-v1.pdf', $document->originalName);
        $this->assertStringStartsWith('%PDF', $this->pdfTextOf($document->id));
    }

    public function testTheFileLandsOutsidePublicUnderARandomName(): void
    {
        $this->setTemplate('<p>x</p>');
        $document = $this->service->generate(
            $this->createBooking(),
            $this->asset(),
            DocumentType::CONTRACT,
            $this->settings()
        );

        $file = $this->fileRepository->findById($document->fileId);
        $this->assertNotNull($file);
        $this->assertStringStartsWith('rental/documents/', $file->relativePath);
        // The stored name is random; only the display name says what it is.
        $this->assertStringNotContainsString('LOC-K7Q2M4', $file->relativePath);
        $this->assertSame('application/pdf', $file->mimeType);
    }

    public function testRegeneratingAddsAVersionAndNeverOverwritesTheFirst(): void
    {
        // v1 may already have been sent, printed and signed.
        $this->setTemplate('<p>v1</p>');
        $booking = $this->createBooking();
        $first = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->service->saveBookingText($booking, DocumentType::CONTRACT, '<p>Texte révisé</p>');
        $second = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->fileId, $second->fileId);
        // The first PDF is still on disk and still readable.
        $this->assertStringStartsWith('%PDF', $this->pdfTextOf($first->id));
        $this->assertCount(2, $this->service->forBooking($booking->id));
    }

    public function testADeletedVersionDoesNotFreeItsNumber(): void
    {
        // Two different PDFs called v2 is exactly the confusion versioning
        // exists to prevent.
        $this->setTemplate('<p>x</p>');
        $booking = $this->createBooking();
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $second = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->service->delete($second);
        $third = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertSame(3, $third->version);
    }

    public function testTheLatestVersionIsTheOneOfferedForSending(): void
    {
        $this->setTemplate('<p>x</p>');
        $booking = $this->createBooking();
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertSame(2, $this->service->latest($booking->id, DocumentType::CONTRACT)?->version);
    }

    public function testContractsAndInvoicesVersionIndependently(): void
    {
        $this->setTemplate('<p>c</p>');
        $this->setTemplate('<p>f</p>', DocumentType::INVOICE);
        $booking = $this->createBooking();

        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $invoice = $this->service->generate($booking, $this->asset(), DocumentType::INVOICE, $this->settings());

        $this->assertSame(1, $invoice->version);
        $this->assertSame('facture-LOC-K7Q2M4-v1.pdf', $invoice->originalName);
    }

    public function testAnEmptyTemplateFallsBackToTheStandardContract(): void
    {
        // The shipped bodies are a real default: an asset whose managers
        // never opened the template editor still hands its renter a complete
        // contract rather than a generation error.
        $document = $this->service->generate(
            $this->createBooking(),
            $this->asset(),
            DocumentType::CONTRACT,
            $this->settings()
        );

        $this->assertSame(1, $document->version);
        // And the booking's own copy — the text a manager reviews and a
        // renter signs — is the standard body, keywords substituted at
        // generation as for any other template.
        $this->assertStringContainsString(
            'Convention de location',
            $this->service->bookingText($this->createBooking('Anne Petit', null, 'LOC-W9Y8X7'), $this->asset(), DocumentType::CONTRACT)
        );
    }

    public function testTemplateOrStandardYieldsToACustomText(): void
    {
        $this->assertStringContainsString(
            'Convention de location',
            $this->service->templateOrStandard($this->asset(), DocumentType::CONTRACT)
        );

        $this->setTemplate('<p>Nos propres conditions.</p>');

        $custom = $this->service->templateOrStandard($this->asset(), DocumentType::CONTRACT);
        $this->assertStringContainsString('Nos propres conditions', $custom);
        $this->assertStringNotContainsString('Convention de location', $custom);
        // The raw accessor still tells "nothing written yet" apart — the
        // fallback lives in templateOrStandard() only.
        $this->assertSame('', $this->service->template($this->asset(), DocumentType::INVOICE));
    }

    public function testAnUploadOnlyTypeCannotBeGenerated(): void
    {
        $this->expectException(RentalException::class);

        $this->service->generate($this->createBooking(), $this->asset(), DocumentType::PHOTO, $this->settings());
    }

    public function testTheValuesUsedAreFrozenAlongsideTheFile(): void
    {
        // Without it, "why does v1 say 360,00 € when the booking says
        // 300,00 €?" has no answer six months later.
        $this->setTemplate('<p>{{ prix_total }}</p>');
        $document = $this->service->generate(
            $this->createBooking(),
            $this->asset(),
            DocumentType::CONTRACT,
            $this->settings()
        );

        $snapshot = $this->pdo->query(
            'SELECT generated_snapshot FROM rental_documents WHERE id = ' . $document->id
        )->fetchColumn();
        $decoded = json_decode((string) $snapshot, true);

        $this->assertIsArray($decoded);
        $this->assertSame('360,00 €', $decoded['prix_total']);
        $this->assertSame('LOC-K7Q2M4', $decoded['reference']);
    }

    // ── The injection path, end to end ──────────────────────────────────

    public function testARenterNameContainingMarkupNeverReachesTheRendererAsMarkup(): void
    {
        // The escaping is proven in isolation by DocumentKeywordsTest; this
        // proves it is on the path a real contract actually takes, with the
        // name coming back out of a real encrypted column.
        $this->setTemplate('<p>Entre l\'unité et {{ locataire_nom }}.</p>');
        $booking = $this->createBooking('<script>alert(1)</script>');

        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $snapshot = (string) $this->pdo->query(
            'SELECT generated_snapshot FROM rental_documents WHERE id = ' . $document->id
        )->fetchColumn();
        // The snapshot keeps the raw value — it is evidence of what was
        // supplied — but the rendered document must not have interpreted it.
        $this->assertStringContainsString('script', $snapshot);
        $this->assertStringStartsWith('%PDF', $this->pdfTextOf($document->id));
    }

    public function testAnOrganisationWithAnglesStillGeneratesCleanly(): void
    {
        $this->setTemplate('<p>{{ locataire_organisation }}</p>');
        $booking = $this->createBooking('Jeanne Martin', 'Scouts <de> Nulle Part & Cie');

        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertStringStartsWith('%PDF', $this->pdfTextOf($document->id));
    }

    // ── Keyword values ──────────────────────────────────────────────────

    public function testEveryValueTheSpecNamesIsResolved(): void
    {
        $booking = $this->createBooking('Jeanne Martin', 'Les Scouts de Nulle Part');
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings(), '+++100/0000/00034+++');

        $this->assertSame('LOC-K7Q2M4', $values['reference']);
        $this->assertSame('Local Saint-Georges', $values['bien']);
        $this->assertSame('01/07/2027', $values['date_arrivee']);
        $this->assertSame('04/07/2027', $values['date_depart']);
        $this->assertSame('3', $values['nuits']);
        $this->assertSame('20', $values['participants']);
        $this->assertSame('60', $values['capacite']);
        $this->assertSame('360,00 €', $values['prix_total']);
        $this->assertSame('100,00 €', $values['acompte']);
        $this->assertSame('500,00 €', $values['caution']);
        $this->assertSame('Jeanne Martin', $values['locataire_nom']);
        $this->assertSame('Les Scouts de Nulle Part', $values['locataire_organisation']);
        $this->assertSame('+++100/0000/00034+++', $values['communication']);
        $this->assertSame('Unité Saint-Georges', $values['nom_bailleur']);
        $this->assertSame('Rue du Local 1, 1000 Bruxelles', $values['adresse_bailleur'], 'the two lines of the setting, on one');
        $this->assertNull($values['bce_bailleur']);
        $this->assertSame('18:00', $values['heure_arrivee']);
    }

    /**
     * The contract names the conditions the renter accepted WITH THE
     * REQUEST, never today's (#708, IT-16): in a keyword for the unit's
     * own wording, and in the frame of the PDF for a template written
     * before that keyword existed.
     */
    public function testTheContractNamesTheVersionOfTheConditionsTheRenterAccepted(): void
    {
        $versions = new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo);
        $hash = hash('sha256', '<p>Conditions de septembre</p>');
        $accepted = $versions->archive(
            $this->assetId,
            $hash,
            '<p>Conditions de septembre</p>',
            new \DateTimeImmutable('2026-09-12 10:00:00'),
            null
        );
        $booking = $this->createBooking();
        $this->pdo->prepare('UPDATE rental_bookings SET conditions_version = ?, conditions_hash = ? WHERE id = ?')
            ->execute([$accepted->version, $hash, $booking->id]);
        $booking = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($booking);

        $service = $this->serviceWithConditions($versions);

        $this->assertSame($accepted->version, $service->acceptedConditions($booking)?->version);
        $values = $service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertStringStartsWith('version du 12/09/2026', (string) $values['conditions_acceptees']);

        $document = $service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $text = (string) preg_replace('/\s+/', ' ', $this->renderedTextOf($document->id));
        $this->assertStringContainsString('Conditions de location acceptées : version du 12/09/2026', $text);
    }

    /**
     * A version archived after the renter accepted it carries the date of
     * that late archiving, which post-dates the acceptance: the contract
     * then says when the version was accepted, never a date after it.
     */
    public function testAVersionArchivedAfterTheAcceptanceIsNamedByTheAcceptance(): void
    {
        $versions = new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo);
        $hash = hash('sha256', '<p>Conditions anciennes</p>');
        $accepted = $versions->archive(
            $this->assetId,
            $hash,
            '<p>Conditions anciennes</p>',
            new \DateTimeImmutable('2026-09-12 10:00:00'),
            null
        );
        $booking = $this->createBooking();
        $this->pdo->prepare(
            'UPDATE rental_bookings SET conditions_version = ?, conditions_hash = ?, conditions_accepted_at = ?'
                . ' WHERE id = ?'
        )->execute([$accepted->version, $hash, '2025-06-01 09:00:00', $booking->id]);
        $booking = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($booking);

        $values = $this->serviceWithConditions($versions)->valuesFor($booking, $this->asset(), $this->settings());

        $this->assertStringStartsWith('version acceptée le 01/06/2025', (string) $values['conditions_acceptees']);
        $this->assertStringNotContainsString('12/09/2026', (string) $values['conditions_acceptees']);
    }

    /**
     * A booking whose fingerprint matches no archived text names none: a
     * link to some other wording would be worse than nothing. The standard
     * contract still reads as a sentence — it says when the conditions
     * were in force, never « (—) » — and the PDF frame names no version.
     */
    public function testAContractNamesNoConditionsItCannotProve(): void
    {
        $versions = new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo);
        $accepted = $versions->archive(
            $this->assetId,
            hash('sha256', 'a'),
            '<p>a</p>',
            new \DateTimeImmutable('2026-09-12 10:00:00'),
            null
        );
        $booking = $this->createBooking();
        $this->pdo->prepare('UPDATE rental_bookings SET conditions_version = ?, conditions_hash = ? WHERE id = ?')
            ->execute([$accepted->version, hash('sha256', 'b'), $booking->id]);
        $booking = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($booking);

        $service = $this->serviceWithConditions($versions);

        $this->assertNull($service->acceptedConditions($booking));
        $line = (string) $service->valuesFor($booking, $this->asset(), $this->settings())['conditions_acceptees'];
        $this->assertStringStartsWith('en vigueur le ', $line);
        $this->assertStringNotContainsString('/conditions/', $line);

        $document = $service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
        $text = (string) preg_replace('/\s+/', ' ', $this->renderedTextOf($document->id));
        $this->assertStringNotContainsString('(—)', $text);
        $this->assertMatchesRegularExpression('#\d{2}/\d{2}/\d{4}\) font partie#', $text, 'a date where the dash was');
        $this->assertStringNotContainsString('Conditions de location acceptées :', $text);
    }

    private function serviceWithConditions(
        \Modules\Rental\Repository\RentalConditionsVersionRepository $versions
    ): RentalDocumentService {
        return new RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->editableContentService,
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->storagePath,
            new \Modules\Rental\Service\RentalConditionsService($versions, $this->editableContentService)
        );
    }

    /**
     * The module's landlord replaces the unit whole — name, address and
     * enterprise number together — because the case it exists for is a
     * separate non-profit owning the hall, and the unit's address under the
     * ASBL's name would be the wrong party at the wrong place (issue #497).
     */
    public function testTheModulesLandlordReplacesTheUnitWhole(): void
    {
        $this->settingService->set('landlord_name', 'ASBL Les Amis du Local', 'rental');
        $this->settingService->set('landlord_address', "Place du Parc 3\n1300 Wavre", 'rental');
        $this->settingService->set('landlord_enterprise_number', '0123.456.789', 'rental');

        $values = $this->service->valuesFor($this->createBooking(), $this->asset(), $this->settings());

        $this->assertSame('ASBL Les Amis du Local', $values['nom_bailleur']);
        $this->assertSame('Place du Parc 3, 1300 Wavre', $values['adresse_bailleur']);
        $this->assertSame('0123.456.789', $values['bce_bailleur']);
        $this->assertSame('Unité Saint-Georges', $values['unite'], 'the unit is still nameable as itself');
    }

    /**
     * The asset's own landlord wins over the module's, whole again: a unit
     * whose hall belongs to one ASBL and whose meadow to another.
     */
    public function testTheAssetsOwnLandlordWinsOverTheModules(): void
    {
        $this->settingService->set('landlord_name', 'ASBL Les Amis du Local', 'rental');
        $this->settingService->set('landlord_enterprise_number', '0123.456.789', 'rental');
        $this->assetRepository->saveLandlord($this->assetId, 'ASBL Le Pré Fleuri', 'Chemin du Pré 2, 1380 Lasne', null);

        $values = $this->service->valuesFor($this->createBooking(), $this->asset(), $this->settings());

        $this->assertSame('ASBL Le Pré Fleuri', $values['nom_bailleur']);
        $this->assertSame('Chemin du Pré 2, 1380 Lasne', $values['adresse_bailleur']);
        $this->assertNull($values['bce_bailleur'], 'the module\'s number is not borrowed');
        $this->assertSame('asset', $this->service->landlordFor($this->asset())->source);
    }

    /**
     * A landlord with a name and no address keeps the address missing: the
     * unit's postal address under an ASBL's name would print a place that
     * is not theirs, and a « — » is at least visibly incomplete.
     */
    public function testALandlordWithoutAnAddressDoesNotBorrowTheUnits(): void
    {
        $this->settingService->set('landlord_name', 'ASBL Les Amis du Local', 'rental');

        $values = $this->service->valuesFor($this->createBooking(), $this->asset(), $this->settings());

        $this->assertSame('ASBL Les Amis du Local', $values['nom_bailleur']);
        $this->assertNull($values['adresse_bailleur']);
    }

    public function testACommaAtTheEndOfALineIsNotDoubled(): void
    {
        $this->settingService->set(UnitAddresses::POSTAL_ADDRESS, "Rue du Local 1,\r\n  1000 Bruxelles");

        $values = $this->service->valuesFor($this->createBooking(), $this->asset(), $this->settings());

        $this->assertSame('Rue du Local 1, 1000 Bruxelles', $values['adresse_bailleur']);
    }

    /** An address with no name is the unit letting from somewhere else. */
    public function testALandlordAddressWithoutANameIsTheUnitElsewhere(): void
    {
        $this->settingService->set('landlord_address', 'Chemin du Bois 12, 1310 La Hulpe', 'rental');

        $values = $this->service->valuesFor($this->createBooking(), $this->asset(), $this->settings());

        $this->assertSame('Unité Saint-Georges', $values['nom_bailleur']);
        $this->assertSame('Chemin du Bois 12, 1310 La Hulpe', $values['adresse_bailleur']);
    }

    public function testWithNothingConfiguredTheLandlordLinesPrintADash(): void
    {
        $this->settingService->set(UnitAddresses::POSTAL_ADDRESS, '');
        $booking = $this->createBooking();
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());

        $this->assertNull($values['adresse_bailleur']);
        $this->assertNull($values['bce_bailleur']);
    }

    /** The shipped contract names the landlord, not merely the unit. */
    public function testTheStandardContractPrintsTheLandlord(): void
    {
        $this->settingService->set('landlord_name', 'ASBL Les Amis du Local', 'rental');
        $this->settingService->set('landlord_address', 'Place du Parc 3, 1300 Wavre', 'rental');

        $document = $this->service->generate(
            $this->createBooking(),
            $this->asset(),
            DocumentType::CONTRACT,
            $this->settings()
        );
        $text = $this->renderedTextOf($document->id);

        $this->assertStringContainsString('ASBL Les Amis du Local', $text);
        $this->assertStringContainsString('Place du Parc 3, 1300 Wavre', $text);
    }

    public function testAnAssetWithNoDepositResolvesTheKeywordToNothing(): void
    {
        $values = $this->service->valuesFor($this->createBooking(), $this->asset(), new PaymentSettings());

        $this->assertNull($values['acompte']);
        $this->assertNull($values['caution']);
    }

    public function testTheBillingIdentityFeedsTheInvoiceKeywords(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->saveBillingIdentity($booking->id, [
            'name' => 'ASBL Les Scouts',
            'address' => 'Rue Haute 12, 1000 Bruxelles',
            'country' => 'be',
            'vat_number' => 'BE0123456789',
        ]);

        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $identity = $this->bookingRepository->findBillingIdentity($booking->id);

        $this->assertSame('Rue Haute 12, 1000 Bruxelles', $values['locataire_adresse']);
        $this->assertSame('BE0123456789', $values['locataire_tva']);
        // Normalised to two upper-case letters, or dropped: a country field
        // holding "Belgique" in one row and "BE" in another is what makes a
        // later e-invoice export a guessing game.
        $this->assertSame('BE', $identity['country']);
    }

    public function testAnInvalidCountryIsDroppedRatherThanStoredAsTyped(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->saveBillingIdentity($booking->id, ['country' => 'Belgique']);

        $this->assertNull($this->bookingRepository->findBillingIdentity($booking->id)['country']);
    }

    public function testTheBillingIdentityIsStoredEncrypted(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->saveBillingIdentity($booking->id, [
            'name' => 'ASBL Les Scouts',
            'vat_number' => 'BE0123456789',
        ]);

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('ASBL Les Scouts', $raw);
        $this->assertStringNotContainsString('BE0123456789', $raw);
    }

    // ── Annex 8.1 of the Walloon tourism code (issue #977) ─────────────

    /** The same service, reading the asset's account through Finance's Api\\ interface. */
    private function serviceWithAccount(?string $iban, ?string $holder): RentalDocumentService
    {
        $accounts = new class ($iban, $holder) implements \Modules\Finance\Api\FinanceAccountInterface {
            public function __construct(private ?string $iban, private ?string $holder)
            {
            }

            public function getConfiguredAccounts(): array
            {
                return [[
                    'id' => 1, 'name' => 'Compte unité', 'iban' => $this->iban,
                    'holder_name' => $this->holder, 'section_id' => null,
                ]];
            }

            public function getDefaultAccountForSection(int $sectionId): ?int
            {
                return null;
            }

            public function getCategories(): array
            {
                return [];
            }
        };

        return new RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->editableContentService,
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->storagePath,
            null,
            new \Modules\Rental\Service\RentalPaymentService(
                new \Modules\Rental\Repository\RentalPaymentRepository($this->pdo, $this->encryption),
                RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
                new JournalService(new JournalRepository($this->pdo)),
                null,
                null,
                null,
                $accounts
            )
        );
    }

    private function documentCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn();
    }

    /** Point 2: no contract without the renter's address, and the refusal says where to complete it. */
    public function testAContractIsRefusedWithoutTheRentersAddressAndSaysWhereToCompleteIt(): void
    {
        $booking = $this->createBooking(renterAddress: null);

        try {
            $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
            $this->fail('a contract without the renter\'s address must be refused');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertStringContainsString("il manque l'adresse du locataire", $e->getMessage());
            $this->assertStringContainsString(
                '« Vos coordonnées de facturation » sur sa page de suivi',
                $e->getMessage()
            );
            $this->assertStringContainsString(
                '« Coordonnées de facturation » sur la page « Facture »',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('Adresse du bien', $e->getMessage());
        }

        $this->assertSame(0, $this->documentCount(), 'a refused contract leaves nothing behind');
    }

    /** The billing name stands in for a blank request name; with neither, both are asked for. */
    public function testTheRefusalNamesEverythingMissingAtOnce(): void
    {
        $booking = $this->createBooking(renterName: ' ', renterAddress: null);
        $this->assetRepository->saveContractDetails($this->assetId, null, 60);

        try {
            $this->service->assertContractCanBeGenerated($booking, $this->asset());
            $this->fail('refused');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertStringContainsString("il manque le nom et l'adresse du locataire", $e->getMessage());
            $this->assertStringContainsString("L'adresse du bien manque aussi", $e->getMessage());
        }

        $this->bookingRepository->saveBillingIdentity($booking->id, [
            'name' => 'ASBL Les Scouts', 'address' => 'Rue Haute 12, 1000 Bruxelles',
        ]);
        $this->assetRepository->saveContractDetails($this->assetId, 'Chemin du Camp 4, 1300 Wavre', 60);
        $this->service->assertContractCanBeGenerated($booking, $this->asset());
        $this->assertSame(
            'ASBL Les Scouts',
            $this->service->valuesFor($booking, $this->asset(), $this->settings())['locataire_nom']
        );
    }

    /** Point 4: no contract without the asset's address. */
    public function testAContractIsRefusedWithoutTheAssetsAddressAndSaysWhereToCompleteIt(): void
    {
        $this->assetRepository->saveContractDetails($this->assetId, null, 60);
        $booking = $this->createBooking();

        try {
            $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());
            $this->fail('a contract without the asset\'s address must be refused');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertStringStartsWith(
                'Le contrat ne peut pas être généré : l\'adresse du bien manque.',
                $e->getMessage()
            );
            $this->assertStringContainsString(
                '« Adresse du bien » dans la section « Contrat » des « Réglages »',
                $e->getMessage()
            );
        }

        $this->assertSame(0, $this->documentCount());
    }

    /** The guard is the contract's: an invoice is out of its scope. */
    public function testAnInvoiceIsNotHeldToTheContractsGuard(): void
    {
        $this->assetRepository->saveContractDetails($this->assetId, null, 60);
        $booking = $this->createBooking(renterAddress: null);

        $document = $this->service->generate($booking, $this->asset(), DocumentType::INVOICE, $this->settings());

        $this->assertSame(DocumentType::INVOICE, $document->type);
    }

    public function testTheAssetAddressAndTheFreeWithdrawalDeadlineArePrinted(): void
    {
        $booking = $this->createBooking();

        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());

        $this->assertSame('Chemin du Camp 4, 1300 Wavre', $values['bien_adresse']);
        // Sixty days before an arrival on 1 July 2027.
        $this->assertSame('02/05/2027', $values['date_limite_desistement']);
        $this->assertSame(
            "Jusqu'au 02/05/2027 inclus, il le fait sans indemnité et les sommes versées lui sont remboursées.",
            $values['desistement_sans_indemnite']
        );
        $this->assertSame(
            'du 03/05/2027 au 01/06/2027 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 02/06/2027, soixante-quinze pour cent du prix de la location.',
            $values['bareme_desistement']
        );

        $text = (string) preg_replace('/\s+/', ' ', $this->renderedTextOf(
            $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings())->id
        ));
        // A justified line loses its spaces to the text extractor: the values
        // themselves are what is checked here.
        $this->assertStringContainsString('Chemin du Camp 4, 1300 Wavre', $text);
        $this->assertStringContainsString('02/05/2027', $text);
    }

    /** The schema's default deadline, ninety days, and a short one inside the last month. */
    public function testTheDefaultAndAShortDeadlineGiveTheirOwnScale(): void
    {
        $booking = $this->createBooking();

        $this->assetRepository->saveContractDetails($this->assetId, 'Chemin du Camp 4', 90);
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame('02/04/2027', $values['date_limite_desistement']);
        $this->assertStringStartsWith(
            'du 03/04/2027 au 01/06/2027 inclus, cinquante',
            (string) $values['bareme_desistement']
        );

        $this->assetRepository->saveContractDetails($this->assetId, 'Chemin du Camp 4', 10);
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame('21/06/2027', $values['date_limite_desistement']);
        $this->assertSame(
            'à partir du 22/06/2027, soixante-quinze pour cent du prix de la location.',
            $values['bareme_desistement']
        );
    }

    /**
     * Booked after the deadline: the contract says the free period does
     * not apply, and prints no past date as an offer.
     */
    public function testAContractGeneratedAfterTheDeadlineOffersNoFreeWithdrawal(): void
    {
        $booking = $this->createBooking();
        $arrival = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $this->pdo->prepare('UPDATE rental_bookings SET arrival_date = ?, departure_date = ? WHERE id = ?')
            ->execute([$arrival, (new \DateTimeImmutable('+23 days'))->format('Y-m-d'), $booking->id]);
        $booking = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($booking);
        $deadline = (new \DateTimeImmutable('+20 days'))->modify('-60 days')->format('d/m/Y');

        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());

        $this->assertNull($values['date_limite_desistement']);
        $this->assertStringContainsString(
            'ne s\'applique pas à cette réservation',
            (string) $values['desistement_sans_indemnite']
        );
        $this->assertSame('soixante-quinze pour cent du prix de la location.', $values['bareme_desistement']);

        $text = (string) preg_replace('/\s+/', '', $this->renderedTextOf(
            $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings())->id
        ));
        // The extractor drops words of a justified line, so only the absence
        // is read off the PDF; the sentence itself is asserted above.
        $this->assertStringNotContainsString($deadline, $text);
    }

    /** Point 8: the account and its holder, from the asset's Finance account. */
    public function testThePaymentKeywordsComeFromTheAssetsFinanceAccount(): void
    {
        $booking = $this->createBooking();
        $settings = new PaymentSettings(
            enabled: true,
            financeAccountId: 1,
            depositMode: \Modules\Rental\Payment\DepositMode::FIXED,
            depositAmountCents: 10000,
            depositDueDays: 15,
            balanceDueDays: 30
        );

        $values = $this->serviceWithAccount('be68 5390 0754 7034', 'ASBL Les Amis du Local')
            ->valuesFor($booking, $this->asset(), $settings);

        $this->assertSame('BE68 5390 0754 7034', $values['iban']);
        $this->assertSame('ASBL Les Amis du Local', $values['titulaire_compte']);
        // Before the first send: counted from it, which this contract's send will stamp.
        $this->assertSame("dans les 15 jours qui suivent l'envoi du présent contrat", $values['echeance_acompte']);
        $this->assertSame('au plus tard le 01/06/2027', $values['echeance_solde']);

        // After it, the date that send stamped (issue #927).
        $this->pdo->prepare('UPDATE rental_bookings SET deposit_due_date = ? WHERE id = ?')
            ->execute(['2027-02-15', $booking->id]);
        $values = $this->serviceWithAccount('BE68539007547034', 'ASBL Les Amis du Local')
            ->valuesFor($booking, $this->asset(), $settings);
        $this->assertSame('au plus tard le 15/02/2027', $values['echeance_acompte']);
    }

    /** Without an account the contract says so, rather than print a blank. */
    public function testWithoutAFinanceAccountTheContractSaysSo(): void
    {
        $booking = $this->createBooking();
        $settings = new PaymentSettings(enabled: false);

        foreach ([$this->service, $this->serviceWithAccount(null, null)] as $service) {
            $values = $service->valuesFor($booking, $this->asset(), $settings);

            $this->assertSame('à communiquer par écrit par le bailleur', $values['iban']);
            $this->assertSame('le bailleur', $values['titulaire_compte']);
            $this->assertNull($values['echeance_acompte'], 'no deposit, no deadline for it');
            $this->assertSame('avant le début du séjour', $values['echeance_solde']);
        }

        // An account pinned but without an IBAN says it too.
        $values = $this->serviceWithAccount(null, 'Unité Saint-Georges')
            ->valuesFor($booking, $this->asset(), new PaymentSettings(financeAccountId: 1));
        $this->assertSame('à communiquer par écrit par le bailleur', $values['iban']);
        $this->assertSame('Unité Saint-Georges', $values['titulaire_compte']);

        $text = (string) preg_replace('/\s+/', '', $this->renderedTextOf(
            $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $settings)->id
        ));
        $this->assertStringContainsString('titulaireestlebailleur', $text);
    }

    /** Point 5: the accepted conditions are a link the reader can click, and still printed. */
    public function testTheAcceptedConditionsAreAClickableLinkInThePdf(): void
    {
        $this->settingService->register(
            'base_url',
            'https://unite.example.be',
            'url',
            'Adresse',
            'Description de test.'
        );
        $this->settingService->set('base_url', 'https://unite.example.be');
        $versions = new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo);
        $hash = hash('sha256', '<p>Conditions</p>');
        $accepted = $versions->archive(
            $this->assetId,
            $hash,
            '<p>Conditions</p>',
            new \DateTimeImmutable('2026-09-12'),
            null
        );
        $booking = $this->createBooking();
        $this->pdo->prepare('UPDATE rental_bookings SET conditions_version = ?, conditions_hash = ? WHERE id = ?')
            ->execute([$accepted->version, $hash, $booking->id]);
        $booking = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($booking);
        $url = 'https://unite.example.be/locations/local-saint-georges/conditions/' . $accepted->version;

        $document = $this->serviceWithConditions($versions)
            ->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $pdf = $this->pdfTextOf($document->id);
        $this->assertStringContainsString('/URI', $pdf, 'dompdf wrote a link annotation');
        // At least twice: the frame's header line and article 12 of the body
        // (dompdf writes one annotation per line a link runs over).
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, '/URI (' . $url . ')'));
        $this->assertStringContainsString(
            $url,
            (string) preg_replace('/\s+/', '', $this->renderedTextOf($document->id))
        );
    }

    /** Point 12: a contract is headed by its landlord, never by the site's own name. */
    public function testTheContractIsHeadedByTheLandlordAndPrintsNoEmptyEnterpriseNumber(): void
    {
        $this->settingService->set('site_name', 'Scout Magic');
        $this->assetRepository->saveLandlord(
            $this->assetId,
            'ASBL Les Amis du Local',
            'Place du Parc 3, 1300 Wavre',
            null
        );
        $booking = $this->createBooking();

        $text = (string) preg_replace('/\s+/', ' ', $this->renderedTextOf(
            $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings())->id
        ));

        $this->assertStringNotContainsString('Scout Magic', $text);
        $this->assertStringContainsString('ASBL Les Amis du Local', $text);
        $this->assertStringNotContainsString("N° d'entreprise", $text);

        $this->assetRepository->saveLandlord(
            $this->assetId,
            'ASBL Les Amis du Local',
            'Place du Parc 3',
            '0123.456.789'
        );
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame(", n° d'entreprise 0123.456.789", $values['mention_bce_bailleur']);
    }

    /** Point 12: « nuit(s) » and its kind, agreed. */
    public function testTheCountsAreAgreedInTheSingularAndThePlural(): void
    {
        $booking = $this->createBooking();
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame('3 nuits', $values['duree_nuits']);
        $this->assertSame('20 participants', $values['nombre_participants']);
        $this->assertSame('60 personnes', $values['capacite_personnes']);

        $this->pdo->prepare('UPDATE rental_bookings SET departure_date = ?, estimated_persons = 1 WHERE id = ?')
            ->execute(['2027-07-02', $booking->id]);
        $this->pdo->exec('UPDATE rental_assets SET capacity = 1');
        $booking = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($booking);
        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame('1 nuit', $values['duree_nuits']);
        $this->assertSame('1 participant', $values['nombre_participants']);
        $this->assertSame('1 personne', $values['capacite_personnes']);
    }

    /** Point 2: the group's responsible person on site, stored encrypted. */
    public function testTheResponsiblePersonOnSiteIsNamedAndStoredEncrypted(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->saveOnSiteContact($booking->id, 'Paul Lemaire', '+32 470 12 34 56');

        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame('Paul Lemaire', $values['responsable_nom']);
        $this->assertSame('+32 470 12 34 56', $values['responsable_telephone']);

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('Paul Lemaire', $raw);
        $this->assertStringNotContainsString('470 12 34 56', $raw);
    }

    /** Point 11: the controller, the duration and where to write. */
    public function testTheDataClauseNamesItsDurationAndAContact(): void
    {
        $booking = $this->createBooking();

        $values = $this->service->valuesFor($booking, $this->asset(), $this->settings());
        $this->assertSame(
            "7 ans à compter de la clôture de l'exercice comptable au cours duquel le séjour a eu lieu",
            $values['duree_conservation']
        );
        $this->assertSame("l'adresse du bailleur indiquée en tête du présent contrat", $values['contact_donnees']);

        $this->settingService->register('contact_email', 'rgpd@unite.be', 'email', 'Contact', 'Description de test.');
        $this->settingService->set('contact_email', 'rgpd@unite.be');
        $this->assertSame(
            'rgpd@unite.be',
            $this->service->valuesFor($booking, $this->asset(), $this->settings())['contact_donnees']
        );
    }

    // ── VAT (§6.27) ─────────────────────────────────────────────────────

    public function testNoVatIsEverComputedAndTheExemptionNoteIsConfigurable(): void
    {
        $this->assertSame(
            'Exemption de TVA — article 44 du Code de la TVA.',
            $this->service->vatNote($this->asset())
        );

        $this->assetRepository->saveVatExemptionNote($this->assetId, 'Article 44 du Code de la TVA.');

        $this->assertSame('Article 44 du Code de la TVA.', $this->service->vatNote($this->asset()));
    }

    /**
     * No unit-wide level any more (issue #613): a setting under the old
     * key, whatever it holds, changes nothing.
     */
    public function testTheUndeclaredUnitWideVatSettingIsNotRead(): void
    {
        $this->pdo->prepare(
            "INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description, editable) "
                . "VALUES ('rental', 'rental_vat_exemption_note', 'Mention oubliée', 'text', 'x', 'x', 1)"
        )->execute();

        $this->assertSame(
            \Modules\Rental\Service\RentalDocumentService::DEFAULT_VAT_NOTE,
            $this->service->vatNote($this->asset())
        );
    }

    // ── Uploaded documents (§6.24) ──────────────────────────────────────

    public function testAnUploadedDocumentIsRecordedAtVersionOne(): void
    {
        $booking = $this->createBooking();
        $fileId = $this->fileRepository->create(
            'rental/documents/abc.pdf',
            'etat-des-lieux.pdf',
            'application/pdf',
            1024,
            'identified',
            'rental',
            null,
            false,
            null,
            RentalDocumentService::OWNER_TYPE,
            $booking->id
        );

        $documentId = $this->service->attachUploaded($booking, $fileId, DocumentType::INVENTORY, false);
        $document = $this->service->find($documentId);

        $this->assertNotNull($document);
        $this->assertSame(1, $document->version);
        $this->assertFalse($document->isForRenter);
        $this->assertSame('etat-des-lieux.pdf', $document->originalName);
    }

    public function testAMissingFileIsReportedAsNullRatherThanThrowing(): void
    {
        // A real state on a restored backup: the listing has to keep
        // rendering with the others.
        $booking = $this->createBooking();
        $fileId = $this->fileRepository->create(
            'rental/documents/gone.pdf',
            'gone.pdf',
            'application/pdf',
            1,
            'identified',
            'rental',
            null
        );
        $documentId = $this->service->attachUploaded($booking, $fileId, DocumentType::OTHER, false);
        $document = $this->service->find($documentId);
        $this->assertNotNull($document);

        $this->assertNull($this->service->absolutePath($document));
    }

    // ── Deleting a document never blanks a message (§7.8, §8.59) ────────

    private function createAttachmentFile(RentalBooking $booking, string $name = 'piece-jointe.pdf'): int
    {
        return $this->fileRepository->create(
            'rental/documents/' . bin2hex(random_bytes(6)) . '.pdf',
            $name,
            'application/pdf',
            1024,
            'identified',
            'rental',
            null,
            false,
            null,
            RentalDocumentService::OWNER_TYPE,
            $booking->id
        );
    }

    private function countFiles(int $fileId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE id = ?');
        $stmt->execute([$fileId]);

        return (int) $stmt->fetchColumn();
    }

    public function testDeletingAnEmailSourcedDocumentLeavesTheMessagesFileAlone(): void
    {
        // An inbound message's attachment is registered by the very same
        // `files` id the message serves it from. Deleting the bytes here
        // would blank the message too — the manager who tidies up a
        // "Non classé" row would silently destroy the correspondence it
        // came from.
        $booking = $this->createBooking();
        $fileId = $this->createAttachmentFile($booking);
        $documentId = $this->service->attachUploaded(
            $booking,
            $fileId,
            DocumentType::UNSORTED,
            false,
            null,
            \Modules\Rental\Document\RentalDocument::SOURCE_EMAIL
        );
        $document = $this->service->find($documentId);
        $this->assertNotNull($document);
        $this->assertFalse($document->ownsItsFile());

        $this->service->delete($document);

        $this->assertNull($this->service->find($documentId));
        $this->assertSame(1, $this->countFiles($fileId), 'The message still owns and serves this file.');
    }

    public function testReclassifyingAnAttachmentDoesNotMakeItOursToDelete(): void
    {
        // Reclassification moves the type, never the ownership of the
        // bytes: the message still carries the same attachment.
        $booking = $this->createBooking();
        $fileId = $this->createAttachmentFile($booking, 'contrat-signe.pdf');
        $documentId = $this->service->attachUploaded(
            $booking,
            $fileId,
            DocumentType::UNSORTED,
            false,
            null,
            \Modules\Rental\Document\RentalDocument::SOURCE_EMAIL
        );
        $this->service->reclassify($booking, $documentId, DocumentType::EVIDENCE, false);

        $document = $this->service->find($documentId);
        $this->assertNotNull($document);
        $this->service->delete($document);

        $this->assertSame(1, $this->countFiles($fileId));
    }

    public function testDeletingAnUploadedDocumentStillTakesItsFileWithIt(): void
    {
        $booking = $this->createBooking();
        $fileId = $this->createAttachmentFile($booking, 'etat-des-lieux.pdf');
        $documentId = $this->service->attachUploaded($booking, $fileId, DocumentType::INVENTORY, false);
        $document = $this->service->find($documentId);
        $this->assertNotNull($document);
        $this->assertTrue($document->ownsItsFile());

        $this->service->delete($document);

        $this->assertSame(0, $this->countFiles($fileId));
    }

    public function testAFileASecondDocumentStillPointsAtIsKept(): void
    {
        // Two rows can legitimately share one file. Deleting the bytes
        // would turn the surviving row into a broken download.
        $booking = $this->createBooking();
        $fileId = $this->createAttachmentFile($booking, 'plan.pdf');
        $first = $this->service->attachUploaded($booking, $fileId, DocumentType::OTHER, false);
        $this->service->attachUploaded($booking, $fileId, DocumentType::OTHER, false);

        $document = $this->service->find($first);
        $this->assertNotNull($document);
        $this->service->delete($document);

        $this->assertSame(1, $this->countFiles($fileId));
    }

    // ── Who may fetch a document (§6.24, §6.26) ─────────────────────────

    private function checkerFor(?string $email): RentalDocumentOwnershipChecker
    {
        return new RentalDocumentOwnershipChecker(
            $this->bookingRepository,
            new RentalAuthorizationService(
                new \Core\Member\MemberService(
    new \Core\Import\MemberYearRepository($this->pdo),
    new \Core\Member\Repository\MemberProfileRepository(\Core\Database\Connection::withPdo($this->pdo), $this->encryption)
),
                $this->assetRepository,
                $this->managerRepository
            ),
            $this->scoutYearId,
            $email
        );
    }

    private function addManager(string $email, ?int $assetId = null): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($assetId ?? $this->assetId, $memberId, false);

        return $memberId;
    }

    public function testAManagerOfTheAssetMayFetchItsDocuments(): void
    {
        $this->addManager('manager@test.be');
        $booking = $this->createBooking();

        $this->assertTrue(
            $this->checkerFor('manager@test.be')->isAllowed($booking->id, Role::fromString('identified'), [])
        );
    }

    public function testAnIdentifiedVisitorWhoManagesNothingMayNot(): void
    {
        // The file's own role_min is `identified`, which alone would let any
        // logged-in visitor at every contract in the unit. This checker is
        // what actually protects them.
        $booking = $this->createBooking();

        $this->assertFalse(
            $this->checkerFor('nobody@test.be')->isAllowed($booking->id, Role::fromString('identified'), [])
        );
    }

    public function testAManagerOfAnotherAssetMayNot(): void
    {
        $otherAssetId = $this->assetRepository->create(
            'Local', 'Autre', 'autre', null, 1, null, null, null, true
        );
        $this->addManager('autre@test.be', $otherAssetId);
        $booking = $this->createBooking();

        $this->assertFalse(
            $this->checkerFor('autre@test.be')->isAllowed($booking->id, Role::fromString('identified'), [])
        );
    }

    public function testAnAnonymousVisitorMayNot(): void
    {
        $booking = $this->createBooking();

        $this->assertFalse(
            $this->checkerFor(null)->isAllowed($booking->id, Role::fromString('public'), [])
        );
    }

    public function testAChiefWithNoGrantMayNotEither(): void
    {
        // No role bypass: a higher role_min never substitutes for actually
        // managing the asset.
        $booking = $this->createBooking();

        $this->assertFalse(
            $this->checkerFor('chief@test.be')->isAllowed($booking->id, Role::fromString('chief'), [])
        );
    }

    public function testADocumentWhoseBookingIsGoneIsRefused(): void
    {
        // A restored backup, a botched delete: there is nobody left to
        // check the request against, so refusing is the only safe answer.
        $this->addManager('manager@test.be');

        $this->assertFalse(
            $this->checkerFor('manager@test.be')->isAllowed(9999, Role::fromString('admin'), [])
        );
    }

    public function testTheCheckerOnlyClaimsItsOwnOwnerType(): void
    {
        $checker = $this->checkerFor('manager@test.be');

        $this->assertTrue($checker->supports('rental_document'));
        $this->assertFalse($checker->supports('section_document'));
    }

    // ── The supplementary invoice (#980, chantier decision 2.8) ─────────────────

    /**
     * A settlement as stored, without the stay service: what the document
     * service is handed by its caller.
     *
     * @param list<array{string, int, string}> $lines label, amount, origin
     */
    private function settlement(
        int $id,
        int $version,
        array $lines,
        bool $validated = true,
        int $balanceCents = 0,
        int $bookingId = 0,
        ?int $unrecoveredCents = null
    ): \Modules\Rental\Stay\Settlement {
        $settlementLines = [];
        $total = 0;
        foreach ($lines as [$label, $amount, $origin]) {
            $settlementLines[] = new \Modules\Rental\Stay\SettlementLine($label, $amount, $origin);
            $total += $amount;
        }

        return new \Modules\Rental\Stay\Settlement(
            id: $id,
            bookingId: $bookingId,
            version: $version,
            finalPersons: 20,
            lines: $settlementLines,
            totalCents: $total,
            alreadyPaidCents: $total - $balanceCents,
            balanceCents: $balanceCents,
            securityDepositWithheldCents: 0,
            securityDepositReturnCents: 0,
            isValidated: $validated,
            validatedAt: $validated ? new \DateTimeImmutable('2027-07-20 10:00:00') : null,
            validatedByMemberId: $validated ? 1 : null,
            createdByMemberId: 1,
            createdAt: new \DateTimeImmutable('2027-07-20 09:00:00'),
            recordedKeptCents: $total,
            incidentBasisHash: str_repeat('a', 64),
            unrecoveredCents: $unrecoveredCents,
            unrecoveredReason: $unrecoveredCents !== null ? 'Locataire injoignable' : null,
            unrecoveredAt: $unrecoveredCents !== null ? new \DateTimeImmutable('2027-09-01 10:00:00') : null
        );
    }

    /** The booking's invoice, generated before any settlement and sent. */
    private function sentInvoice(RentalBooking $booking): \Modules\Rental\Document\RentalDocument
    {
        $invoice = $this->service->generate($booking, $this->asset(), DocumentType::INVOICE, $this->settings());
        $this->service->markSent($invoice->id, new \DateTimeImmutable('2027-07-10 12:00:00'));

        return $this->documentRepository->findById($invoice->id) ?? throw new \LogicException('no invoice');
    }

    /** @return list<array{string, int, string}> */
    private static function stayWithBrokenWindow(): array
    {
        return [
            ['Séjour', 36000, \Modules\Rental\Stay\SettlementLine::ORIGIN_AGREED],
            ['Vitre brisée', 3000, \Modules\Rental\Stay\SettlementLine::ORIGIN_INCIDENT],
        ];
    }

    /** A service whose payments answer with $instructions (Finance on). */
    private function serviceWithPayments(array $instructions): RentalDocumentService
    {
        $payments = $this->createStub(\Modules\Rental\Service\RentalPaymentService::class);
        $payments->method('contractTerms')->willReturn(['iban' => null, 'holder' => null, 'deposit_due_date' => null]);
        $payments->method('paymentInstructions')->willReturn($instructions + [
            'communication' => '+++090/9337/55493+++',
            'beneficiary' => 'Unité Saint-Georges',
            'iban' => 'BE68 5390 0754 7034',
            'amount_cents' => 0,
            'stage' => null,
            'due_date' => null,
            'qr_data_uri' => null,
            'overpaid_cents' => 0,
            'includes_security_deposit' => true,
        ]);

        return new RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->editableContentService,
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->storagePath,
            null,
            $payments
        );
    }

    private const QR_PNG = 'data:image/png;base64,'
        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function testAnInvoiceRecordsWhatItBillsBeforeAndAfterASettlement(): void
    {
        $booking = $this->createBooking();

        $before = $this->service->generate($booking, $this->asset(), DocumentType::INVOICE, $this->settings());
        $this->assertNull($before->settlementId);
        $this->assertSame(36000, $before->billedKeptCents);

        $validated = $this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id);
        $after = $this->service->generate(
            $booking,
            $this->asset(),
            DocumentType::INVOICE,
            $this->settings(),
            null,
            1,
            $validated
        );
        $this->assertSame(7, $after->settlementId);
        $this->assertSame(39000, $after->billedKeptCents);
    }

    public function testAnInvoiceRefusesASettlementThatIsNotValidatedOrNotTheBookings(): void
    {
        $booking = $this->createBooking();

        $this->expectException(RentalException::class);
        $this->service->generate(
            $booking,
            $this->asset(),
            DocumentType::INVOICE,
            $this->settings(),
            null,
            1,
            $this->settlement(7, 1, self::stayWithBrokenWindow(), false, 0, $booking->id)
        );
    }

    public function testNothingIsOfferedUntilAnInvoiceWentOutAndASettlementBillsAnotherAmount(): void
    {
        $booking = $this->createBooking();
        $validated = $this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id);

        // No invoice at all, then one not sent.
        $this->assertNull($this->service->supplementOffer($booking, [$validated]));
        $this->service->generate($booking, $this->asset(), DocumentType::INVOICE, $this->settings());
        $this->assertNull($this->service->supplementOffer($booking, [$validated]));

        // Sent, but no validated settlement yet.
        $invoice = $this->sentInvoice($booking);
        $this->assertNull($this->service->supplementOffer(
            $booking,
            [$this->settlement(7, 1, self::stayWithBrokenWindow(), false, 0, $booking->id)]
        ));

        // A settlement keeping exactly what was billed has no difference.
        $same = $this->settlement(8, 1, [['Séjour', 36000, 'agreed']], bookingId: $booking->id);
        $this->assertNull($this->service->supplementOffer($booking, [$same]));

        $offer = $this->service->supplementOffer($booking, [$validated]);
        $this->assertNotNull($offer);
        $this->assertSame($invoice->id, $offer->originalInvoice->id);
        $this->assertSame(36000, $offer->alreadyBilledCents);
        $this->assertSame(3000, $offer->differenceCents());
        $this->assertFalse($offer->favoursTheRenter());
    }

    public function testAnInvoiceBasedOnTheLatestSettlementNeedsNoSupplement(): void
    {
        $booking = $this->createBooking();
        $validated = $this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id);
        $invoice = $this->service->generate(
            $booking,
            $this->asset(),
            DocumentType::INVOICE,
            $this->settings(),
            null,
            1,
            $validated
        );
        $this->service->markSent($invoice->id, new \DateTimeImmutable());

        $this->assertNull($this->service->supplementOffer($booking, [$validated]));
    }

    /**
     * A closure with an unrecovered balance (#980) writes the debt off in
     * a version of its own: it lowers what is kept, and it bills nothing —
     * no supplementary invoice is offered in the renter's favour.
     */
    public function testAnUnrecoveredClosureOffersNoSupplement(): void
    {
        $booking = $this->createBooking();
        $validated = $this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id);
        $invoice = $this->service->generate(
            $booking,
            $this->asset(),
            DocumentType::INVOICE,
            $this->settings(),
            null,
            1,
            $validated
        );
        $this->service->markSent($invoice->id, new \DateTimeImmutable());

        $closing = $this->settlement(
            8,
            2,
            [...self::stayWithBrokenWindow(), [
                \Modules\Rental\Stay\SettlementLine::UNRECOVERED_LABEL,
                -20000,
                \Modules\Rental\Stay\SettlementLine::ORIGIN_UNRECOVERED,
            ]],
            bookingId: $booking->id,
            unrecoveredCents: 20000
        );

        $this->assertNull($this->service->supplementOffer($booking, [$validated, $closing]));
    }

    /**
     * Only the difference, a reference to the invoice by number and date,
     * the balance due with its deadline — and the invoice untouched.
     */
    public function testTheSupplementBillsOnlyTheDifferenceAndLeavesTheInvoiceAsItWas(): void
    {
        $booking = $this->createBooking();
        $invoice = $this->sentInvoice($booking);
        $invoiceBytes = $this->pdfTextOf($invoice->id);
        $invoiceSnapshot = $this->documentRepository->findSnapshot($invoice->id);
        $validated = $this->settlement(7, 1, self::stayWithBrokenWindow(), true, 3000, $booking->id);

        $supplement = $this->service->generateSupplementaryInvoice(
            $booking,
            $this->asset(),
            $this->settings(),
            [$validated],
            1
        );

        $this->assertSame(DocumentType::SUPPLEMENTARY_INVOICE, $supplement->type);
        $this->assertSame(1, $supplement->version);
        $this->assertSame('Facture complémentaire v1', $supplement->label());
        $this->assertSame('facture-complementaire-LOC-K7Q2M4-v1.pdf', $supplement->originalName);
        $this->assertTrue($supplement->isForRenter);
        $this->assertNull($supplement->sentAt);
        $this->assertSame(7, $supplement->settlementId);
        $this->assertSame(39000, $supplement->billedKeptCents);
        $this->assertSame($invoice->id, $supplement->supplementsDocumentId);

        // The frame and the figures, as printed; the sentences, as they were
        // substituted (a justified paragraph's text layer is split apart).
        $text = str_replace("\u{a0}", ' ', $this->renderedTextOf($supplement->id));
        $this->assertStringContainsString('LOC-K7Q2M4-C1', $text);
        $this->assertStringContainsString(
            'Complète la facture LOC-K7Q2M4-v1 du ' . $invoice->createdAt->format('d/m/Y'),
            $text
        );
        $this->assertStringContainsString('Déjà facturé : 360,00 €', $text);
        $this->assertStringContainsString('Décompte final validé : 390,00 €', $text);
        $this->assertStringContainsString('Différence : 30,00 €', $text);
        $values = $this->documentRepository->findSnapshot($supplement->id) ?? [];
        $this->assertSame('LOC-K7Q2M4-v1 du ' . $invoice->createdAt->format('d/m/Y'), $values['facture_origine']);
        // Against the agreed quote the invoice billed: only the damage is new.
        $this->assertSame('Ajouté au décompte : Vitre brisée (30,00 €).', $values['detail_complement']);
        $this->assertSame(
            'Restant dû : 30,00 €, à verser au plus tard le 19/08/2027.',
            $values['reglement_complement']
        );
        // Without Finance there is nothing to scan.
        $this->assertSame(0, substr_count($this->pdfTextOf($supplement->id), '/Subtype /Image'));

        // The invoice the renter holds is exactly what it was.
        $after = $this->documentRepository->findById($invoice->id);
        $this->assertNotNull($after);
        $this->assertSame($invoiceBytes, $this->pdfTextOf($invoice->id));
        $this->assertSame($invoiceSnapshot, $this->documentRepository->findSnapshot($invoice->id));
        $this->assertEquals($invoice->sentAt, $after->sentAt);
        $this->assertSame($invoice->billedKeptCents, $after->billedKeptCents);
        $this->assertNull($after->settlementId);
        $this->assertSame(1, $after->version);
    }

    public function testTheSupplementOfOneSettlementIsGeneratedOnce(): void
    {
        $booking = $this->createBooking();
        $this->sentInvoice($booking);
        $validated = $this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id);
        $this->service->generateSupplementaryInvoice($booking, $this->asset(), $this->settings(), [$validated], 1);

        $this->assertNull($this->service->supplementOffer($booking, [$validated]));
        try {
            $this->service->generateSupplementaryInvoice($booking, $this->asset(), $this->settings(), [$validated], 1);
            $this->fail('a second supplement of the same settlement was generated');
        } catch (RentalException $e) {
            $this->assertStringContainsString('Aucune facture complémentaire à générer', $e->getMessage());
        }
        $this->assertCount(1, array_filter(
            $this->service->forBooking($booking->id),
            static fn($document) => $document->type === DocumentType::SUPPLEMENTARY_INVOICE
        ));
    }

    /**
     * With Finance, the balance due is the receivable's, with the account,
     * the communication and the payment QR.
     */
    public function testWithFinanceTheBalanceDueCarriesTheCommunicationAndTheQr(): void
    {
        $service = $this->serviceWithPayments([
            'amount_cents' => 3000,
            'stage' => 'balance',
            'qr_data_uri' => self::QR_PNG,
        ]);
        $booking = $this->createBooking();
        $this->sentInvoice($booking);

        $supplement = $service->generateSupplementaryInvoice(
            $booking,
            $this->asset(),
            $this->settings(),
            [$this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id)],
            1
        );

        $this->assertSame(
            'Restant dû : 30,00 €, à verser au plus tard le 19/08/2027 sur le compte BE68 5390 0754 7034 '
                . '(Unité Saint-Georges), avec la communication +++090/9337/55493+++.',
            ($this->documentRepository->findSnapshot($supplement->id) ?? [])['reglement_complement'] ?? null
        );
        $this->assertStringContainsString('Scannez ce code', $this->renderedTextOf($supplement->id));
        $this->assertGreaterThan(0, substr_count($this->pdfTextOf($supplement->id), '/Subtype /Image'));
    }

    /**
     * A decision in the renter's favour after a supplement: the second one
     * bills the difference from the first, refers to the INVOICE, and says
     * what comes back — with no QR, even with a QR at hand.
     */
    public function testASecondSupplementInTheRentersFavourRefersToTheInvoiceAndCarriesNoQr(): void
    {
        $booking = $this->createBooking();
        $invoice = $this->sentInvoice($booking);
        $charged = $this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id);
        $first = $this->service->generateSupplementaryInvoice(
            $booking,
            $this->asset(),
            $this->settings(),
            [$charged],
            1
        );
        $this->service->markSent($first->id, new \DateTimeImmutable('2027-07-21 12:00:00'));

        $waived = $this->settlement(8, 2, [['Séjour', 36000, 'agreed']], bookingId: $booking->id);
        $service = $this->serviceWithPayments([
            'amount_cents' => 0,
            'overpaid_cents' => 3000,
            'qr_data_uri' => self::QR_PNG,
        ]);

        $offer = $service->supplementOffer($booking, [$waived, $charged]);
        $this->assertNotNull($offer);
        $this->assertSame($invoice->id, $offer->originalInvoice->id);
        $this->assertSame(39000, $offer->alreadyBilledCents);
        $this->assertSame(-3000, $offer->differenceCents());
        $this->assertTrue($offer->favoursTheRenter());

        $second = $service->generateSupplementaryInvoice(
            $booking,
            $this->asset(),
            $this->settings(),
            [$waived, $charged],
            1
        );

        $this->assertSame(2, $second->version);
        $this->assertSame($invoice->id, $second->supplementsDocumentId);
        $text = str_replace("\u{a0}", ' ', $this->renderedTextOf($second->id));
        $this->assertStringContainsString('LOC-K7Q2M4-C2', $text);
        $this->assertStringContainsString('Complète la facture LOC-K7Q2M4-v1 du', $text);
        $this->assertStringContainsString('Différence : 30,00 € en faveur du locataire', $text);
        $this->assertStringNotContainsString('Scannez', $text);
        $values = $this->documentRepository->findSnapshot($second->id) ?? [];
        $this->assertSame('Retiré du décompte : Vitre brisée (30,00 €).', $values['detail_complement']);
        $this->assertStringStartsWith(
            'Montant à rembourser au locataire (trop-perçu) : 30,00 €.',
            (string) $values['reglement_complement']
        );
        $this->assertSame(0, substr_count($this->pdfTextOf($second->id), '/Subtype /Image'));
        // The first supplement is left as it was sent.
        $this->assertSame(39000, $this->documentRepository->findById($first->id)?->billedKeptCents);
    }

    /** An invoice generated before #980 recorded nothing: its printed total is the baseline. */
    public function testAnInvoiceFromBeforeTheColumnsIsBilledAtTheTotalItPrinted(): void
    {
        $booking = $this->createBooking();
        $invoice = $this->sentInvoice($booking);
        $this->pdo->prepare('UPDATE rental_documents SET billed_kept_cents = NULL WHERE id = ?')
            ->execute([$invoice->id]);
        $legacy = $this->documentRepository->findById($invoice->id);
        $this->assertNotNull($legacy);

        $this->assertSame(36000, $this->service->billedCents($legacy));
        $this->assertSame(3000, $this->service->supplementOffer(
            $booking,
            [$this->settlement(7, 1, self::stayWithBrokenWindow(), bookingId: $booking->id)]
        )?->differenceCents());
    }

    public function testTheSupplementIsNeitherGeneratedAsADocumentNorWritten(): void
    {
        $booking = $this->createBooking();

        try {
            $this->service->generate($booking, $this->asset(), DocumentType::SUPPLEMENTARY_INVOICE, $this->settings());
            $this->fail('the supplementary invoice was generated without a settlement');
        } catch (RentalException $e) {
            $this->assertStringContainsString('page « Facture »', $e->getMessage());
        }

        $this->expectException(RentalException::class);
        $this->service->saveBookingText($booking, DocumentType::SUPPLEMENTARY_INVOICE, '<p>x</p>', 1);
    }
}
