<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Core\Audit\AuditSource;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
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
    private RentalModificationRepository $modificationRepository;
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
        $this->modificationRepository = new RentalModificationRepository($this->pdo);
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
            [
                new RentalBookingService($this->bookingRepository, $journal),
                $this->blockRepository,
                $this->modificationRepository,
            ]
        );

        $this->service = new RentalOperationsService(
            $this->bookingRepository,
            $this->bookingAudit,
            $this->commentRepository,
            $this->modificationRepository,
            $availability,
            $this->pricingService,
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
        string $reference = 'LOC-A2B3C4',
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

    // ── « Contrat envoyé » (#708, IT-13) ────────────────────────────────

    public function testSendingTheContractAnswersAnyStateStillWaitingOnTheUnit(): void
    {
        foreach ([BookingStatus::RECEIVED, BookingStatus::INFO_REQUESTED, BookingStatus::PROPOSED] as $i => $from) {
            $booking = $this->createBooking('LOC-2027-010' . $i);
            $this->bookingRepository->setStatus($booking->id, $from, $this->now());

            $this->service->contractSent($this->reload($booking), $this->asset(), 1, $this->now());

            $this->assertSame(BookingStatus::CONTRACT_SENT, $this->reload($booking)->status, $from->value);
        }
    }

    public function testSendingItAgainOrAfterConfirmationNeverMovesTheStatusBack(): void
    {
        $sent = $this->createBooking('LOC-2027-0201');
        $this->bookingRepository->setStatus($sent->id, BookingStatus::CONTRACT_SENT, $this->now());
        $this->service->contractSent($this->reload($sent), $this->asset(), 1, $this->now());
        $this->assertSame(BookingStatus::CONTRACT_SENT, $this->reload($sent)->status);

        $confirmed = $this->createBooking('LOC-2027-0202', '2027-08-01', '2027-08-04');
        $this->bookingRepository->setStatus($confirmed->id, BookingStatus::CONFIRMED, $this->now());
        $this->service->contractSent($this->reload($confirmed), $this->asset(), 1, $this->now());
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($confirmed)->status);
        $this->assertNull($this->reload($confirmed)->holdUntil);
    }

    public function testTheContractEmailIsTheOnlyMessageNoDecisionEmail(): void
    {
        $this->assertNull(\Modules\Rental\Booking\RenterDecision::forStatus(BookingStatus::CONTRACT_SENT));
        $this->assertFalse(BookingStatus::CONTRACT_SENT->needsAttention());
    }

    public function testALongerHoldIsKeptAShorterOneIsLengthenedAndNoneIsCreated(): void
    {
        // Sent on 1 February, stay on 1 July: the 15-day floor is 16 February.
        $longer = $this->createBooking('LOC-2027-0301');
        $this->bookingRepository->setHold($longer->id, new \DateTimeImmutable('2027-03-15 10:00:00'), HoldOrigin::MANAGER);
        $this->service->contractSent($this->reload($longer), $this->asset(), 1, $this->now());
        $this->assertSame('2027-03-15 10:00:00', $this->reload($longer)->holdUntil?->format('Y-m-d H:i:s'));
        $this->assertSame(HoldOrigin::MANAGER, $this->reload($longer)->holdOrigin);

        $shorter = $this->createBooking('LOC-2027-0302', '2027-08-01', '2027-08-04');
        $this->bookingRepository->setHold($shorter->id, new \DateTimeImmutable('2027-02-05 10:00:00'), HoldOrigin::MANAGER);
        $this->service->contractSent($this->reload($shorter), $this->asset(), 1, $this->now());
        $this->assertSame('2027-02-16 10:00:00', $this->reload($shorter)->holdUntil?->format('Y-m-d H:i:s'));
        $this->assertSame(HoldOrigin::MANAGER, $this->reload($shorter)->holdOrigin, 'an option stays an option');

        $none = $this->createBooking('LOC-2027-0303', '2027-09-01', '2027-09-04');
        $this->service->contractSent($this->reload($none), $this->asset(), 1, $this->now());
        $this->assertSame('2027-02-16 10:00:00', $this->reload($none)->holdUntil?->format('Y-m-d H:i:s'));
        $this->assertSame(HoldOrigin::AUTOMATIC, $this->reload($none)->holdOrigin);
    }

    /**
     * The history names the extension for what it is: « Blocage prolongé »,
     * never « Option posée », which carries an expiry an automatic hold
     * does not have.
     */
    public function testTheContractHoldIsRecordedAsAnExtensionNotAnOption(): void
    {
        $booking = $this->createBooking('LOC-2027-0304', '2027-09-01', '2027-09-04');
        $this->service->contractSent($booking, $this->asset(), 1, $this->now());

        $keys = array_map(
            static fn($entry) => $entry->fieldKey,
            RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id)
        );
        $this->assertContains(BookingAudit::HOLD_EXTENDED, $keys);
        $this->assertNotContains(BookingAudit::HOLD_PLACED, $keys);
    }

    public function testTheContractHoldNeverRunsPastTheStartOfTheStay(): void
    {
        $soon = $this->createBooking('LOC-2027-0401', '2027-02-08', '2027-02-10');

        $this->service->contractSent($soon, $this->asset(), 1, $this->now());

        $this->assertSame('2027-02-08 00:00:00', $this->reload($soon)->holdUntil?->format('Y-m-d H:i:s'));
    }

    /**
     * The date the send's confirmation announces is the one the send then
     * writes — one rule, read before and applied after — and nothing is
     * announced for a booking the send would not hold.
     */
    public function testTheAnnouncedHoldIsTheOneTheSendApplies(): void
    {
        $cases = [
            'longer' => ['LOC-2027-0501', '2027-07-01', '2027-03-15 10:00:00'],
            'shorter' => ['LOC-2027-0502', '2027-08-01', '2027-02-05 10:00:00'],
            'none' => ['LOC-2027-0503', '2027-09-01', null],
            'soon' => ['LOC-2027-0504', '2027-02-08', null],
        ];
        foreach ($cases as $label => [$reference, $arrival, $hold]) {
            $departure = (new \DateTimeImmutable($arrival))->modify('+3 days')->format('Y-m-d');
            $booking = $this->createBooking($reference, $arrival, $departure);
            if ($hold !== null) {
                $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable($hold), HoldOrigin::MANAGER);
            }

            $announced = $this->service->contractHoldUntil($this->reload($booking), $this->now());
            $this->service->contractSent($this->reload($booking), $this->asset(), 1, $this->now());

            $this->assertNotNull($announced, $label);
            $this->assertEquals($announced, $this->reload($booking)->holdUntil, $label);
        }

        $confirmed = $this->createBooking('LOC-2027-0505', '2027-10-01', '2027-10-04');
        $this->bookingRepository->setStatus($confirmed->id, BookingStatus::CONFIRMED, $this->now());
        $this->assertNull($this->service->contractHoldUntil($this->reload($confirmed), $this->now()));
    }

    /** Confirmation refused while a step of the agreement is missing, accepted once done. */
    public function testConfirmationWaitsForTheAgreement(): void
    {
        $booking = $this->createBooking();
        $missing = \Modules\Rental\Booking\BookingMilestones::for(
            $booking,
            $this->now(),
            [\Modules\Rental\Booking\BookingMilestones::CONTRACT_SENT => false]
        );

        try {
            $this->service->confirm($booking, $this->asset(), 1, $this->now(), $missing);
            $this->fail('confirmed without a contract sent');
        } catch (RentalException $e) {
            $this->assertStringContainsString('« Contrat envoyé »', $e->getMessage());
        }

        $done = \Modules\Rental\Booking\BookingMilestones::for(
            $booking,
            $this->now(),
            [\Modules\Rental\Booking\BookingMilestones::CONTRACT_SENT => true]
        );
        $this->service->confirm($this->reload($booking), $this->asset(), 1, $this->now(), $done);
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking)->status);
    }

    /** A step « sans objet » blocks nothing: an asset with no contract. */
    public function testAStepWithoutObjectDoesNotBlockConfirmation(): void
    {
        $booking = $this->createBooking();

        $this->service->confirm($booking, $this->asset(), 1, $this->now(), \Modules\Rental\Booking\BookingMilestones::for($booking, $this->now()));

        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking)->status);
    }

    public function testAValidTransitionMovesTheBooking(): void
    {
        $booking = $this->createBooking();

        $this->service->changeStatus($booking, BookingStatus::INFO_REQUESTED, 1, $this->now());

        $this->assertSame(BookingStatus::INFO_REQUESTED, $this->reload($booking)->status);
    }

    public function testAnInvalidTransitionIsRefusedWithAReasonAndChangesNothing(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/nouvelle demande/');

        $this->service->changeStatus($this->reload($booking), BookingStatus::INFO_REQUESTED, 1, $this->now());
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
        $this->service->changeStatus($booking, BookingStatus::INFO_REQUESTED, 42, $this->now());

        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id);
        $this->assertCount(1, $history);
        $this->assertSame(BookingAudit::STATUS_CHANGED, $history[0]->fieldKey);
        $this->assertSame('Demande reçue', $history[0]->fromValue);
        $this->assertSame('Informations demandées', $history[0]->toValue);
        // A person moved it, even though this test wires no resolver to
        // say which account they log in with.
        $this->assertSame(AuditSource::Human, $history[0]->source);
    }

    public function testTheHistoryCarriesNoRenterIdentity(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::INFO_REQUESTED, 1, $this->now());
        $this->service->addComment($this->reload($booking), 1, 'Le groupe a laissé la cuisine sale.');

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM entity_changes')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringNotContainsString('Jeanne Martin', $raw);
        $this->assertStringNotContainsString('@example.be', $raw);
        $this->assertStringNotContainsString('cuisine sale', $raw);
    }

    public function testNoRenterIdentityReachesTheJournalOnAStatusChange(): void
    {
        $booking = $this->createBooking();
        $this->service->changeStatus($booking, BookingStatus::INFO_REQUESTED, 1, $this->now());

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringContainsString('LOC-A2B3C4', $journal);
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
        $first = $this->createBooking('LOC-A2B3C4');
        $second = $this->createBooking('LOC-A2B3C5');

        $this->service->confirm($first, $this->asset(), 1, $this->now());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/plus disponibles/');

        $this->service->confirm($second, $this->asset(), 2, $this->now());
    }

    public function testTheSecondConfirmationLeavesTheLoserUntouched(): void
    {
        $first = $this->createBooking('LOC-A2B3C4');
        $second = $this->createBooking('LOC-A2B3C5');
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
        $this->service->changeStatus($booking, BookingStatus::INFO_REQUESTED, 1, $this->now());

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

        $a = $this->createBooking('LOC-D4E5F5', '2027-08-01', '2027-08-05', $stockId, 5);
        $b = $this->createBooking('LOC-D4E5F6', '2027-08-01', '2027-08-05', $stockId, 5);
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
            $this->createBooking('LOC-D4E5F5', '2027-08-01', '2027-08-05', $stockId, 5),
            $asset,
            1,
            $this->now()
        );

        // Seven left; a booking of seven must go through.
        $this->service->confirm(
            $this->createBooking('LOC-D4E5F6', '2027-08-01', '2027-08-05', $stockId, 7),
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
        $this->blockService->create($this->assetId, '2027-07-01', '2027-07-04', 'Chantier toiture', 1);

        $this->expectException(RentalException::class);

        $this->service->confirm($this->createBooking(), $this->asset(), 1, $this->now());
    }

    // ── Manager's option (specifications.md §22.5) ────────────────────────────────────────

    public function testAnOptionIsStoredAsAManagerHold(): void
    {
        $booking = $this->createBooking();

        $this->service->placeOption($booking, $this->asset(), new \DateTimeImmutable('2027-03-22 18:00:00'), 7, $this->now());

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
            $this->asset(),
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
            $this->asset(),
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

        $this->service->placeOption($booking, $this->asset(), new \DateTimeImmutable('2027-03-01'), 1, $this->now());
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

    // ── A booking's end and its modification (#927, chantier §2.3) ──────

    /**
     * Closed, cancelled, refused or expired, a booking takes its
     * modification in progress along, in the same transaction: its new
     * dates are free again, and the history says why.
     */
    public function testAFinalStatusCancelsTheModificationInProgress(): void
    {
        $booking = $this->createBooking();
        $this->modificationRepository->createDraft(
            $booking,
            new \Modules\Rental\Booking\ModificationTerms(
                '2027-07-10',
                '2027-07-13',
                1,
                20,
                $this->pricingService->quoteForAsset(
                    $this->assetId,
                    new \Modules\Rental\Pricing\PricingRequest('2027-07-10', '2027-07-13', 20)
                )
            ),
            null,
            new \DateTimeImmutable('2027-01-02 10:00:00')
        );
        $this->assertFalse($this->availabilityService->isRangeFree(
            $this->asset(),
            \Modules\Rental\Pricing\BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-10'),
            new \DateTimeImmutable('2027-07-13'),
            1,
            new \DateTimeImmutable('2027-01-02 11:00:00')
        ), 'the project holds its new dates');

        $this->service->changeStatus($booking, BookingStatus::CANCELLED, 1, new \DateTimeImmutable('2027-01-03'));

        $this->assertNull($this->modificationRepository->findActiveFor($booking->id));
        $this->assertSame(
            \Modules\Rental\Booking\ModificationStatus::CANCELLED,
            $this->modificationRepository->findForBooking($booking->id)[0]->status
        );
        $this->assertTrue($this->availabilityService->isRangeFree(
            $this->asset(),
            \Modules\Rental\Pricing\BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-10'),
            new \DateTimeImmutable('2027-07-13'),
            1,
            new \DateTimeImmutable('2027-01-03 11:00:00')
        ), 'its dates are free again');
        $summaries = array_map(
            static fn($entry): string => (string) $entry->summary,
            RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id)
        );
        $this->assertNotEmpty(array_filter(
            $summaries,
            static fn(string $summary): bool => str_starts_with($summary, 'Modification annulée avec la réservation')
        ));
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

    /** Issue #977: the billing identity and the on-site contact, checked together, written together. */
    public function testRenterCoordinatesAreCheckedWholeBeforeAnythingIsWritten(): void
    {
        $booking = $this->createBooking();

        try {
            $this->service->saveRenterCoordinates(
                $booking->id,
                ['name' => 'Unité du Petit Ry', 'address' => 'Rue du Village 12'],
                ['name' => 'Paul Lemaire', 'phone' => str_repeat('1', 41)]
            );
            $this->fail('an over-long phone is refused');
        } catch (RentalException $e) {
            $this->assertStringContainsString('Téléphone du responsable', $e->getMessage());
        }
        $this->assertNull($this->service->billingIdentity($booking->id)['name'], 'the billing half waited too');

        $this->service->saveRenterCoordinates(
            $booking->id,
            ['name' => 'Unité du Petit Ry', 'address' => 'Rue du Village 12'],
            ['name' => 'Paul Lemaire', 'phone' => '0470 98 76 54']
        );
        $this->assertSame('Unité du Petit Ry', $this->service->billingIdentity($booking->id)['name']);
        $this->assertSame('Paul Lemaire', $this->service->onSiteContact($booking->id)['name']);

        // Without the contact's fields, the contact stays as it was.
        $this->service->saveRenterCoordinates($booking->id, ['name' => 'ASBL'], null);
        $this->assertSame('Paul Lemaire', $this->service->onSiteContact($booking->id)['name']);
    }

    // ── Manual blocks (§6.18) ───────────────────────────────────────────

    public function testABlockOverAnAlreadyBookedPeriodIsAcceptedAndBothCoexist(): void
    {
        // Explicitly required by §6.18: it must neither fail silently nor
        // overwrite the booking.
        $booking = $this->createBooking();
        $this->service->confirm($booking, $this->asset(), 1, $this->now());

        $blockId = $this->blockService->create($this->assetId, '2027-07-01', '2027-07-04', 'Chantier', 1);

        $this->assertNotNull($this->blockRepository->findById($blockId));
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($booking)->status);
        $this->assertSame('2027-07-01', $this->reload($booking)->arrivalDate);
    }

    public function testABlockMakesTheDaysUnavailable(): void
    {
        $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', null, 1);

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
        $blockId = $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', 'Chantier toiture', 1);
        $block = $this->blockRepository->findById($blockId);
        $this->assertNotNull($block);

        $occupancy = $block->toOccupancy();
        $this->assertStringNotContainsString('Chantier', (string) json_encode($occupancy));
    }

    public function testABlockEndingBeforeItStartsIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->blockService->create($this->assetId, '2027-09-05', '2027-09-01', null, 1);
    }

    public function testAMalformedBlockDateIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->blockService->create($this->assetId, '05/09/2027', '2027-09-10', null, 1);
    }

    public function testABlockCannotBeDeletedThroughAnotherAsset(): void
    {
        // The asset check is the real guard: a block id alone must not let a
        // manager of one asset delete another asset's block.
        $otherAssetId = $this->stockAsset(4);
        $blockId = $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', null, 1);

        $this->expectException(RentalException::class);

        $this->blockService->delete($otherAssetId, $blockId);
    }

    public function testDeletingABlockRemovesIt(): void
    {
        $blockId = $this->blockService->create($this->assetId, '2027-09-01', '2027-09-05', null, 1);

        $this->blockService->delete($this->assetId, $blockId);

        $this->assertNull($this->blockRepository->findById($blockId));
    }
}
