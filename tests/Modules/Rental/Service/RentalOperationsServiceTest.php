<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\ChangeRequestStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Core\Audit\AuditSource;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBlockService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Operating a booking (§6.12, §6.15–§6.18).
 *
 * The parts worth testing hardest are the ones that only misbehave when two
 * people act at once: two managers confirming for the same week, three
 * bookings of five tents against a stock of twelve, two clicks on the same
 * change request. Each of those is written here as the interleaving that
 * actually happens, not as a mock of one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalOperationsServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalAssetRepository $assetRepository;
    private RentalBookingRepository $bookingRepository;
    private BookingAudit $bookingAudit;
    private RentalBookingCommentRepository $commentRepository;
    private RentalChangeRequestRepository $changeRequestRepository;
    private RentalBlockRepository $blockRepository;
    private RentalBlockService $blockService;
    private RentalPricingService $pricingService;
    private RentalAvailabilityService $availabilityService;
    private RentalOperationsService $service;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $this->encryption);
        $this->commentRepository = new RentalBookingCommentRepository($this->pdo, $this->encryption);
        $this->changeRequestRepository = new RentalChangeRequestRepository($this->pdo, $this->encryption);
        $this->blockRepository = new RentalBlockRepository($this->pdo);
        $this->blockService = new RentalBlockService($this->blockRepository, $journal);

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );

        $this->availabilityService = $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            // Both providers, exactly as public/index.php wires them: a test
            // that left the blocks out would never see a block and a booking
            // compete for the same day.
            [new RentalBookingService($this->bookingRepository, $journal), $this->blockRepository]
        );

        $this->service = new RentalOperationsService(
            $this->bookingRepository,
            $this->bookingAudit,
            $this->commentRepository,
            $this->changeRequestRepository,
            $availability,
            $this->pricingService,
            new QuoteEditor(),
            $journal
        );

        $this->assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            null,
            null,
            null,
            true
        );
        $this->pricingService->saveAssetPricing($this->assetId, 'per_night', 12000, null, null);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function asset(?int $assetId = null): RentalAsset
    {
        $asset = $this->assetRepository->findById($assetId ?? $this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    private function stockAsset(int $quantity): int
    {
        $id = $this->assetRepository->create(
            'Tente',
            'Tentes canadiennes',
            'tentes',
            null,
            $quantity,
            null,
            null,
            null,
            true
        );
        $this->pricingService->saveAssetPricing($id, 'per_night', 1500, null, null);

        return $id;
    }

    private function createBooking(
        string $reference = 'LOC-2027-0001',
        string $arrival = '2027-07-01',
        string $departure = '2027-07-04',
        ?int $assetId = null,
        int $units = 1
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $assetId ?? $this->assetId,
            $reference,
            $arrival,
            $departure,
            $units,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => strtolower($reference) . '@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => null,
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
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function reload(RentalBooking $booking): RentalBooking
    {
        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-02-01 10:00:00');
    }

    // ── Lifecycle transitions ───────────────────────────────────────────

    public function testAValidTransitionMovesTheBooking(): void
    {
        $booking = $this->createBooking();

        $this->service->changeStatus($booking, BookingStatus::REVIEWING, 1, $this->now());

        $this->assertSame(BookingStatus::REVIEWING, $this->reload($booking)->status);
    }

    public function testAnInvalidTransitionIsRefusedWithAReasonAndChangesNothing(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/nouvelle demande/');

        $this->service->changeStatus($this->reload($booking), BookingStatus::REVIEWING, 1, $this->now());
    }

    public function testConfirmationCannotGoThroughTheOrdinaryTransitionPath(): void
    {
        // It has to re-check availability inside a transaction, so routing
        // it through changeStatus() would skip the only check that matters.
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/Confirmer/');

        $this->service->changeStatus($this->createBooking(), BookingStatus::CONFIRMED, 1, $this->now());
    }

    public function testARefusalReleasesTheDatesAndDropsAnyHold(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable('2027-03-01'), HoldOrigin::AUTOMATIC);

        $this->service->changeStatus($this->reload($booking), BookingStatus::REFUSED, 1, $this->now());

        $fresh = $this->reload($booking);
        $this->assertSame(BookingStatus::REFUSED, $fresh->status);
        $this->assertNull($fresh->holdUntil);
        $this->assertFalse($fresh->status->occupiesTheAsset());
    }

    public function testAStatusChangeIsRecordedInTheBookingsOwnHistory(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::REVIEWING, 42, $this->now());

        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id);
        $this->assertCount(1, $history);
        $this->assertSame(BookingAudit::STATUS_CHANGED, $history[0]->fieldKey);
        $this->assertSame('Demande reçue', $history[0]->fromValue);
        $this->assertSame("En cours d'examen", $history[0]->toValue);
        // A person moved it, even though this test wires no resolver to
        // say which account they log in with.
        $this->assertSame(AuditSource::Human, $history[0]->source);
    }

    public function testTheHistoryCarriesNoRenterIdentity(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::REVIEWING, 1, $this->now());
        $this->service->addComment($this->reload($booking), 1, 'Le groupe a laissé la cuisine sale.');

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM entity_changes')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringNotContainsString('Jeanne Martin', $raw);
        $this->assertStringNotContainsString('@example.be', $raw);
        $this->assertStringNotContainsString('cuisine sale', $raw);
    }

    public function testNoRenterIdentityReachesTheJournalOnAStatusChange(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::REVIEWING, 1, $this->now());

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringContainsString('LOC-2027-0001', $journal);
        $this->assertStringNotContainsString('Jeanne Martin', $journal);
        $this->assertStringNotContainsString('+32 495 11 22 33', $journal);
    }

    // ── Confirmation and concurrency ────────────────────────────────────

    public function testConfirmingABookingWhoseDatesAreStillFreeSucceeds(): void
    {
        $booking = $this->createBooking();

        $this->service->confirm($booking, $this->asset(), 1, $this->now());

        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking)->status);
    }

    public function testConfirmingClearsTheHoldWhichHasDoneItsJob(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable('2027-03-01'), HoldOrigin::MANAGER);

        $this->service->confirm($this->reload($booking), $this->asset(), 1, $this->now());

        $this->assertNull($this->reload($booking)->holdUntil);
    }

    public function testABookingNeverCountsItselfAsOccupyingItsOwnDates(): void
    {
        // Without the self-exclusion every confirmation would fail: the
        // booking already holds its own week.
        $booking = $this->createBooking();

        $this->service->confirm($booking, $this->asset(), 1, $this->now());

        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking)->status);
    }

    public function testTwoBookingsForTheSameWeekCannotBothBeConfirmed(): void
    {
        $first = $this->createBooking('LOC-2027-0001');
        $second = $this->createBooking('LOC-2027-0002');

        $this->service->confirm($first, $this->asset(), 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/plus disponibles/');

        $this->service->confirm($second, $this->asset(), 2, $this->now());
    }

    public function testTheSecondConfirmationLeavesTheLoserUntouched(): void
    {
        $first = $this->createBooking('LOC-2027-0001');
        $second = $this->createBooking('LOC-2027-0002');
        $this->service->confirm($first, $this->asset(), 1, $this->now());

        try {
            $this->service->confirm($second, $this->asset(), 2, $this->now());
            $this->fail('The second confirmation must be refused.');
        } catch (RentalException) {
            // Expected.
        }

        $this->assertSame(BookingStatus::RECEIVED, $this->reload($second)->status);
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($first)->status);
    }

    public function testAStaleCopyOfTheBookingCannotWinOverALaterChange(): void
    {
        // The manager's page was rendered, somebody else cancelled the
        // booking, then the manager clicked. The stale copy still says
        // "received"; the compare-and-set is what refuses it.
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        $this->expectException(RentalException::class);

        $this->service->confirm($booking, $this->asset(), 2, $this->now());
    }

    public function testAStaleCopyCannotWinOnAnOrdinaryTransitionEither(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::REVIEWING, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/Rechargez la page/');

        // $booking is the pre-change copy: it still believes it is
        // "received", so the compare-and-set matches no row.
        $this->service->changeStatus($booking, BookingStatus::INFO_REQUESTED, 2, $this->now());
    }

    public function testStockIsNotOverrunByConcurrentConfirmations(): void
    {
        // Twelve tents, three bookings of five.
        $stockId = $this->stockAsset(12);
        $asset = $this->asset($stockId);

        $a = $this->createBooking('LOC-2027-0011', '2027-08-01', '2027-08-05', $stockId, 5);
        $b = $this->createBooking('LOC-2027-0012', '2027-08-01', '2027-08-05', $stockId, 5);
        $c = $this->createBooking('LOC-2027-0013', '2027-08-01', '2027-08-05', $stockId, 5);

        $this->service->confirm($a, $asset, 1, $this->now());
        $this->service->confirm($b, $asset, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/quantité suffisante/');

        $this->service->confirm($c, $asset, 1, $this->now());
    }

    public function testStockLeftOverIsStillBookable(): void
    {
        $stockId = $this->stockAsset(12);
        $asset = $this->asset($stockId);

        $this->service->confirm(
            $this->createBooking('LOC-2027-0011', '2027-08-01', '2027-08-05', $stockId, 5),
            $asset,
            1,
            $this->now()
        );

        // Seven left; a booking of seven must go through.
        $this->service->confirm(
            $this->createBooking('LOC-2027-0012', '2027-08-01', '2027-08-05', $stockId, 7),
            $asset,
            1,
            $this->now()
        );

        $this->assertSame(2, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM rental_bookings WHERE status = 'confirmed'"
        )->fetchColumn());
    }

    public function testAManualBlockOnTheSamePeriodStopsAConfirmation(): void
    {
        $this->blockService->create($this->assetId, '2027-07-01', '2027-07-04', 1, 'Chantier toiture', 1);

        $this->expectException(RentalException::class);

        $this->service->confirm($this->createBooking(), $this->asset(), 1, $this->now());
    }

    // ── Manager's option (§6.14) ────────────────────────────────────────

    public function testAnOptionIsStoredAsAManagerHold(): void
    {
        $booking = $this->createBooking();

        $this->service->placeOption($booking, new \DateTimeImmutable('2027-03-22 18:00:00'), 7, $this->now());

        $fresh = $this->reload($booking);
        $this->assertSame(HoldOrigin::MANAGER, $fresh->holdOrigin);
        $this->assertSame('2027-03-22 18:00:00', $fresh->holdUntil?->format('Y-m-d H:i:s'));
    }

    public function testAnOptionInThePastIsRefused(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/futur/');

        $this->service->placeOption(
            $this->createBooking(),
            new \DateTimeImmutable('2027-01-01 10:00:00'),
            1,
            $this->now()
        );
    }

    public function testAnOptionOnAFinalBookingIsRefused(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::REFUSED, 1, $this->now());

        $this->expectException(RentalException::class);

        $this->service->placeOption(
            $this->reload($booking),
            new \DateTimeImmutable('2027-03-01'),
            1,
            $this->now()
        );
    }

    public function testClearingAHoldIsRecordedAndIsANoOpWhenThereIsNone(): void
    {
        $booking = $this->createBooking();
        $this->service->clearHold($booking, 1);
        $this->assertSame([], RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id));

        $this->service->placeOption($booking, new \DateTimeImmutable('2027-03-01'), 1, $this->now());
        $this->service->clearHold($this->reload($booking), 1);

        $this->assertNull($this->reload($booking)->holdUntil);
        $this->assertCount(2, RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id));
    }

    // ── Price (§6.12) ───────────────────────────────────────────────────

    public function testTheWorkingQuoteStartsFromTheLiveTariffWhenTheBookingHasNone(): void
    {
        $quote = $this->service->workingQuote($this->createBooking(), $this->asset());

        // Three nights at 120,00 €.
        $this->assertSame(36000, $quote->totalCents);
    }

    public function testEditingALineStoresTheAgreedPriceAndLeavesTheEstimateAlone(): void
    {
        $booking = $this->createBooking();
        $this->service->editPriceLine($booking, $this->asset(), 0, null, null, 30000, 1);

        $fresh = $this->reload($booking);
        $this->assertSame(30000, $fresh->agreedTotalCents);
        // The estimate was never written at creation here, and must stay
        // untouched either way: it is the record of what was shown, not a
        // working copy.
        $this->assertNull($fresh->estimatedPrice);
        $this->assertTrue($fresh->priceHasBeenAgreed());
    }

    public function testAddingAFreeLineChangesTheEffectiveTotal(): void
    {
        $booking = $this->createBooking();
        $this->service->addPriceLine($booking, $this->asset(), 'Remise exceptionnelle', 1, -5000, 1);

        $this->assertSame(31000, $this->reload($booking)->effectiveTotalCents());
    }

    public function testRemovingALineChangesTheEffectiveTotal(): void
    {
        $booking = $this->createBooking();
        $this->service->removePriceLine($booking, $this->asset(), 0, 1);

        $this->assertSame(0, $this->reload($booking)->effectiveTotalCents());
    }

    public function testAManualLineSurvivesARecalculationAfterTheDatesChange(): void
    {
        $booking = $this->createBooking();
        $this->service->editPriceLine($booking, $this->asset(), 0, null, null, 30000, 1);
        $this->service->addPriceLine($this->reload($booking), $this->asset(), 'Prêt remorque', 1, 5000, 1);

        // The stay grows by a night; the automatic lines must follow, the
        // negotiated ones must not.
        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-05', 1, 20);
        $this->service->recalculate($this->reload($booking), $this->asset(), 1);

        $quote = $this->service->workingQuote($this->reload($booking), $this->asset());
        $labels = array_map(static fn($line) => $line->label, $quote->lines);

        $this->assertContains('Prêt remorque', $labels);
        // 4 nights × 120,00 € recomputed + the two manual lines kept.
        $this->assertSame(48000 + 30000 + 5000, $quote->totalCents);
    }

    public function testEveryPriceChangeIsJournaledWithBothTotals(): void
    {
        $booking = $this->createBooking();
        $this->service->editPriceLine($booking, $this->asset(), 0, null, null, 30000, 1);

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringContainsString('rental_booking_price_changed', $journal);
        $this->assertStringContainsString('30000', $journal);
    }

    public function testThePriceHistoryShowsTheMovementInEuros(): void
    {
        $booking = $this->createBooking();
        $this->service->addPriceLine($booking, $this->asset(), 'Supplément', 1, 1000, 1);

        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id);
        $this->assertSame(BookingAudit::PRICE_CHANGED, $history[0]->fieldKey);
        $this->assertStringContainsString('€', (string) $history[0]->toValue);
    }

    // ── Internal comments (§6.6) ────────────────────────────────────────

    public function testAnInternalCommentIsStoredEncrypted(): void
    {
        $booking = $this->createBooking();
        $this->service->addComment($booking, 3, 'Attention : groupe déjà venu, cuisine laissée sale.');

        $raw = (string) json_encode(
            $this->pdo->query('SELECT * FROM rental_booking_comments')->fetchAll(\PDO::FETCH_ASSOC)
        );
        $this->assertStringNotContainsString('cuisine laissée sale', $raw);

        $comments = $this->commentRepository->findForBooking($booking->id);
        $this->assertSame('Attention : groupe déjà venu, cuisine laissée sale.', $comments[0]['body']);
        $this->assertSame(3, $comments[0]['author_member_id']);
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service->addComment($this->createBooking(), 1, "   \n  ");
    }

    // ── Change requests (§6.16) ─────────────────────────────────────────

    public function testARenterRequestChangesNothingByItself(): void
    {
        $booking = $this->createBooking();

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            'Nous préférons la semaine suivante.',
            null, $this->now()
        );

        $fresh = $this->reload($booking);
        $this->assertSame('2027-07-01', $fresh->arrivalDate);
        $this->assertSame('2027-07-04', $fresh->departureDate);
        $this->assertSame(BookingStatus::RECEIVED, $fresh->status);
    }

    public function testTheRentersMessageIsStoredEncrypted(): void
    {
        $booking = $this->createBooking();
        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            25,
            null,
            'Mon fils est allergique aux arachides.',
            null, $this->now()
        );

        $raw = (string) json_encode(
            $this->pdo->query('SELECT * FROM rental_change_requests')->fetchAll(\PDO::FETCH_ASSOC)
        );
        $this->assertStringNotContainsString('allergique', $raw);

        $requests = $this->changeRequestRepository->findForBooking($booking->id);
        $this->assertSame('Mon fils est allergique aux arachides.', $requests[0]->message);
    }

    public function testAChangeRequestRefusesADateThatIsNotOne(): void
    {
        // The renter's form posts raw text. An unparseable value used to
        // travel all the way to acceptance and throw a
        // DateMalformedStringException in the MANAGER's face — a 500 on
        // their page, caused by something a renter typed weeks earlier.
        $booking = $this->createBooking();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches("/n'est pas une date valide/");

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            'demain',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
    }

    public function testAChangeRequestRefusesACalendarDateThatDoesNotExist(): void
    {
        $booking = $this->createBooking();

        $this->expectException(RentalException::class);

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-02-30',
            '2027-03-02',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
    }

    public function testAChangeRequestRefusesADepartureBeforeTheArrival(): void
    {
        // Which is also what used to slip through isRangeFree() — a range
        // covering no day answered "available" on a fully booked asset.
        $booking = $this->createBooking();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/doit suivre/');

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-11',
            '2027-07-08',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
    }

    public function testAManagerAcceptingARenterRequestAppliesIt(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null, $this->now()
        );

        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->acceptChange(
            $request,
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            1,
            $this->now()
        );

        $fresh = $this->reload($booking);
        $this->assertSame('2027-07-08', $fresh->arrivalDate);
        $this->assertSame('2027-07-11', $fresh->departureDate);
    }

    public function testNobodyDecidesTheirOwnRequest(): void
    {
        // The asymmetry is what makes "a request never modifies the booking
        // silently" structurally true rather than a rule to remember.
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches("/l'autre partie/");

        $this->service->acceptChange(
            $request,
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            null,
            $this->now()
        );
    }

    public function testAManagerProposalIsDecidedByTheRenter(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::DATES,
            '2027-07-15',
            '2027-07-18',
            null,
            null,
            null,
            'Ces dates nous arrangeraient mieux.',
            5,
            $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->acceptChange(
            $request,
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            null,
            $this->now()
        );

        $this->assertSame('2027-07-15', $this->reload($booking)->arrivalDate);
    }

    /**
     * The refusal now arrives when the renter asks, not weeks later when a
     * manager presses « Accepter » (IT-03). Until then `requestChange()`
     * checked that the dates parsed and were in order and nothing else, so
     * a period already taken was recorded, queued, and refused in the
     * manager's face — the wrong person finding out at the wrong moment.
     */
    public function testADateChangeOntoTakenDatesIsRefusedWhenItIsASKED(): void
    {
        $other = $this->createBooking('LOC-2027-0002', '2027-07-08', '2027-07-11');
        $this->service->confirm($other, $this->asset(), 1, $this->now());

        $booking = $this->createBooking('LOC-2027-0003');

        try {
            $this->service->requestChange(
                $booking,
                $this->asset(),
                ChangeRequestOrigin::RENTER,
                ChangeRequestKind::DATES,
                '2027-07-08',
                '2027-07-11',
                null,
                null,
                null,
                'Ces dates nous arrangeraient mieux.',
                null, $this->now()
            );
            $this->fail('Asking for dates that are already taken must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('disponible', $e->getMessage());
        }

        // Nothing was recorded: a request that cannot be accepted is not a
        // request a manager should have to read.
        $this->assertSame([], $this->changeRequestRepository->findPendingForBooking($booking->id));
    }

    /**
     * And the acceptance-time check STAYS, because it answers a different
     * question: between a request and an answer, the dates can be taken by
     * somebody else, and only the check inside the lock sees that.
     */
    public function testDatesTakenAFTERTheRequestAreStillCaughtAtAcceptance(): void
    {
        $booking = $this->createBooking('LOC-2027-0003');

        // Asked while the period is free — this passes the new check.
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            'Ces dates nous arrangeraient mieux.',
            null, $this->now()
        );

        // Somebody else takes them in the meantime.
        $other = $this->createBooking('LOC-2027-0002', '2027-07-08', '2027-07-11');
        $this->service->confirm($other, $this->asset(), 1, $this->now());

        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        try {
            $this->service->acceptChange(
                $request,
                $booking,
                $this->asset(),
                ChangeRequestOrigin::MANAGER,
                1,
                $this->now()
            );
            $this->fail('Accepting a change onto taken dates must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('pas disponibles', $e->getMessage());
        }

        // Refused means nothing moved: neither the booking nor the request.
        $this->assertSame('2027-07-01', $this->reload($booking)->arrivalDate);
        $this->assertSame(
            ChangeRequestStatus::PENDING,
            $this->changeRequestRepository->findById($id)?->status
        );
    }

    public function testAcceptingACancellationRequestCancelsTheBooking(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::CANCELLATION,
            null,
            null,
            null,
            null,
            null,
            'Notre camp est annulé.',
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->acceptChange(
            $request,
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            1,
            $this->now()
        );

        $this->assertSame(BookingStatus::CANCELLED, $this->reload($booking)->status);
    }

    public function testARefusedRequestLeavesTheBookingUntouched(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->refuseChange($request, ChangeRequestOrigin::MANAGER, 1);

        $this->assertSame('2027-07-01', $this->reload($booking)->arrivalDate);
        $this->assertSame(
            ChangeRequestStatus::REFUSED,
            $this->changeRequestRepository->findById($id)?->status
        );
    }

    public function testTheSameRequestCannotBeDecidedTwice(): void
    {
        // Two managers on two screens, both clicking "accept".
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            30,
            null,
            null,
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->acceptChange($request, $booking, $this->asset(), ChangeRequestOrigin::MANAGER, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/déjà été traitée/');

        // $request is the stale pre-decision copy, exactly as the second
        // manager's page would hold it.
        $this->service->acceptChange($request, $booking, $this->asset(), ChangeRequestOrigin::MANAGER, 2, $this->now());
    }

    public function testARequestOnAFinalBookingIsRefused(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        $this->expectException(RentalException::class);

        $this->service->requestChange(
            $this->reload($booking),
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
    }

    public function testABookingCancelledBetweenLoadAndDecisionIsNotRewritten(): void
    {
        // The manager's page was rendered before the cancellation; the
        // click lands after it. Without the re-check inside the lock the
        // dates and the price of a closed file were rewritten silently.
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        try {
            // `$booking` and `$request` are the stale copies the manager's
            // page is still holding.
            $this->service->acceptChange(
                $request,
                $booking,
                $this->asset(),
                ChangeRequestOrigin::MANAGER,
                1,
                $this->now()
            );
            $this->fail('A final booking must refuse the change.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('ne peut plus être modifiée', $e->getMessage());
        }

        $fresh = $this->reload($booking);
        $this->assertSame('2027-07-01', $fresh->arrivalDate);
        $this->assertSame(BookingStatus::CANCELLED, $fresh->status);
    }

    public function testAKindThatTouchesNoAvailabilityIsRefusedOnAFinalBookingToo(): void
    {
        // The guard sits at the top of the locked section, not inside the
        // availability branch: a head-count change on a cancelled booking
        // is no more acceptable than a date change.
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            30,
            null,
            null,
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/ne peut plus être modifiée/');

        $this->service->acceptChange(
            $request,
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            1,
            $this->now()
        );
    }

    public function testACancellationRequestOnAnAlreadyFinalBookingIsRefusedInTheModulesOwnWords(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::CANCELLATION,
            null,
            null,
            null,
            null,
            null,
            'Notre camp est annulé.',
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);
        $this->service->changeStatus($booking, BookingStatus::REFUSED, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/ne peut plus être modifiée/');

        $this->service->acceptChange(
            $request,
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            1,
            $this->now()
        );
    }

    public function testAFinalStatusRefusesEveryRequestStillWaiting(): void
    {
        // Otherwise the renter's page goes on offering an "Accepter"
        // button on a file nobody can change any more.
        $booking = $this->createBooking();
        $first = $this->service->requestChange(
            $booking, $this->asset(), ChangeRequestOrigin::MANAGER, ChangeRequestKind::PERSONS,
            null, null, null, 30, null, null,
            null, $this->now()
        );
        $second = $this->service->requestChange(
            $booking, $this->asset(), ChangeRequestOrigin::RENTER, ChangeRequestKind::DATES,
            '2027-07-08', '2027-07-11', null, null, null, null,
            null, $this->now()
        );

        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        $this->assertSame(ChangeRequestStatus::REFUSED, $this->changeRequestRepository->findById($first)?->status);
        $this->assertSame(ChangeRequestStatus::REFUSED, $this->changeRequestRepository->findById($second)?->status);
        $this->assertSame([], $this->changeRequestRepository->findPendingForBooking($booking->id));
    }

    public function testAcceptingACancellationDoesNotRefuseTheVeryRequestBeingAccepted(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->requestChange(
            $booking, $this->asset(), ChangeRequestOrigin::RENTER, ChangeRequestKind::CANCELLATION,
            null, null, null, null, null, 'Notre camp est annulé.',
            null, $this->now()
        );
        $request = $this->changeRequestRepository->findById($id);
        $this->assertNotNull($request);

        $this->service->acceptChange(
            $request, $booking, $this->asset(), ChangeRequestOrigin::MANAGER, 1, $this->now()
        );

        $this->assertSame(ChangeRequestStatus::ACCEPTED, $this->changeRequestRepository->findById($id)?->status);
    }

    public function testARenterMayNotStackMoreThanThreeRequestsAtOnce(): void
    {
        // The renter's form is reached with a tracking token and no login,
        // so nothing else bounds how often it can be posted — and every
        // post lands in a manager's queue.
        $booking = $this->createBooking();
        for ($i = 0; $i < RentalOperationsService::MAX_PENDING_RENTER_REQUESTS; $i++) {
            $this->service->requestChange(
                $booking, $this->asset(), ChangeRequestOrigin::RENTER, ChangeRequestKind::PERSONS,
                null, null, null, 20 + $i, null, null,
                null, $this->now()
            );
        }

        try {
            $this->service->requestChange(
                $booking, $this->asset(), ChangeRequestOrigin::RENTER, ChangeRequestKind::PERSONS,
                null, null, null, 40, null, null,
                null, $this->now()
            );
            $this->fail('The fourth pending request must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('déjà 3 demandes en attente', $e->getMessage());
        }

        $this->assertCount(
            RentalOperationsService::MAX_PENDING_RENTER_REQUESTS,
            $this->changeRequestRepository->findPendingForBooking($booking->id)
        );
    }

    public function testDecidingOneFreesTheRenterToAskAgain(): void
    {
        $booking = $this->createBooking();
        $ids = [];
        for ($i = 0; $i < RentalOperationsService::MAX_PENDING_RENTER_REQUESTS; $i++) {
            $ids[] = $this->service->requestChange(
                $booking, $this->asset(), ChangeRequestOrigin::RENTER, ChangeRequestKind::PERSONS,
                null, null, null, 20 + $i, null, null,
                null, $this->now()
            );
        }

        $first = $this->changeRequestRepository->findById($ids[0]);
        $this->assertNotNull($first);
        $this->service->refuseChange($first, ChangeRequestOrigin::MANAGER, 1);

        $this->service->requestChange(
            $booking, $this->asset(), ChangeRequestOrigin::RENTER, ChangeRequestKind::PERSONS,
            null, null, null, 40, null, null,
            null, $this->now()
        );

        $this->assertCount(
            RentalOperationsService::MAX_PENDING_RENTER_REQUESTS,
            $this->changeRequestRepository->findPendingForBooking($booking->id)
        );
    }

    public function testAManagersOwnProposalsAreNotCapped(): void
    {
        // A manager posts from behind a login, and competing with
        // themselves is not a failure mode worth a refusal.
        $booking = $this->createBooking();
        for ($i = 0; $i < RentalOperationsService::MAX_PENDING_RENTER_REQUESTS + 2; $i++) {
            $this->service->requestChange(
                $booking, $this->asset(), ChangeRequestOrigin::MANAGER, ChangeRequestKind::PERSONS,
                null, null, null, 20 + $i, null, null, 1,
                $this->now()
            );
        }

        $this->assertCount(
            RentalOperationsService::MAX_PENDING_RENTER_REQUESTS + 2,
            $this->changeRequestRepository->findPendingForBooking($booking->id)
        );
    }

    // ── What the new request-time check must and must not refuse ────────

    /**
     * **Capacity is checked even when the dates do not move.**
     *
     * It lives inside `validateRange()`, so gating that call on "do the
     * dates move" let a head-count change past every check there is: the
     * request-time one skipped it, and the acceptance-time one calls
     * `isRangeFree()`, which takes no `$persons` at all and then writes the
     * new figure through. Eighty people in a hall that holds sixty — the
     * case the call site's own comment names — as long as the dates were
     * left alone.
     */
    public function testAHeadCountChangeAloneIsStillHeldToTheAssetsCapacity(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/capacité maximum est de 60/');

        $this->service->requestChange(
            $this->createBooking(),
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            800,
            null,
            null,
            null, $this->now()
        );
    }

    public function testAHeadCountThatFitsIsRecorded(): void
    {
        $booking = $this->createBooking();

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            45,
            null,
            null,
            null, $this->now()
        );

        $this->assertCount(1, $this->changeRequestRepository->findForBooking($booking->id));
    }

    /**
     * **A stay already under way still takes a head-count change.**
     *
     * The first fix for the capacity gap ran the whole range validation on
     * the booking's own dates, which answers about the dates too: an
     * arrival in the past is « Cette date est déjà passée » — true, and
     * beside the point on a request that never touched them. A group that
     * grew by two on the Tuesday of its own stay is an ordinary thing to
     * ask.
     */
    public function testAHeadCountChangeIsTakenOnAStayThatHasAlreadyBegun(): void
    {
        $booking = $this->createBooking(arrival: '2027-07-01', departure: '2027-07-10');

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            22,
            null,
            null,
            null,
            // The Tuesday of their own stay.
            new \DateTimeImmutable('2027-07-06 09:00:00')
        );

        $this->assertCount(1, $this->changeRequestRepository->findForBooking($booking->id));
    }

    /**
     * And the asset's rules being tightened after the booking was made is
     * not the renter's problem either: a minimum of five nights arriving
     * after a three-night booking must not block them from saying they
     * will be two more.
     */
    public function testTighteningTheAssetsRulesDoesNotBlockAHeadCountChange(): void
    {
        $booking = $this->createBooking();
        $this->availabilityService->saveConstraints($this->assetId, 5, 0, 14, 0, [1], null, 0);

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            22,
            null,
            null,
            null, $this->now()
        );

        $this->assertCount(1, $this->changeRequestRepository->findForBooking($booking->id));
    }

    /**
     * **Extending a stay under way is not asking it to begin again.**
     *
     * `RentalRequestController::requestChange()` sends both dates whenever
     * either moves — a request carrying only a new arrival would be
     * accepted against the old departure — so "is there an arrival" never
     * meant "is it a new one". Validating the whole range anyway asked
     * whether the stay may *start* on a day that is already behind the
     * renter, and answered « Cette date est déjà passée » to somebody who
     * wanted four more nights.
     */
    public function testExtendingTheDepartureOfAStayUnderWayIsAccepted(): void
    {
        $booking = $this->createBooking(arrival: '2027-07-01', departure: '2027-07-04');

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            // Unchanged, and travelling with the new departure.
            '2027-07-01',
            '2027-07-08',
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2027-07-06 09:00:00')
        );

        $this->assertCount(1, $this->changeRequestRepository->findForBooking($booking->id));
    }

    /**
     * And moving the arrival IS asking it to begin again, so the rule is
     * still there — which is what makes the exemption above an exemption
     * rather than the check going missing.
     */
    public function testMovingTheArrivalIntoThePastIsStillRefused(): void
    {
        $booking = $this->createBooking(arrival: '2027-07-01', departure: '2027-07-04');

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/déjà passée/');

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-02',
            '2027-07-08',
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2027-07-06 09:00:00')
        );
    }

    /**
     * **Two requests for the same week are what a manager is there to
     * arbitrate**, and `isRangeFree()` says so in as many words — which is
     * why `acceptChange()` asks it with `firmOnly: true`. Validating a
     * manager's proposal at request time counted the competing renter's
     * soft hold as occupancy and refused the very arbitration the module
     * exists to allow.
     */
    public function testAManagersProposalIsNotBlockedByACompetingRequestsHold(): void
    {
        // A pending request holds the dates against the public — that is
        // what `RentalBooking::occupiesTheAsset()` needs an ACTIVE hold to
        // say, so setting one is what makes this the competing request the
        // rule is about rather than an inert row.
        $competitor = $this->createBooking(reference: 'LOC-2027-0002', arrival: '2027-07-10', departure: '2027-07-14');
        $this->bookingRepository->setHold(
            $competitor->id,
            $this->now()->modify('+7 days'),
            HoldOrigin::AUTOMATIC
        );

        $booking = $this->createBooking();

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::DATES,
            '2027-07-10',
            '2027-07-14',
            null,
            null,
            null,
            null,
            null,
            $this->now()
        );

        $this->assertCount(1, $this->changeRequestRepository->findForBooking($booking->id));
    }

    /**
     * **A manager is not held to the public form's rules**, and the three
     * that are editorial rather than physical are exactly the ones
     * `RentalAvailabilityService::isRangeFree()` already exempts them from
     * in as many words: minimum notice, booking horizon, allowed arrival
     * weekdays.
     *
     * The case: an asset asking visitors for a fortnight's notice, and a
     * manager proposing dates next week. They could confirm those dates
     * directly — refusing their *proposal* would be refusing them over a
     * rule that was never about them.
     */
    public function testAManagersProposalIsNotRefusedByThePublicFormsNoticePeriod(): void
    {
        $this->availabilityService->saveConstraints($this->assetId, 0, 0, 14, 0, [], null, 0);
        $booking = $this->createBooking();

        $this->service->requestChange(
            $booking,
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2027-07-01 09:00:00')
        );

        $this->assertCount(1, $this->changeRequestRepository->findForBooking($booking->id));
    }

    /**
     * The same dates asked by the renter are refused, which is what makes
     * the exemption above an exemption rather than the rule going missing.
     */
    public function testTheSameDatesAskedByTheRenterAreRefused(): void
    {
        $this->availabilityService->saveConstraints($this->assetId, 0, 0, 14, 0, [], null, 0);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/14 jours/');

        $this->service->requestChange(
            $this->createBooking(),
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2027-07-01 09:00:00')
        );
    }

    /**
     * And a manager is still held to what is physical: a hall holds sixty
     * whoever is asking.
     */
    public function testAManagerIsStillHeldToTheAssetsCapacity(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/capacité maximum est de 60/');

        $this->service->requestChange(
            $this->createBooking(),
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            800,
            null,
            null,
            null, $this->now()
        );
    }

    /**
     * The mirror of the dates guard below. Without it the capacity check
     * has nothing to weigh, the request is stored, and its summary reads
     * « 0 participants » — while accepting it quietly keeps the count the
     * booking already had.
     */
    public function testAParticipantsRequestWithoutANumberIsRefused(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/doit préciser ce nombre/');

        $this->service->requestChange(
            $this->createBooking(),
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->now()
        );
    }

    public function testACombinedRequestWithoutANumberIsRefusedToo(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/doit préciser ce nombre/');

        $this->service->requestChange(
            $this->createBooking(),
            $this->asset(),
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::DATES_AND_PERSONS,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null,
            null,
            $this->now()
        );
    }

    // ── The billing identity (§22.6) ────────────────────────────────────

    /**
     * Every billing field but the country is an encrypted `BLOB`, and
     * AES-GCM adds a nonce and a tag to what it is given — so a value near
     * the column's ceiling comes back over it, and the database either
     * refuses the update or truncates it into ciphertext that will never
     * decrypt again. Neither reaches the renter as anything actionable.
     */
    public function testAnOversizedBillingFieldIsRefusedInFrench(): void
    {
        $booking = $this->createBooking();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/Adresse.*dépasse 500/');

        $this->service->saveBillingIdentity($booking->id, [
            'name' => 'Unité du Petit Ry',
            'address' => str_repeat('a', 501),
        ]);
    }

    public function testAnOrdinaryBillingAddressIsStored(): void
    {
        $booking = $this->createBooking();

        $this->service->saveBillingIdentity($booking->id, [
            'name' => 'Unité du Petit Ry',
            'address' => "Rue du Village 12\n5100 Jambes",
            'country' => 'be',
            'vat_number' => 'BE0123456789',
        ]);

        $stored = $this->service->billingIdentity($booking->id);

        $this->assertSame('Unité du Petit Ry', $stored['name']);
        // Two letters, upper-cased by the repository — a country field
        // holding « Belgique » is a field nothing can use.
        $this->assertSame('BE', $stored['country']);
    }

    public function testADateRequestWithoutBothDatesIsRefused(): void
    {
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/deux dates/');

        $this->service->requestChange(
            $this->createBooking(),
            $this->asset(),
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            null,
            null,
            null,
            null,
            null,
            null, $this->now()
        );
    }

    // ── Manual blocks (§6.18) ───────────────────────────────────────────

    public function testABlockOverAnAlreadyBookedPeriodIsAcceptedAndBothCoexist(): void
    {
        // Explicitly required by §6.18: it must neither fail silently nor
        // overwrite the booking.
        $booking = $this->createBooking();
        $this->service->confirm($booking, $this->asset(), 1, $this->now());

        $blockId = $this->blockService->create($this->assetId, '2027-07-01', '2027-07-04', 1, 'Chantier', 1);

        $this->assertNotNull($this->blockRepository->findById($blockId));
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking)->status);
        $this->assertSame('2027-07-01', $this->reload($booking)->arrivalDate);
    }

    public function testABlockMakesTheDaysUnavailable(): void
    {
        $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', 1, null, 1);

        $occupancies = $this->blockRepository->findOccupancies(
            $this->assetId,
            new \DateTimeImmutable('2027-09-01'),
            new \DateTimeImmutable('2027-09-30'),
            new \DateTimeImmutable('2027-06-01')
        );

        $this->assertCount(1, $occupancies);
        $this->assertSame('2027-09-01', $occupancies[0]->arrivalDate);
    }

    public function testABlocksOccupancyCarriesNothingButDates(): void
    {
        // A block and a booking must be indistinguishable to the public,
        // which they are because Occupancy has no discriminator.
        $blockId = $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', 1, 'Chantier toiture', 1);
        $block = $this->blockRepository->findById($blockId);
        $this->assertNotNull($block);

        $occupancy = $block->toOccupancy();
        $this->assertStringNotContainsString('Chantier', (string) json_encode($occupancy));
    }

    public function testABlockEndingBeforeItStartsIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->blockService->create($this->assetId, '2027-09-05', '2027-09-01', 1, null, 1);
    }

    public function testAMalformedBlockDateIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->blockService->create($this->assetId, '05/09/2027', '2027-09-10', 1, null, 1);
    }

    public function testABlockCannotBeDeletedThroughAnotherAsset(): void
    {
        // The asset check is the real guard: a block id alone must not let a
        // manager of one asset delete another asset's block.
        $otherAssetId = $this->stockAsset(4);
        $blockId = $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', 1, null, 1);

        $this->expectException(RentalException::class);

        $this->blockService->delete($otherAssetId, $blockId);
    }

    public function testDeletingABlockRemovesIt(): void
    {
        $blockId = $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', 1, null, 1);

        $this->blockService->delete($this->assetId, $blockId);

        $this->assertNull($this->blockRepository->findById($blockId));
    }
}
