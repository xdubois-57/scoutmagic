<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Signature\AccountSignature;
use Core\Signature\PersonalSignatureRepository;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureSubmission;
use Core\Signature\WitnessSignature;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\InventoryPhotoRenderer;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalDocumentService;
use Modules\Rental\Service\RentalInventoryValidationService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\InventoryScope;
use Modules\Rental\Stay\InventorySigning;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\PaperSheet;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RenterSigning;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The PDF « Valider l'état des lieux » produces, with its photos (#989,
 * lot C): rendered by the real dompdf from photos stored as the
 * controller stores them (2 400 px JPEG), so what is measured — the size
 * of the file the renter is e-mailed, the pages the annex takes — is what
 * a real validation makes.
 *
 * The validation's other rules (frozen first, taken back on a failure,
 * the missing readings) are pinned in `RentalStayServiceTest`.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalInventoryValidationServiceTest extends TestCase
{
    /**
     * The ceiling for ten photos. Ten 1 600 px copies at quality 80 of
     * photographs weigh 2,5 to 4 Mo; the e-mail adds a third in base64.
     */
    private const TEN_PHOTOS_CEILING_BYTES = 4 * 1024 * 1024;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalStayService $stay;
    private RentalStayRepository $stayRepository;
    private RentalAssetRepository $assetRepository;
    private RentalBookingRepository $bookingRepository;
    private FileRepository $fileRepository;
    private string $storagePath;
    private int $assetId;

    private JournalService $journal;
    private UserAccountRepository $accounts;
    private PersonalSignatureService $signatures;

    /** The PDF the last validation filed, as dompdf wrote it. */
    private ?string $filedPdf = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = $this->journal = new JournalService(new JournalRepository($this->pdo));
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->signatures = new PersonalSignatureService(
            new PersonalSignatureRepository($this->pdo, $this->encryption),
            $journal,
            $this->accounts
        );
        $pricing = new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal);

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->stayRepository = new RentalStayRepository($this->pdo, $this->encryption);
        $this->fileRepository = new FileRepository($this->pdo);
        $this->stay = new RentalStayService(
            $this->stayRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $pricing,
            new SettlementCalculator(),
            $journal
        );

        $this->storagePath = sys_get_temp_dir() . '/rental_inventory_pdf_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/rental', 0755, true);

        $this->assetId = $this->assetRepository->create(
            'Local', 'Local Saint-Georges', 'local-saint-georges', 60, 1, null, null, null, true
        );
        $pricing->saveAssetPricing($this->assetId, 'per_night', 12000, null, null);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->storagePath);
    }

    // ── The tests ───────────────────────────────────────────────────────

    /**
     * Ten photos of 2 400 px — a meter's two and eight of two incidents —
     * make a PDF under the ceiling, its annex two pages of six after the
     * page of text, and each incident line names its photos.
     */
    public function testTenPhotosStayUnderTheCeilingTwoByThreeToAPage(): void
    {
        $booking = $this->createBooking();
        $meterId = $this->stay->addMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $this->stay->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->at('2027-07-01 15:00'),
            $this->storedPhoto(1), null, 1
        );
        $this->arrivalValidated($booking);
        $this->stay->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::DEPARTURE, '1342,5', $this->at('2027-07-04 10:00'),
            $this->storedPhoto(2), null, 1
        );
        $this->stay->reportIncident(
            $booking, 'Porte du réfectoire forcée', null, array_map($this->storedPhoto(...), [3, 4, 5, 6, 7]), 1
        );
        $this->stay->reportIncident($booking, 'Vitre cassée', null, array_map($this->storedPhoto(...), [8, 9, 10]), 1);

        $this->signWith($this->validationService(), $booking, ReadingPhase::DEPARTURE, $this->at('2027-07-04 11:30'));

        $this->assertNotNull($this->filedPdf);
        $this->assertLessThan(self::TEN_PHOTOS_CEILING_BYTES, strlen($this->filedPdf));

        $document = (new Parser())->parseContent($this->filedPdf);
        $this->assertCount(3, $document->getPages(), 'one page of text, then two of six photos');
        $text = $document->getText();
        $this->assertStringContainsString('Porte du réfectoire forcée (photos 3, 4, 5, 6 et 7)', $text);
        $this->assertStringContainsString('Vitre cassée (photos 8, 9 et 10)', $text);
        $this->assertStringContainsString('Annexe : photos', $text);
        $this->assertStringContainsString('Compteur Électricité, entrée : 1 000,000 kWh', $text);
        $this->assertStringContainsString('Photo 10', $text);

        $this->assertSame(10, $this->embeddedJpegCount($this->filedPdf), 'ten distinct images, each embedded once');
    }

    /**
     * The copies are made for the PDF and never kept: the storage holds
     * the photos it held before, and nothing beside them.
     */
    public function testTheCopiesAreNeverStored(): void
    {
        $booking = $this->createBooking();
        $this->stay->addInventoryItem($this->assetId, 'Clés');
        $this->stay->snapshotInventory($booking, $this->assetId);
        $this->stay->reportIncident($booking, 'Rayure', null, [$this->storedPhoto(1)], 1);
        $this->arrivalValidated($booking);
        $before = glob($this->storagePath . '/rental/*') ?: [];
        $files = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

        $this->signWith($this->validationService(), $booking, ReadingPhase::DEPARTURE, $this->at('2027-07-04 11:30'));

        $this->assertSame($before, glob($this->storagePath . '/rental/*') ?: []);
        $this->assertSame($files, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertStringContainsString('Rayure (photo 1)', (new Parser())->parseContent((string) $this->filedPdf)->getText());
    }

    /**
     * Each photo goes into the PDF as a copy of 1 600 px on its longest
     * side (#989 §6), a JPEG — whatever the 2 400 px stored beside it.
     */
    public function testEachCopyForThePdfIsBroughtDownTo1600Pixels(): void
    {
        $fileId = $this->storedPhoto(1);
        $renderer = new InventoryPhotoRenderer($this->fileRepository, $this->storagePath);

        $copy = $renderer->render([$fileId])[$fileId] ?? null;

        $this->assertNotNull($copy);
        $this->assertSame([1600, 1200], [$copy['width'], $copy['height']]);
        $size = getimagesizefromstring($copy['jpeg']);
        $this->assertIsArray($size);
        $this->assertSame([1600, 1200, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);
        $this->assertSame(1600, InventoryPhotoRenderer::MAX_LONG_SIDE);
    }

    /**
     * The arrival's PDF shows the arrival's meter photos and nothing of
     * the incidents, which belong to the departure.
     */
    public function testTheArrivalsAnnexHoldsItsMeterPhotosOnly(): void
    {
        $booking = $this->createBooking();
        $meterId = $this->stay->addMeter($this->assetId, 'Eau', MeterKind::WATER, 'm³', null);
        $this->stay->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '12', $this->at('2027-07-01 15:00'),
            $this->storedPhoto(1), null, 1
        );
        $this->stay->reportIncident($booking, 'Rayure', null, [$this->storedPhoto(2)], 1);

        $this->signWith($this->validationService(), $booking, ReadingPhase::ARRIVAL, $this->at('2027-07-01 15:30'));

        $text = (new Parser())->parseContent((string) $this->filedPdf)->getText();
        $this->assertStringContainsString('Compteur Eau, entrée : 12,000 m³', $text);
        $this->assertStringNotContainsString('Rayure', $text);
        $this->assertSame(1, $this->embeddedJpegCount((string) $this->filedPdf));
    }

    /** A photo whose file is gone is left out; the validation goes through. */
    public function testAPhotoWhoseFileIsGoneIsLeftOut(): void
    {
        $booking = $this->createBooking();
        $this->stay->addInventoryItem($this->assetId, 'Clés');
        $this->stay->snapshotInventory($booking, $this->assetId);
        $gone = $this->storedPhoto(1);
        $kept = $this->storedPhoto(2);
        $this->stay->reportIncident($booking, 'Rayure', null, [$gone, $kept], 1);
        $record = $this->fileRepository->findById($gone);
        $this->assertNotNull($record);
        unlink($this->storagePath . '/' . $record->relativePath);
        $this->arrivalValidated($booking);

        $this->signWith($this->validationService(), $booking, ReadingPhase::DEPARTURE, $this->at('2027-07-04 11:30'));

        $this->assertStringContainsString('Rayure (photo 1)', (new Parser())->parseContent((string) $this->filedPdf)->getText());
        $this->assertSame(1, $this->embeddedJpegCount((string) $this->filedPdf));
    }

    // ── Signed on the spot (#989, lot E) ────────────────────────────────

    /**
     * Both signatures: the PDF says who accepted and who signed, the row
     * says what the renter did and which account signed, and the
     * fingerprint is the SHA-256 of the very file filed — in the row, in
     * the booking's history and in the journal of the act.
     */
    public function testTheRenterAndTheManagerSignAndTheFingerprintIsTheFilesOwn(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $service = $this->realService();

        $result = $service->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::onSite($this->witness('Jeanne Témoin')),
            $this->managerSignature($account),
            $this->at('2027-07-01 15:30')
        );

        $path = $this->documentService()->absolutePath($result['document']);
        $this->assertIsString($path);
        $this->assertSame(hash_file('sha256', $path), $result['sha256']);
        $validation = $this->stay->inventoryValidations($booking->id)['arrival'];
        $this->assertSame($result['sha256'], $validation['document_sha256']);
        $this->assertSame(RenterSigning::ON_SITE, $validation['renter_signing']);
        $this->assertSame($account, $validation['validated_by_account_id']);
        $this->assertSame($result['document']->id, $validation['document_id']);

        $text = (string) preg_replace('/\s+/u', ' ', (new Parser())->parseFile($path)->getText());
        $this->assertStringContainsString('Accepté sur place par Jeanne Témoin, signé au doigt', $text);
        $this->assertStringContainsString('Signé au doigt par Anne Dupont', $text);
        $this->assertStringContainsString('Signé sur place, Local Saint-Georges, le 01/07/2027 à 15:30', $text);

        $history = $this->historyOf($booking);
        $this->assertCount(1, $history);
        $this->assertSame('État des lieux signé', $history[0]['summary']);
        $this->assertSame(
            "État des lieux d'entrée — signé sur place par le locataire — SHA-256 " . $result['sha256'],
            $history[0]['to']
        );
        $this->assertSame($account, $history[0]['account']);

        $act = $this->journalContexts('personal_signature_document_signed');
        $this->assertCount(1, $act);
        $this->assertSame($result['sha256'], $act[0]['document_sha256']);
        $this->assertSame('inventory', $act[0]['document_type']);
        $this->assertSame($result['document']->id, $act[0]['document_id']);
    }

    /**
     * @return iterable<string, array{RenterSigning, string, string}>
     */
    public static function renterWithoutSignature(): iterable
    {
        yield 'absent' => [RenterSigning::ABSENT, 'Le locataire était absent', 'locataire absent'];
        yield 'refused' => [RenterSigning::REFUSED, 'Le locataire a refusé de signer', 'le locataire a refusé de signer'];
    }

    /**
     * Absent or refusing, the renter still receives the PDF, which says
     * so; the manager's signature is still on it, and the history says
     * what the renter did.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renterWithoutSignature')]
    public function testWithoutTheRenterThePdfSaysWhyAndStillGoesToThem(
        RenterSigning $choice,
        string $inThePdf,
        string $inTheHistory
    ): void {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $sent = [];
        $mail = $this->createStub(RentalBookingMailService::class);
        $mail->method('sendDocument')->willReturnCallback(
            function (RentalBooking $to, RentalAsset $asset, string $label) use (&$sent): string {
                $sent[] = [$to->id, $label];

                return '<message@test>';
            }
        );

        $result = $this->realService($mail)->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::without($choice),
            $this->managerSignature($account),
            $this->at('2027-07-01 15:30')
        );

        $this->assertTrue($result['sent']);
        $this->assertSame([[$booking->id, "État des lieux d'entrée"]], $sent);
        $path = (string) $this->documentService()->absolutePath($result['document']);
        $text = (string) preg_replace('/\s+/u', ' ', (new Parser())->parseFile($path)->getText());
        $this->assertStringContainsString($inThePdf, $text);
        $this->assertStringContainsString('Signé au doigt par Anne Dupont', $text);
        $this->assertSame($choice, $this->stay->inventoryValidations($booking->id)['arrival']['renter_signing']);
        $this->assertStringContainsString(' — ' . $inTheHistory . ' — SHA-256 ', (string) $this->historyOf($booking)[0]['to']);
    }

    /**
     * « Mémoriser » is one act with the signing: a PDF that cannot be made
     * keeps no signature in the account, validates nothing, files nothing.
     */
    public function testARememberedSignatureIsNotKeptWhenThePdfFails(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $pdf = $this->createStub(DocumentPdfService::class);
        $pdf->method('generate')->willThrowException(new \RuntimeException('dompdf'));

        try {
            $this->realService(null, null, $pdf)->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::onSite($this->witness('Jeanne Témoin')),
                $this->managerSignature($account, true),
                $this->at('2027-07-01 15:30')
            );
            $this->fail('A validation without its PDF went through.');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertStringContainsString("Rien n'a été validé ni signé", $e->getMessage());
        }

        $this->assertFalse($this->signatures->has($account), 'nothing remembered');
        $this->assertSame([], $this->stay->inventoryValidations($booking->id));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn());
        $this->assertSame([], $this->historyOf($booking));
        $this->assertSame([], $this->journalContexts('personal_signature_document_signed'));
    }

    /**
     * A failure after the PDF was written to disk rolls everything back —
     * the row, the document, the remembered signature — and deletes the
     * file: it carries both signatures, and nothing would point at it.
     */
    public function testAFailureAfterThePdfWasWrittenDeletesTheFile(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $this->pdo->exec(
            "CREATE TRIGGER no_document BEFORE UPDATE OF document_id ON rental_inventory_validations
             BEGIN SELECT RAISE(ABORT, 'disque plein'); END"
        );

        try {
            $this->realService()->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::onSite($this->witness('Jeanne Témoin')),
                $this->managerSignature($account, true),
                $this->at('2027-07-01 15:30')
            );
            $this->fail('A validation whose document could not be attached went through.');
        } catch (\Modules\Rental\Service\RentalException) {
            // Expected.
        }

        $this->assertSame([], glob($this->storagePath . '/' . RentalDocumentService::STORAGE_SUBDIRECTORY . '/*') ?: []);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertFalse($this->signatures->has($account));
        $this->assertSame([], $this->stay->inventoryValidations($booking->id));
    }

    /**
     * The signer is told — the session's account and nobody else — with a
     * link to the booking's Documents; and an e-mail to the renter that
     * fails holds nothing of it back.
     */
    public function testTheSessionsAccountAloneIsNotifiedEvenWhenTheRentersEmailFails(): void
    {
        $account = $this->sessionAccount();
        $this->accounts->create('autre@test.be');
        $booking = $this->bookingWithAnInventory();
        $notified = [];
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(
            function (string $type, array $recipients, array $payload) use (&$notified): void {
                $notified[] = ['type' => $type, 'recipients' => $recipients, 'payload' => $payload];
            }
        );
        $mail = $this->createStub(RentalBookingMailService::class);
        $mail->method('sendDocument')->willThrowException(new \RuntimeException('smtp'));
        $this->arrivalValidated($booking);

        $result = $this->realService($mail, $notifications)->validate(
            $booking,
            $this->asset(),
            ReadingPhase::DEPARTURE,
            1,
            InventorySigning::without(RenterSigning::ABSENT),
            $this->managerSignature($account),
            new \DateTimeImmutable('2027-07-04 09:30:00', new \DateTimeZone('UTC'))
        );

        $this->assertFalse($result['sent']);
        $this->assertCount(1, $notified);
        $this->assertSame('rental.document_signed', $notified[0]['type']);
        $this->assertSame([['userAccountId' => $account, 'memberId' => null]], $notified[0]['recipients']);
        $this->assertSame("État des lieux de sortie signé — Local Saint-Georges", $notified[0]['payload']['title']);
        $this->assertSame(
            "Vous avez signé l'état des lieux de sortie de LOC-A2B3C4 le 04/07/2027 à 11:30, avec votre signature."
                . ' Il est rangé dans les Documents de la réservation.',
            $notified[0]['payload']['body']
        );
        $this->assertSame(
            '/mes-locations/local-saint-georges/reservations/' . $booking->id . '/documents',
            $notified[0]['payload']['url']
        );
        $this->assertStringNotContainsString('Jeanne', json_encode($notified, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** A notification that cannot be created is journaled, and the signing stands. */
    public function testAFailedNotificationIsJournaledAndChangesNothing(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willThrowException(new \RuntimeException('push'));

        $result = $this->realService(null, $notifications)->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::without(RenterSigning::REFUSED),
            $this->managerSignature($account),
            $this->at('2027-07-01 15:30')
        );

        $this->assertArrayHasKey('arrival', $this->stay->inventoryValidations($booking->id));
        $this->assertSame(
            [['booking_id' => $booking->id, 'document_id' => $result['document']->id]],
            $this->journalContexts('rental_signer_notification_failed')
        );
    }

    /**
     * **The renter's signature exists in the PDF alone.** After a signing
     * remembered by the manager, with the renter's history line, the act's
     * journal line and the notification written, no table of the database
     * holds the name the renter typed nor their drawing — every column of
     * every table read, the encrypted ones decrypted under every context
     * the code encrypts with.
     */
    public function testNoTableHoldsTheRentersTypedNameNorTheirDrawing(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $typedName = 'Jeanne Témoin-Unique';
        $witness = $this->witness($typedName);
        $notified = [];
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(
            function (string $type, array $recipients, array $payload) use (&$notified): void {
                $notified[] = $payload;
            }
        );

        $result = $this->realService(null, $notifications)->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::onSite($witness),
            $this->managerSignature($account, true),
            $this->at('2027-07-01 15:30')
        );

        // The PDF does carry them: the scan below is not blind.
        $pdf = (string) file_get_contents((string) $this->documentService()->absolutePath($result['document']));
        $this->assertStringContainsString($typedName, (string) (new Parser())->parseContent($pdf)->getText());
        $this->assertTrue($this->signatures->has($account), "the manager's own signature was remembered");

        $decrypted = 0;
        foreach ($this->everyStoredValue($decrypted) as $where => $value) {
            $this->assertStringNotContainsString($typedName, $value, $where . ' holds the typed name');
            $this->assertStringNotContainsString('Témoin', $value, $where . ' holds part of the typed name');
            $this->assertStringNotContainsString($witness->png->bytes(), $value, $where . ' holds the drawing');
            $this->assertStringNotContainsString(base64_encode($witness->png->bytes()), $value, $where . ' holds the drawing');
        }
        $this->assertGreaterThan(0, $decrypted, 'the encrypted columns were read in clear');
        // And the notification, stored encrypted by the core, carries none.
        $this->assertCount(1, $notified);
        $this->assertStringNotContainsString('Témoin', json_encode($notified, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * **Nothing of the device is added** (#989 §1): neither the address
     * the request came from nor the browser that sent it reaches the PDF,
     * the validation's row or the booking's history. The one place an
     * address is kept is the core journal's own column, where every
     * action of a manager has it (decision Q2) — never a field of the act.
     */
    public function testNoAddressNorBrowserReachesThePdfTheRowOrTheHistory(): void
    {
        $address = '198.51.100.77';
        $browser = 'Mozilla/5.0 (Linux; Android 14; Pixel-Marqueur-989) Mobile';
        $saved = [$_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null];
        $_SERVER['REMOTE_ADDR'] = $address;
        $_SERVER['HTTP_USER_AGENT'] = $browser;
        try {
            $account = $this->sessionAccount();
            $booking = $this->bookingWithAnInventory();
            $result = $this->realService()->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::onSite($this->witness('Jeanne Témoin')),
                $this->managerSignature($account),
                $this->at('2027-07-01 15:30')
            );
        } finally {
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            if ($saved[0] !== null) {
                $_SERVER['REMOTE_ADDR'] = $saved[0];
            }
            if ($saved[1] !== null) {
                $_SERVER['HTTP_USER_AGENT'] = $saved[1];
            }
        }

        $pdf = (string) file_get_contents((string) $this->documentService()->absolutePath($result['document']));
        $text = (new Parser())->parseContent($pdf)->getText();
        $this->assertStringContainsString('Accepté sur place par Jeanne Témoin', (string) preg_replace('/\s+/u', ' ', $text));
        foreach ([$address, 'Pixel-Marqueur-989', 'Android', 'Mozilla'] as $trace) {
            $this->assertStringNotContainsString($trace, $text, 'the PDF names the device');
            $this->assertStringNotContainsString($trace, $pdf, 'the PDF file carries the device');
        }

        $journaled = 0;
        $decrypted = 0;
        foreach ($this->everyStoredValue($decrypted) as $where => $value) {
            $this->assertStringNotContainsString('Pixel-Marqueur-989', $value, $where . ' holds the browser');
            if (str_starts_with($where, 'event_log.ip_address#')) {
                $journaled += $value === $address ? 1 : 0;
                continue;
            }
            $this->assertStringNotContainsString($address, $value, $where . ' holds the address');
        }
        // The scan is not blind: the act was journaled, with the address
        // in its own column only, and the history was written.
        $this->assertGreaterThan(0, $journaled);
        $this->assertCount(1, $this->historyOf($booking));
    }

    // ── The minimal inventory (#989, lot G) ─────────────────────────────

    /**
     * An asset with neither items nor meters signs a minimal inventory:
     * the fixed sentence when no remark is written, both signatures, the
     * PDF filed and sent — the same act as a full one, the history saying
     * which it was.
     */
    public function testAnAssetWithNothingToWalkSignsAMinimalInventory(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->createBooking();
        $sent = [];
        $mail = $this->createStub(RentalBookingMailService::class);
        $mail->method('sendDocument')->willReturnCallback(
            function (RentalBooking $to, RentalAsset $asset, string $label) use (&$sent): string {
                $sent[] = $label;

                return '<message@test>';
            }
        );

        $result = $this->realService($mail)->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::onSite($this->witness('Jeanne Témoin')),
            $this->managerSignature($account),
            $this->at('2027-07-01 15:30'),
            '   '
        );

        $this->assertTrue($result['sent']);
        $this->assertSame(["État des lieux d'entrée"], $sent);
        $validation = $this->stay->inventoryValidations($booking->id)['arrival'];
        $this->assertSame(InventoryScope::MINIMAL, $validation['scope']);
        $this->assertNull($validation['remark'], 'a blank remark is the fixed sentence, not a stored one');
        $this->assertNull($this->pdo->query('SELECT remark_encrypted FROM rental_inventory_validations')->fetchColumn());
        $this->assertSame($result['sha256'], $validation['document_sha256']);

        $path = (string) $this->documentService()->absolutePath($result['document']);
        $text = (string) preg_replace('/\s+/u', ' ', (new Parser())->parseFile($path)->getText());
        $this->assertStringContainsString('Bien remis en bon état, sans remarque', $text);
        $this->assertStringContainsString('Accepté sur place par Jeanne Témoin, signé au doigt', $text);
        $this->assertStringContainsString('Signé au doigt par Anne Dupont', $text);
        $this->assertStringNotContainsString('Éléments', $text);
        $this->assertStringNotContainsString('Compteurs', $text);
        $this->assertSame(
            "État des lieux d'entrée minimal — signé sur place par le locataire — SHA-256 " . $result['sha256'],
            $this->historyOf($booking)[0]['to']
        );
    }

    /**
     * The free remark replaces the fixed sentence in the PDF, beside the
     * departure's incidents; it is stored encrypted under its own context
     * and read back in clear, and the history never carries it.
     */
    public function testAMinimalRemarkIsStoredEncryptedAndReadBack(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->createBooking();
        $this->stay->reportIncident($booking, 'Vitre cassée', null, [], 1);
        $this->arrivalValidated($booking);
        $remark = "Le local est rendu propre.\nUne chaise manque au réfectoire.";

        $result = $this->realService()->validate(
            $booking,
            $this->asset(),
            ReadingPhase::DEPARTURE,
            1,
            InventorySigning::without(RenterSigning::ABSENT),
            $this->managerSignature($account),
            $this->at('2027-07-04 10:00'),
            '  ' . $remark . '  '
        );

        $validation = $this->stay->inventoryValidations($booking->id)['departure'];
        $this->assertSame(InventoryScope::MINIMAL, $validation['scope']);
        $this->assertSame($remark, $validation['remark']);

        $stored = (string) $this->pdo->query(
            "SELECT remark_encrypted FROM rental_inventory_validations WHERE phase = 'departure'"
        )->fetchColumn();
        $this->assertStringNotContainsString('chaise', $stored);
        $this->assertSame($remark, $this->encryption->decrypt($stored, 'rental_inventory_validations.remark'));

        $path = (string) $this->documentService()->absolutePath($result['document']);
        $text = (string) preg_replace('/\s+/u', ' ', (new Parser())->parseFile($path)->getText());
        $this->assertStringContainsString('Le local est rendu propre. Une chaise manque au réfectoire.', $text);
        $this->assertStringNotContainsString('sans remarque', $text);
        $this->assertStringContainsString('Vitre cassée', $text);
        foreach ($this->historyOf($booking) as $line) {
            $this->assertStringNotContainsString('chaise', (string) $line['to']);
        }
    }

    /**
     * Given back with incidents and no remark, a minimal departure never
     * says « en bon état »: the PDF says the incidents follow, and lists
     * them.
     */
    public function testAMinimalDepartureWithIncidentsIsNeverSaidInGoodOrder(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->createBooking();
        $this->stay->reportIncident($booking, 'Vitre cassée', null, [], 1);
        $this->arrivalValidated($booking);

        $result = $this->realService()->validate(
            $booking,
            $this->asset(),
            ReadingPhase::DEPARTURE,
            1,
            InventorySigning::without(RenterSigning::ABSENT),
            $this->managerSignature($account),
            $this->at('2027-07-04 10:00')
        );

        $path = (string) $this->documentService()->absolutePath($result['document']);
        $text = (string) preg_replace('/\s+/u', ' ', (new Parser())->parseFile($path)->getText());
        $this->assertStringContainsString('Bien rendu ; incidents constatés ci-dessous', $text);
        $this->assertStringNotContainsString('en bon état', $text);
        $this->assertStringContainsString('Vitre cassée', $text);
        $this->assertNull($this->stay->inventoryValidations($booking->id)['departure']['remark']);
    }

    /**
     * A minimal inventory is signed on the screen (#989, lots F and G): it
     * has no checklist to print, so « Signé sur papier » is refused with a
     * sentence that says what to do, and nothing is validated nor filed.
     */
    public function testAMinimalInventoryIsNeverSignedOnPaper(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->createBooking();

        try {
            $this->realService()->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::onPaper(new PaperSheet($this->storedPhoto(41), $account)),
                null,
                $this->at('2027-07-01 15:30')
            );
            $this->fail('A minimal inventory was signed on paper.');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertSame(RentalInventoryValidationService::MINIMAL_NOT_ON_PAPER, $e->getMessage());
        }

        $this->assertSame([], $this->stay->inventoryValidations($booking->id));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn());
    }

    /** A remark past its length is refused, and nothing is validated. */
    public function testARemarkTooLongIsRefused(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->createBooking();

        try {
            $this->realService()->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::without(RenterSigning::ABSENT),
                $this->managerSignature($account),
                $this->at('2027-07-01 15:30'),
                str_repeat('é', InventoryScope::REMARK_MAX + 1)
            );
            $this->fail('A remark past its length went through.');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertSame('La remarque ne peut pas dépasser 2 000 caractères.', $e->getMessage());
        }

        $this->assertSame([], $this->stay->inventoryValidations($booking->id));
    }

    /** A full inventory has its lines' notes: a posted remark is not kept. */
    public function testAFullInventoryKeepsNoRemark(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();

        $this->realService()->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::without(RenterSigning::ABSENT),
            $this->managerSignature($account),
            $this->at('2027-07-01 15:30'),
            'Une remarque'
        );

        $validation = $this->stay->inventoryValidations($booking->id)['arrival'];
        $this->assertSame(InventoryScope::FULL, $validation['scope']);
        $this->assertNull($validation['remark']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    // ── « Signé sur papier » and the blank checklist (#989, lot F) ──────

    /**
     * Signed on paper, nobody signs on the screen: the photo of the sheet
     * is filed with the booking's documents — a photo, not for the renter,
     * who has it in the PDF — referenced by the validation, printed last
     * in the annex, and the signatures block sends the reader to it. The
     * session's account is in the row, the history and the journal; there
     * is no electronic signing to journal, nor a « signed with your
     * signature » to notify. The renter still receives the PDF.
     */
    public function testSignedOnPaperTheSheetIsInTheAnnexAndInTheDocuments(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $sheet = $this->storedPhoto(31);
        $notified = [];
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(
            function (string $type) use (&$notified): void {
                $notified[] = $type;
            }
        );
        $sent = [];
        $mail = $this->createStub(RentalBookingMailService::class);
        $mail->method('sendDocument')->willReturnCallback(
            function (RentalBooking $to, RentalAsset $asset, string $label) use (&$sent): string {
                $sent[] = $label;

                return '<message@test>';
            }
        );

        $result = $this->realService($mail, $notifications)->validate(
            $booking,
            $this->asset(),
            ReadingPhase::ARRIVAL,
            1,
            InventorySigning::onPaper(new PaperSheet($sheet, $account)),
            null,
            $this->at('2027-07-01 15:30')
        );

        $validation = $this->stay->inventoryValidations($booking->id)['arrival'];
        $this->assertSame(RenterSigning::PAPER, $validation['renter_signing']);
        $this->assertSame($account, $validation['validated_by_account_id']);
        $this->assertSame($result['sha256'], $validation['document_sha256']);
        $photo = $this->documentService()->find((int) $validation['paper_sheet_document_id']);
        $this->assertNotNull($photo);
        $this->assertSame(DocumentType::PHOTO, $photo->type);
        $this->assertSame($sheet, $photo->fileId);
        $this->assertFalse($photo->isForRenter, 'the renter has it in the PDF');
        $this->assertSame($booking->id, $photo->bookingId);

        $path = (string) $this->documentService()->absolutePath($result['document']);
        $pdf = (string) file_get_contents($path);
        $parsed = (new Parser())->parseContent($pdf);
        $text = (string) preg_replace('/\s+/u', ' ', $parsed->getText());
        $this->assertStringContainsString(
            'Signé sur papier par les deux parties le 01/07/2027 à 15:30 : voir la feuille signée, photo 1',
            $text
        );
        $this->assertStringContainsString('Annexe : feuille signée', $text);
        $this->assertStringContainsString("Photo 1 — Feuille d'état des lieux signée sur papier", $text);
        $this->assertStringNotContainsString('Signé au doigt', $text);
        $this->assertStringNotContainsString('Signé sur place', $text);
        $this->assertSame(1, $this->embeddedJpegCount($pdf), 'the sheet, and no signature image');
        $this->assertCount(2, $parsed->getPages(), 'the text, then the sheet on a page of its own');

        $history = $this->historyOf($booking);
        $signed = array_values(array_filter($history, static fn(array $row): bool => $row['summary'] === 'État des lieux signé'));
        $this->assertCount(1, $signed);
        $this->assertSame(
            "État des lieux d'entrée — signé sur papier par les deux parties — SHA-256 " . $result['sha256'],
            $signed[0]['to']
        );
        $this->assertSame($account, $signed[0]['account']);

        $this->assertSame([], $this->journalContexts('personal_signature_document_signed'));
        $act = $this->journalContexts('rental_inventory_signed_on_paper');
        $this->assertCount(1, $act);
        $this->assertSame($result['sha256'], $act[0]['document_sha256']);
        $this->assertSame($photo->id, $act[0]['paper_sheet_document_id']);
        $this->assertSame([], $notified, 'nobody signed with their signature');
        $this->assertSame(["État des lieux d'entrée"], $sent);
        $this->assertTrue($result['sent']);

        // The sheet is the signatures: it is not deleted either.
        try {
            $this->documentService()->delete($photo);
            $this->fail('the photo of a signed sheet must not be deleted');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertStringContainsString("la feuille signée d'un état des lieux", $e->getMessage());
        }
        $this->assertNotNull($this->documentService()->find($photo->id));
    }

    /**
     * A sheet whose photo cannot be read back refuses the validation in
     * French, before anything is frozen or filed.
     */
    public function testAPaperSheetThatCannotBeReadValidatesNothing(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();
        $documents = (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn();

        try {
            $this->realService()->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::onPaper(new PaperSheet(424242, $account)),
                null,
                $this->at('2027-07-01 15:30')
            );
            $this->fail('a sheet that cannot be read must refuse the validation');
        } catch (\Modules\Rental\Service\RentalException $e) {
            $this->assertSame(RentalInventoryValidationService::SHEET_UNREADABLE, $e->getMessage());
        }

        $this->assertSame([], $this->stay->inventoryValidations($booking->id));
        $this->assertSame($documents, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn());
    }

    /**
     * Signed on paper, no electronic signature is ever put on the PDF nor
     * « remembered », even when one is handed in: the two modes never mix.
     */
    public function testAnElectronicSignatureIsNeverTakenWithAnInventorySignedOnPaper(): void
    {
        $account = $this->sessionAccount();
        $booking = $this->bookingWithAnInventory();

        try {
            $this->realService()->validate(
                $booking,
                $this->asset(),
                ReadingPhase::ARRIVAL,
                1,
                InventorySigning::onPaper(new PaperSheet($this->storedPhoto(32), $account)),
                $this->managerSignature($account, true),
                $this->at('2027-07-01 15:30')
            );
            $this->fail('a signature handed in with a paper sheet must be refused');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $this->stay->inventoryValidations($booking->id));
        $this->assertFalse($this->signatures->has($account), 'nothing remembered');
    }

    /**
     * « Imprimer la liste à vérifier »: a PDF of the phase's checklist, its
     * items with their reference and a box, the meters, the free lines and
     * the two frames — nothing stored.
     */
    public function testTheBlankChecklistIsAPdfOfThePhase(): void
    {
        $booking = $this->bookingWithAnInventory();
        $this->stay->addMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $files = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

        $pdf = $this->realService()->blankSheet($booking, $this->asset(), ReadingPhase::DEPARTURE);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $text = (string) preg_replace('/\s+/u', ' ', (new Parser())->parseContent($pdf)->getText());
        $this->assertStringContainsString("État des lieux de sortie — liste à vérifier", $text);
        $this->assertStringContainsString("Cochez ce qui est conforme", $text);
        $this->assertStringContainsString('Clés', $text);
        $this->assertStringContainsString('Électricité (kWh)', $text);
        $this->assertStringContainsString('Incidents et remarques', $text);
        $this->assertStringContainsString('Le gestionnaire', $text);
        $this->assertSame($files, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    /**
     * The service with the real dompdf and the real renderer; the PDF is
     * caught where it would be filed.
     */
    private function validationService(): RentalInventoryValidationService
    {
        $documents = $this->createStub(RentalDocumentService::class);
        $documents->method('attachPdf')->willReturnCallback(
            function (RentalBooking $booking, string $pdf): RentalDocument {
                $this->filedPdf = $pdf;

                return new RentalDocument(
                    id: 41,
                    bookingId: $booking->id,
                    fileId: 141,
                    type: DocumentType::INVENTORY,
                    version: 1,
                    isForRenter: true,
                    originalName: 'etat-des-lieux.pdf',
                    sizeBytes: strlen($pdf),
                    sentAt: null,
                    createdByMemberId: 1,
                    createdAt: new \DateTimeImmutable('2027-07-04 11:30')
                );
            }
        );
        // Where attachPdf() would have written it: the fingerprint is read
        // from the file. No e-mail goes (the mail service is a stub).
        $documents->method('absolutePath')->willReturnCallback(function (): string {
            $path = $this->storagePath . '/filed.pdf';
            file_put_contents($path, (string) $this->filedPdf);

            return $path;
        });

        return new RentalInventoryValidationService(
            $this->stay,
            $documents,
            new RentalDocumentRepository($this->pdo),
            $this->createStub(RentalBookingMailService::class),
            new DocumentPdfService(),
            $this->createStub(\Core\Config\SettingService::class),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new InventoryPhotoRenderer($this->fileRepository, $this->storagePath),
            $this->signatures,
            $this->journal
        );
    }

    /**
     * A photo as the controller stores one: a 2 400 × 1 800 JPEG at
     * quality 85. Its pixels are a field of random colours enlarged — the
     * smooth gradients and the texture of a photograph, so it compresses
     * like one (about 1 Mo stored, 250 Ko at 1 600 px) — and a different
     * seed gives a different image, which dompdf cannot share between two
     * photos.
     */
    private function storedPhoto(int $seed): int
    {
        mt_srand($seed);
        $field = imagecreatetruecolor(200, 150);
        for ($y = 0; $y < 150; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($field, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
            }
        }
        $photo = imagecreatetruecolor(2400, 1800);
        imagecopyresampled($photo, $field, 0, 0, 0, 0, 2400, 1800, 200, 150);
        ob_start();
        imagejpeg($photo, null, 85);
        $jpeg = (string) ob_get_clean();
        mt_srand();

        $relative = 'rental/photo-' . $seed . '-' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($this->storagePath . '/' . $relative, $jpeg);

        return $this->fileRepository->create(
            $relative, 'photo.jpg', 'image/jpeg', strlen($jpeg), 'identified', 'rental', null
        );
    }

    /** How many JPEG image objects the PDF embeds. */
    private function embeddedJpegCount(string $pdf): int
    {
        return preg_match_all('#/Subtype\s*/Image.{0,200}?/Filter\s*/DCTDecode|/Filter\s*/DCTDecode.{0,200}?/Subtype\s*/Image#s', $pdf);
    }

    /**
     * The validation the older tests make: the renter absent, the
     * manager's signature traced, account 1.
     */
    private function signWith(
        RentalInventoryValidationService $service,
        RentalBooking $booking,
        ReadingPhase $phase,
        \DateTimeImmutable $at
    ): void {
        $service->validate(
            $booking,
            $this->asset(),
            $phase,
            1,
            InventorySigning::without(RenterSigning::ABSENT),
            new AccountSignature(1, $this->drawnSignature(), \Core\Signature\SignatureOrigin::DrawnNow, false, 'Anne Dupont'),
            $at
        );
    }

    /**
     * The arrival frozen as its own validation leaves it — the departure
     * starts only then (#989, lot G: an inventory is never ticked).
     */
    private function arrivalValidated(RentalBooking $booking): void
    {
        $this->assertTrue($this->stay->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->at('2027-07-01 15:30'), 1));
    }

    /** The account the session would hold: Anne Dupont, with no signature kept. */
    private function sessionAccount(): int
    {
        $account = $this->accounts->create('anne@test.be');
        $this->accounts->updateProfile($account->id, 'Anne', 'Dupont');

        return $account->id;
    }

    /** As the controller reads the manager's fields: a drawing, « Mémoriser » or not. */
    private function managerSignature(int $account, bool $remember = false): AccountSignature
    {
        return $this->signatures->forSigning(
            $account,
            new SignatureSubmission(false, $this->drawingDataUrl(300, 100), null, $remember)
        );
    }

    /** As the controller reads the renter's fields: a drawing and the typed name. */
    private function witness(string $typedName): WitnessSignature
    {
        return $this->signatures->witness(new SignatureSubmission(false, $this->drawingDataUrl(360, 120)), $typedName);
    }

    private function drawnSignature(): \Core\Signature\SignaturePng
    {
        return \Core\Signature\SignatureImage::fromDataUrl($this->drawingDataUrl(300, 100));
    }

    /** A canvas export, with a stroke so two of them differ. */
    private function drawingDataUrl(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imageline($image, 10, (int) ($height / 2), $width - 10, 10, (int) imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** A booking whose checklist has one line, checked. */
    private function bookingWithAnInventory(): RentalBooking
    {
        $booking = $this->createBooking();
        $this->stay->addInventoryItem($this->assetId, 'Clés');
        $this->stay->snapshotInventory($booking, $this->assetId);

        return $booking;
    }

    private function documentService(): RentalDocumentService
    {
        return new RentalDocumentService(
            new RentalDocumentRepository($this->pdo),
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new \Core\Security\HtmlSanitizer(),
            $this->createStub(\Core\Config\SettingService::class),
            $this->journal,
            $this->storagePath
        );
    }

    /** The service as public/index.php wires it, the PDF really filed on disk. */
    private function realService(
        ?RentalBookingMailService $mail = null,
        ?NotificationService $notifications = null,
        ?DocumentPdfService $pdf = null
    ): RentalInventoryValidationService {
        return new RentalInventoryValidationService(
            $this->stay,
            $this->documentService(),
            new RentalDocumentRepository($this->pdo),
            $mail ?? $this->createStub(RentalBookingMailService::class),
            $pdf ?? new DocumentPdfService(),
            $this->createStub(\Core\Config\SettingService::class),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new InventoryPhotoRenderer($this->fileRepository, $this->storagePath),
            $this->signatures,
            $this->journal,
            $notifications
        );
    }

    /**
     * The booking's history, decrypted.
     *
     * @return list<array{summary: ?string, to: ?string, account: ?int}>
     */
    private function historyOf(RentalBooking $booking): array
    {
        $rows = $this->pdo->query(
            "SELECT * FROM entity_changes WHERE entity_type = 'rental_booking' AND entity_id = " . $booking->id
        )->fetchAll(\PDO::FETCH_ASSOC);

        $clear = fn(mixed $value): ?string => $value !== null
            ? $this->encryption->decrypt((string) $value, 'entity_changes.value')
            : null;

        return array_map(fn(array $row): array => [
            'summary' => $clear($row['summary']),
            'to' => $clear($row['to_value']),
            'account' => $row['actor_user_account_id'] !== null ? (int) $row['actor_user_account_id'] : null,
        ], $rows);
    }

    /**
     * The contexts of the journal lines of one type.
     *
     * @return list<array<string, mixed>>
     */
    private function journalContexts(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id');
        $stmt->execute([$type]);

        return array_map(
            static fn(mixed $context): array => (array) json_decode((string) $context, true),
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * Every value of every table of the test database, as text — and each
     * one that decrypts under a context the code encrypts with, in clear
     * as well. `$decrypted` counts those.
     *
     * @return \Generator<string, string>
     */
    private function everyStoredValue(int &$decrypted): \Generator
    {
        $contexts = self::encryptionContexts();
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('rental_inventory_validations', $tables);
        foreach ($tables as $table) {
            foreach ($this->pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(\PDO::FETCH_ASSOC) as $i => $row) {
                foreach ($row as $column => $value) {
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $where = $table . '.' . $column . '#' . $i;
                    yield $where => $value;
                    foreach ([...$contexts, $table . '.' . $column] as $context) {
                        try {
                            $plain = $this->encryption->decrypt($value, $context);
                        } catch (\Throwable) {
                            continue;
                        }
                        $decrypted++;
                        yield $where . ' (' . $context . ')' => $plain;
                    }
                }
            }
        }
    }

    /**
     * Every context the code passes to `encrypt()`, read off the source:
     * a literal second argument, or a constant whose name says CONTEXT.
     *
     * @return list<string>
     */
    private static function encryptionContexts(): array
    {
        $root = dirname(__DIR__, 4);
        $contexts = [''];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/core', \FilesystemIterator::SKIP_DOTS));
        $sources = iterator_to_array($files);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/modules', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $sources[] = $file;
        }
        foreach ($sources as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/crypt\\([^;]*?,\\s*'([a-z0-9_.]+)'\\s*\\)/i", $source, $literal);
            preg_match_all("/const\\s+[A-Z_]*CONTEXT[A-Z_]*\\s*=\\s*'([a-z0-9_.]+)'/", $source, $constant);
            array_push($contexts, ...$literal[1], ...$constant[1]);
        }

        return array_values(array_unique($contexts));
    }

    private function createBooking(): RentalBooking
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-A2B3C4',
            '2027-07-01',
            '2027-07-04',
            1,
            40,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            new PriceQuote(
                lines: [new PriceLine('3 nuits', 3, 12000, 36000, PriceLine::RULE_BASE)],
                totalCents: 36000,
                nights: 3,
                persons: 40,
                quantity: 3,
                billingUnit: BillingUnit::PER_NIGHT
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

    private function asset(): RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }
}
