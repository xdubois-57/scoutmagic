<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Security\EncryptionService;
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
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\InventoryPhotoRenderer;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalDocumentService;
use Modules\Rental\Service\RentalInventoryValidationService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\ReadingPhase;
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

    /** The PDF the last validation filed, as dompdf wrote it. */
    private ?string $filedPdf = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
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
        foreach (glob($this->storagePath . '/rental/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->storagePath . '/rental');
        @rmdir($this->storagePath);
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
        $this->stay->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::DEPARTURE, '1342,5', $this->at('2027-07-04 10:00'),
            $this->storedPhoto(2), null, 1, true
        );
        $this->stay->reportIncident(
            $booking, 'Porte du réfectoire forcée', null, array_map($this->storedPhoto(...), [3, 4, 5, 6, 7]), 1
        );
        $this->stay->reportIncident($booking, 'Vitre cassée', null, array_map($this->storedPhoto(...), [8, 9, 10]), 1);

        $this->validationService()->validate(
            $booking, $this->asset(), ReadingPhase::DEPARTURE, 1, 'Anne Dupont', $this->at('2027-07-04 11:30'), true
        );

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
        $before = glob($this->storagePath . '/rental/*') ?: [];
        $files = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

        $this->validationService()->validate(
            $booking, $this->asset(), ReadingPhase::DEPARTURE, 1, 'Anne Dupont', $this->at('2027-07-04 11:30'), true
        );

        $this->assertSame($before, glob($this->storagePath . '/rental/*') ?: []);
        $this->assertSame($files, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertStringContainsString('Rayure (photo 1)', (new Parser())->parseContent((string) $this->filedPdf)->getText());
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

        $this->validationService()->validate(
            $booking, $this->asset(), ReadingPhase::ARRIVAL, 1, 'Anne Dupont', $this->at('2027-07-01 15:30')
        );

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

        $this->validationService()->validate(
            $booking, $this->asset(), ReadingPhase::DEPARTURE, 1, 'Anne Dupont', $this->at('2027-07-04 11:30'), true
        );

        $this->assertStringContainsString('Rayure (photo 1)', (new Parser())->parseContent((string) $this->filedPdf)->getText());
        $this->assertSame(1, $this->embeddedJpegCount((string) $this->filedPdf));
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /**
     * The service with the real dompdf and the real renderer; the PDF is
     * caught where it would be filed, and no e-mail goes (no path).
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
        $documents->method('absolutePath')->willReturn(null);

        return new RentalInventoryValidationService(
            $this->stay,
            $documents,
            $this->createStub(RentalBookingMailService::class),
            new DocumentPdfService(),
            $this->createStub(\Core\Config\SettingService::class),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new InventoryPhotoRenderer($this->fileRepository, $this->storagePath)
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
