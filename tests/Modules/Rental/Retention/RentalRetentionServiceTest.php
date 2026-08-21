<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Retention;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Repository\RentalAggregateRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalReminderRepository;
use Modules\Rental\Service\RentalRetentionService;
use Modules\Rental\Service\RentalStatisticsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Deleting a booking once its retention has run out (§6.35), and keeping
 * the year's figures honest afterwards (§6.34).
 *
 * The two halves are tested together on purpose: they only make sense
 * together. A purge that deleted everything would be correct and would
 * silently zero a unit's yearly revenue; an aggregate that kept anything
 * identifying would keep the statistics and defeat the deletion.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalRetentionServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private RentalDocumentRepository $documentRepository;
    private RentalAggregateRepository $aggregateRepository;
    private RentalRetentionService $service;
    private FileRepository $fileRepository;
    private int $assetId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->documentRepository = new RentalDocumentRepository($this->pdo);
        $this->aggregateRepository = new RentalAggregateRepository($this->pdo);
        $this->fileRepository = new FileRepository($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'rental',
            RentalRetentionService::SETTING_RETENTION_YEARS,
            '7',
            'number',
            'Rétention',
            'Années.',
        ]);

        $this->storagePath = sys_get_temp_dir() . '/rental-retention-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/rental/documents', 0777, true);

        $this->service = new RentalRetentionService(
            $this->bookingRepository,
            $this->documentRepository,
            $this->aggregateRepository,
            new RentalReminderRepository($this->pdo),
            $settingService,
            new JournalService(new JournalRepository($this->pdo)),
            $this->pdo,
            $this->fileRepository,
            null,
            $this->storagePath
        );

        $this->assetId = (new RentalAssetRepository($this->pdo, $this->encryption))
            ->create('Local', 'Local Saint-Georges', 'local-saint-georges', 60, 1, '18:00', '11:00', null, true);

        // Accounting years, so the cutoff has something to resolve against.
        foreach ([['2017-2018', '2017-09-01', '2018-08-31'], ['2018-2019', '2018-09-01', '2019-08-31']] as $year) {
            $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
            $stmt->execute($year);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/rental/documents/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/rental/documents');
        @rmdir($this->storagePath . '/rental');
        @rmdir($this->storagePath);
    }

    private function createBooking(
        string $reference,
        string $arrival,
        string $departure,
        BookingStatus $status = BookingStatus::CLOSED,
        ?int $agreedCents = 45000
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $this->assetId,
            $reference,
            $arrival,
            $departure,
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => 'Les Scouts de Nulle Part',
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable($arrival . ' 10:00:00')
        );

        $this->bookingRepository->setStatus($created['id'], $status, new \DateTimeImmutable($arrival . ' 10:00:00'));

        if ($agreedCents !== null) {
            $this->bookingRepository->setAgreedPrice($created['id'], new \Modules\Rental\Pricing\PriceQuote(
                lines: [],
                totalCents: $agreedCents,
                nights: 3,
                persons: 20,
                quantity: 1,
                billingUnit: \Modules\Rental\Pricing\BillingUnit::FLAT_STAY
            ));
        }

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function attachDocument(RentalBooking $booking): int
    {
        $relative = 'rental/documents/' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($this->storagePath . '/' . $relative, '%PDF-1.4');

        $fileId = $this->fileRepository->create(
            $relative,
            'contrat.pdf',
            'application/pdf',
            8,
            'identified',
            'rental',
            null
        );

        $this->documentRepository->create($booking->id, $fileId, DocumentType::CONTRACT, 1, false, null, null);

        return $fileId;
    }

    private function countBookings(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rental_bookings')->fetchColumn();
    }

    // ── The cutoff (§6.35) ──────────────────────────────────────────────

    public function testTheCutoffIsAnAccountingYearsCloseNotADateMinusSevenYears(): void
    {
        // Every letting of one financial year purges together — which is
        // what makes the rule explicable to a unit and auditable later.
        $this->assertSame('2018-08-31', $this->service->cutoffDate(new \DateTimeImmutable('2026-01-01')));
    }

    public function testNothingIsPurgedBeforeAnyYearHasClosedLongEnoughAgo(): void
    {
        $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');

        $this->assertSame(0, $this->service->purge(new \DateTimeImmutable('2020-01-01')));
        $this->assertSame(1, $this->countBookings());
    }

    public function testARetentionOfZeroFallsBackToTheDefault(): void
    {
        // Nobody means "delete everything on the next tick" by typing 0.
        (new SettingService(new SettingRepository($this->pdo)))
            ->set(RentalRetentionService::SETTING_RETENTION_YEARS, '0', 'rental');

        $this->assertSame(RentalRetentionService::DEFAULT_RETENTION_YEARS, $this->service->retentionYears());
    }

    // ── Deletion is total ───────────────────────────────────────────────

    public function testAnOutOfRetentionBookingIsDeletedEntirely(): void
    {
        $booking = $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');
        $this->attachDocument($booking);

        $this->assertSame(1, $this->service->purge(new \DateTimeImmutable('2026-01-01')));

        $this->assertSame(0, $this->countBookings());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_documents')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testTheFileOnDiskGoesToo(): void
    {
        // A row deleted while the bytes stay on disk is not a deletion.
        $booking = $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');
        $fileId = $this->attachDocument($booking);
        $record = $this->fileRepository->findById($fileId);
        $this->assertNotNull($record);
        $path = $this->storagePath . '/' . $record->relativePath;
        $this->assertFileExists($path);

        $this->service->purge(new \DateTimeImmutable('2026-01-01'));

        $this->assertFileDoesNotExist($path);
    }

    public function testARecentBookingIsUntouched(): void
    {
        $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');
        $this->createBooking('LOC-2025-0001', '2025-07-01', '2025-07-04');

        $this->service->purge(new \DateTimeImmutable('2026-01-01'));

        $remaining = $this->bookingRepository->findAllForAssets([$this->assetId]);
        $this->assertCount(1, $remaining);
        $this->assertSame('LOC-2025-0001', $remaining[0]->reference);
    }

    public function testARefusedRequestIsPurgedOnTheSameTerms(): void
    {
        // It holds the same enquirer's name and address as a completed
        // stay, and there is no reason to keep one longer than the other.
        $this->createBooking('LOC-2018-0002', '2018-07-01', '2018-07-04', BookingStatus::REFUSED, null);

        $this->assertSame(1, $this->service->purge(new \DateTimeImmutable('2026-01-01')));
        $this->assertSame(0, $this->countBookings());
    }

    // ── What survives (§6.35) ───────────────────────────────────────────

    public function testOneAnonymousAggregateRowSurvives(): void
    {
        $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');

        $this->service->purge(new \DateTimeImmutable('2026-01-01'));

        $rows = $this->pdo->query('SELECT * FROM rental_booking_aggregates')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('2018-07', $rows[0]['stay_month']);
        $this->assertSame(3, (int) $rows[0]['occupied_days']);
        $this->assertSame(45000, (int) $rows[0]['amount_cents']);
    }

    public function testTheAggregateCarriesNothingThatCouldIdentifyAnybody(): void
    {
        // The whole point: no booking id, no reference, no token, no name,
        // no file. If any of those were here, the "deletion" would not be
        // one.
        $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');
        $this->service->purge(new \DateTimeImmutable('2026-01-01'));

        $row = $this->pdo->query('SELECT * FROM rental_booking_aggregates LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $serialised = json_encode($row, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialised);
        $this->assertStringNotContainsString('Jeanne', $serialised);
        $this->assertStringNotContainsString('jeanne@example.be', $serialised);
        $this->assertStringNotContainsString('LOC-2018-0001', $serialised);
        $this->assertStringNotContainsString('Scouts de Nulle Part', $serialised);

        $this->assertSame(
            ['id', 'asset_id', 'stay_month', 'occupied_days', 'amount_cents', 'scout_year_id', 'created_at'],
            array_keys($row)
        );
    }

    public function testTheJournalEntryIsNotTheLastSurvivingTraceOfWhatWasDeleted(): void
    {
        $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');
        $this->service->purge(new \DateTimeImmutable('2026-01-01'));

        $rows = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'rental_bookings_purged'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $text = (string) $rows[0]['description'] . (string) $rows[0]['context'];
        $this->assertStringNotContainsString('Jeanne', $text);
        $this->assertStringNotContainsString('LOC-2018-0001', $text);
    }

    // ── The statistics keep working afterwards (§6.34) ──────────────────

    private function statistics(): RentalStatisticsService
    {
        return new RentalStatisticsService($this->bookingRepository, $this->aggregateRepository);
    }

    public function testTheYearsFiguresCountLiveBookings(): void
    {
        $this->createBooking('LOC-2025-0001', '2025-07-01', '2025-07-04');

        $stats = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2025-12-01'));

        $this->assertSame(3, $stats['occupied_days']);
        $this->assertSame(45000, $stats['revenue_cents']);
    }

    public function testTheYearsRevenueDoesNotDropToZeroOnTheMorningOfThePurge(): void
    {
        // The failure this whole design exists to prevent.
        $this->createBooking('LOC-2018-0001', '2018-07-01', '2018-07-04');

        $before = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2018-12-01'));
        $this->service->purge(new \DateTimeImmutable('2026-01-01'));
        $after = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2018-12-01'));

        $this->assertSame($before['revenue_cents'], $after['revenue_cents']);
        $this->assertSame($before['occupied_days'], $after['occupied_days']);
    }

    public function testNothingIsCountedTwiceWhileTheBookingIsStillAlive(): void
    {
        // The aggregate is written AS the booking is deleted, so a live
        // booking never has one.
        $this->createBooking('LOC-2025-0001', '2025-07-01', '2025-07-04');

        $stats = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2025-12-01'));

        $this->assertSame(45000, $stats['revenue_cents']);
        $this->assertSame(0, $this->aggregateRepository->countForAsset($this->assetId));
    }

    public function testARefusedRequestIsCountedAsPendingNeverAsRevenue(): void
    {
        $this->createBooking('LOC-2025-0002', '2025-08-01', '2025-08-04', BookingStatus::REFUSED, 45000);

        $stats = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2025-12-01'));

        $this->assertSame(0, $stats['revenue_cents']);
        $this->assertSame(0, $stats['occupied_days']);
    }

    public function testARequestAwaitingAnAnswerIsCountedAsPending(): void
    {
        $this->createBooking('LOC-2025-0003', '2025-08-01', '2025-08-04', BookingStatus::RECEIVED, null);

        $stats = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2025-12-01'));

        $this->assertSame(1, $stats['pending']);
    }

    public function testAStayInAnotherYearIsNotCountedInThisOne(): void
    {
        $this->createBooking('LOC-2024-0001', '2024-07-01', '2024-07-04');

        $stats = $this->statistics()->forAsset($this->assetId, new \DateTimeImmutable('2025-12-01'));

        $this->assertSame(0, $stats['revenue_cents']);
    }
}
