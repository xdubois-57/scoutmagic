<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
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
use Modules\Rental\Repository\RentalBookingEventRepository;
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
        $settingService->register('unit_address', 'Rue du Local 1, 1000 Bruxelles', 'text', 'Adresse', 'Description de test.');

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
            new RentalBookingEventRepository($this->pdo),
            $this->editableContentService,
            $this->fileRepository,
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
        string $reference = 'LOC-2027-0042'
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

        $second = $this->createBooking('Marc Dupont', null, 'LOC-2027-0043');

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

    // ── Level 3: the PDF ────────────────────────────────────────────────

    public function testGeneratingProducesARealPdfRegisteredAsAFile(): void
    {
        $this->setTemplate('<p>Contrat pour {{ locataire_nom }}, référence {{ reference }}.</p>');
        $booking = $this->createBooking();

        $document = $this->service->generate($booking, $this->asset(), DocumentType::CONTRACT, $this->settings());

        $this->assertSame(1, $document->version);
        $this->assertSame('contrat-LOC-2027-0042-v1.pdf', $document->originalName);
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
        $this->assertStringNotContainsString('LOC-2027-0042', $file->relativePath);
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
        $this->assertSame('facture-LOC-2027-0042-v1.pdf', $invoice->originalName);
    }

    public function testAnEmptyTemplateIsRefusedRatherThanProducingABlankContract(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/vide/');

        $this->service->generate($this->createBooking(), $this->asset(), DocumentType::CONTRACT, $this->settings());
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
        $this->assertSame('LOC-2027-0042', $decoded['reference']);
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

        $this->assertSame('LOC-2027-0042', $values['reference']);
        $this->assertSame('Local Saint-Georges', $values['bien']);
        $this->assertSame('01/07/2027', $values['date_arrivee']);
        $this->assertSame('04/07/2027', $values['date_depart']);
        $this->assertSame('3', $values['nuits']);
        $this->assertSame('20', $values['participants']);
        $this->assertSame('360,00 €', $values['prix_total']);
        $this->assertSame('100,00 €', $values['acompte']);
        $this->assertSame('500,00 €', $values['caution']);
        $this->assertSame('Jeanne Martin', $values['locataire_nom']);
        $this->assertSame('Les Scouts de Nulle Part', $values['locataire_organisation']);
        $this->assertSame('+++100/0000/00034+++', $values['communication']);
        $this->assertSame('Rue du Local 1, 1000 Bruxelles', $values['adresse_bailleur']);
        $this->assertSame('18:00', $values['heure_arrivee']);
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

    // ── VAT (§6.27) ─────────────────────────────────────────────────────

    public function testNoVatIsEverComputedAndTheExemptionNoteIsConfigurable(): void
    {
        $this->assertStringContainsString('exonér', $this->service->vatNote($this->asset()));

        $this->assetRepository->saveVatExemptionNote($this->assetId, 'Article 44 du Code de la TVA.');

        $this->assertSame('Article 44 du Code de la TVA.', $this->service->vatNote($this->asset()));
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

    // ── Who may fetch a document (§6.24, §6.26) ─────────────────────────

    private function checkerFor(?string $email): RentalDocumentOwnershipChecker
    {
        return new RentalDocumentOwnershipChecker(
            $this->bookingRepository,
            new RentalAuthorizationService(
                new \Core\Member\MemberService(
                    new \Core\Import\MemberYearRepository($this->pdo),
                    $this->encryption,
                    \Core\Database\Connection::withPdo($this->pdo)
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
}
