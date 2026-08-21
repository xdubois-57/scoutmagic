<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingEventRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The stay, persisted (§6.21–§6.23).
 *
 * `SettlementCalculatorTest` and `MeterConsumptionTest` pin the arithmetic
 * in isolation; this pins what only shows up once state is involved — that a
 * checklist snapshot is taken exactly once, that a validated settlement
 * cannot be edited, that an incident description never leaves the encrypted
 * column, and that a meter id from one asset cannot reach another's booking.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalStayServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalStayService $service;
    private RentalStayRepository $stayRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private RentalPricingService $pricingService;
    private RentalOperationsService $operationsService;
    private int $assetId;
    private int $otherAssetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->stayRepository = new RentalStayRepository($this->pdo, $this->encryption);
        $eventRepository = new RentalBookingEventRepository($this->pdo);

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );

        $this->service = new RentalStayService(
            $this->stayRepository,
            $eventRepository,
            $this->pricingService,
            new SettlementCalculator(),
            $journal
        );

        $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [new RentalBookingService($this->bookingRepository, $journal)]
        );
        $this->operationsService = new RentalOperationsService(
            $this->bookingRepository,
            $eventRepository,
            new RentalBookingCommentRepository($this->pdo, $this->encryption),
            new RentalChangeRequestRepository($this->pdo, $this->encryption),
            $availability,
            $this->pricingService,
            new QuoteEditor(),
            $journal,
            null,
            $this->service
        );

        $this->assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');
        $this->otherAssetId = $this->createAsset('Autre', 'autre');
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function createAsset(string $name, string $slug): int
    {
        $id = $this->assetRepository->create('Local', $name, $slug, 60, 1, null, null, null, true, false);
        $this->pricingService->saveAssetPricing($id, 'per_night', 12000, null, null);

        return $id;
    }

    private function createBooking(
        string $reference = 'LOC-2027-0001',
        ?int $assetId = null,
        int $persons = 40
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $assetId ?? $this->assetId,
            $reference,
            '2027-07-01',
            '2027-07-04',
            1,
            $persons,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => strtolower($reference) . '@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            new PriceQuote(
                lines: [
                    new PriceLine('3 nuits', 3, 12000, 36000, PriceLine::RULE_BASE),
                    new PriceLine('Taxe de séjour', $persons, 100, $persons * 100, PriceLine::RULE_FEE_PER_PERSON),
                ],
                totalCents: 36000 + $persons * 100,
                nights: 3,
                persons: $persons,
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

    private function addMeter(?int $assetId = null, ?int $feeId = null): int
    {
        return $this->service->addMeter(
            $assetId ?? $this->assetId,
            'Électricité',
            MeterKind::ELECTRICITY,
            'kWh',
            $feeId
        );
    }

    private function meterFee(int $cents = 25): int
    {
        return $this->pricingService->addFee($this->assetId, 'Électricité', 'meter', $cents, 'kWh');
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-07-04 11:00:00');
    }

    // ── Meters (§6.22) ──────────────────────────────────────────────────

    public function testAMeterWithoutAUnitFallsBackToItsKindsDefault(): void
    {
        $id = $this->service->addMeter($this->assetId, 'Eau', MeterKind::WATER, '', null);
        $meter = $this->stayRepository->findMeter($id);

        $this->assertSame('m³', $meter?->unit);
    }

    public function testAMeterNeedsAName(): void
    {
        $this->expectException(RentalException::class);

        $this->service->addMeter($this->assetId, '   ', MeterKind::OTHER, 'x', null);
    }

    public function testARetiredMeterDisappearsFromTheListButItsReadingsSurvive(): void
    {
        // Readings already taken are evidence for a settlement; a cascade
        // would erase them along with the meter.
        $meterId = $this->addMeter();
        $booking = $this->createBooking();
        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1
        );

        $this->service->retireMeter($this->assetId, $meterId);

        $this->assertSame([], $this->service->metersFor($this->assetId));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_meter_readings')->fetchColumn());
    }

    public function testAMeterOfAnotherAssetCannotBeRetiredThroughThisOne(): void
    {
        $foreign = $this->addMeter($this->otherAssetId);

        $this->service->retireMeter($this->assetId, $foreign);

        $this->assertCount(1, $this->service->metersFor($this->otherAssetId));
    }

    // ── Readings (§6.22) ────────────────────────────────────────────────

    public function testAReadingIsStoredInThousandths(): void
    {
        $meterId = $this->addMeter();
        $booking = $this->createBooking();

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1234,567', $this->now(), null, null, 1
        );

        $this->assertSame(
            1234567,
            (int) $this->pdo->query('SELECT value_milli FROM rental_meter_readings')->fetchColumn()
        );
    }

    public function testASecondReadingOfTheSamePhaseIsACorrectionNotAnAddition(): void
    {
        // Keeping both would make consumption a guess about which pair to use.
        $meterId = $this->addMeter();
        $booking = $this->createBooking();

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1
        );
        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1100', $this->now(), null, null, 1
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_meter_readings')->fetchColumn());
        $this->assertSame(
            1100000,
            $this->stayRepository->findReading($booking->id, $meterId, ReadingPhase::ARRIVAL)?->valueMilli
        );
    }

    public function testCorrectingAValueKeepsAnExistingPhoto(): void
    {
        // The photo is the evidence for the reading; re-uploading it just to
        // fix a digit is not something to demand.
        $meterId = $this->addMeter();
        $booking = $this->createBooking();
        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), 42, null, 1
        );

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1100', $this->now(), null, null, 1
        );

        $this->assertSame(
            42,
            $this->stayRepository->findReading($booking->id, $meterId, ReadingPhase::ARRIVAL)?->fileId
        );
    }

    public function testANonNumericReadingIsRefused(): void
    {
        $meterId = $this->addMeter();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/nombre/');

        $this->service->recordReading(
            $this->createBooking(), $this->assetId, $meterId, ReadingPhase::ARRIVAL, 'abc', $this->now(), null, null, 1
        );
    }

    public function testANegativeIndexIsRefused(): void
    {
        $meterId = $this->addMeter();

        $this->expectException(RentalException::class);

        $this->service->recordReading(
            $this->createBooking(), $this->assetId, $meterId, ReadingPhase::ARRIVAL, '-5', $this->now(), null, null, 1
        );
    }

    public function testAMeterOfAnotherAssetCannotBeWrittenAgainstThisBooking(): void
    {
        // The asset check is the guard that matters.
        $foreign = $this->addMeter($this->otherAssetId);

        $this->expectException(RentalException::class);

        $this->service->recordReading(
            $this->createBooking(), $this->assetId, $foreign, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1
        );
    }

    public function testConsumptionPairsTheTwoReadingsAndPricesThem(): void
    {
        $feeId = $this->meterFee(25);
        $meterId = $this->addMeter(feeId: $feeId);
        $booking = $this->createBooking();

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1
        );
        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::DEPARTURE, '1342,5', $this->now(), null, null, 1
        );

        $consumptions = $this->service->consumptionsFor($booking, $this->assetId);
        $this->assertCount(1, $consumptions);
        $this->assertSame(342500, $consumptions[0]->consumptionMilli);
        $this->assertSame(8563, $consumptions[0]->amountCents);
    }

    public function testAReadingIsRecordedInTheBookingsHistory(): void
    {
        $meterId = $this->addMeter();
        $booking = $this->createBooking();

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 7
        );

        $history = (new RentalBookingEventRepository($this->pdo))->findForBooking($booking->id);
        $this->assertNotSame([], $history);
        $this->assertStringContainsString('Électricité', (string) $history[0]['to_value']);
    }

    // ── Inventory snapshot (§6.23) ──────────────────────────────────────

    public function testTheChecklistIsCopiedIntoTheBookingAtConfirmation(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés', 0);
        $this->service->addInventoryItem($this->assetId, 'Tables', 1);
        $booking = $this->createBooking();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $this->operationsService->confirm($booking, $asset, 1, $this->now());

        $inventory = $this->service->inventoryFor($booking->id);
        $this->assertCount(2, $inventory);
        $this->assertSame('Clés', $inventory[0]['label']);
        $this->assertSame(InventoryState::NOT_CHECKED, $inventory[0]['arrival_state']);
    }

    public function testEditingTheTemplateAfterwardsChangesNoExistingInventory(): void
    {
        // An item renamed in June must not rewrite what was checked in
        // March, and one deleted must not erase a finding.
        $itemId = $this->service->addInventoryItem($this->assetId, 'Chaises', 0);
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);

        $this->service->removeInventoryItem($itemId);
        $this->service->addInventoryItem($this->assetId, 'Chaises (x40)', 0);

        $inventory = $this->service->inventoryFor($booking->id);
        $this->assertCount(1, $inventory);
        $this->assertSame('Chaises', $inventory[0]['label']);
    }

    public function testTheSnapshotIsTakenExactlyOnce(): void
    {
        // Confirmation can be reached more than once; re-snapshotting would
        // overwrite a completed inventory with blanks.
        $this->service->addInventoryItem($this->assetId, 'Clés', 0);
        $booking = $this->createBooking();

        $this->assertTrue($this->service->snapshotInventory($booking, $this->assetId));
        $this->assertFalse($this->service->snapshotInventory($booking, $this->assetId));
        $this->assertCount(1, $this->service->inventoryFor($booking->id));
    }

    public function testAnAssetWithAnEmptyChecklistStillCountsAsSnapshotted(): void
    {
        // Zero rows is a legitimate snapshot; "are there rows?" would
        // re-snapshot forever.
        $booking = $this->createBooking();

        $this->assertTrue($this->service->snapshotInventory($booking, $this->assetId));
        $this->assertFalse($this->service->snapshotInventory($booking, $this->assetId));
    }

    public function testAnInventoryLineIsRecordedPerPhase(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés', 0);
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $line = $this->service->inventoryFor($booking->id)[0];

        $this->service->setInventoryState($booking, $line['id'], ReadingPhase::ARRIVAL, InventoryState::OK, null);
        $this->service->setInventoryState(
            $booking, $line['id'], ReadingPhase::DEPARTURE, InventoryState::MISSING, 'Un jeu manquant'
        );

        $updated = $this->service->inventoryFor($booking->id)[0];
        $this->assertSame(InventoryState::OK, $updated['arrival_state']);
        $this->assertSame(InventoryState::MISSING, $updated['departure_state']);
        $this->assertSame('Un jeu manquant', $updated['departure_note']);
    }

    public function testAnInventoryLineOfAnotherBookingCannotBeWritten(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés', 0);
        $mine = $this->createBooking('LOC-2027-0001');
        $other = $this->createBooking('LOC-2027-0002');
        $this->service->snapshotInventory($other, $this->assetId);
        $foreignLine = $this->service->inventoryFor($other->id)[0];

        $this->expectException(RentalException::class);

        $this->service->setInventoryState($mine, $foreignLine['id'], ReadingPhase::ARRIVAL, InventoryState::OK, null);
    }

    // ── Incidents (§6.23) ───────────────────────────────────────────────

    public function testAnIncidentDescriptionIsStoredEncrypted(): void
    {
        $booking = $this->createBooking();
        $this->service->reportIncident($booking, 'Vitre cassée par le groupe de Mme Martin', 5000, null, 1);

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM rental_incidents')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('Vitre cassée', $raw);
        $this->assertSame(
            'Vitre cassée par le groupe de Mme Martin',
            $this->service->incidentsFor($booking->id)[0]->description
        );
    }

    public function testAnIncidentNeverReachesTheJournalInClear(): void
    {
        $booking = $this->createBooking();
        $this->service->reportIncident($booking, 'Vitre cassée par le groupe de Mme Martin', 5000, null, 1);

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('LOC-2027-0001', $journal);
        $this->assertStringNotContainsString('Vitre cassée', $journal);
        $this->assertStringNotContainsString('Jeanne Martin', $journal);
    }

    public function testAnIncidentStartsPendingAndIsInvisibleToTheRenter(): void
    {
        // They would read a proposed figure as a demand.
        $booking = $this->createBooking();
        $this->service->reportIncident($booking, 'Dégât en cours d\'évaluation', 5000, null, 1);

        $this->assertSame(IncidentDecision::PENDING, $this->service->incidentsFor($booking->id)[0]->decision);
        $this->assertSame([], $this->service->incidentsVisibleToRenter($booking->id));
    }

    public function testADecidedIncidentBecomesVisibleToTheRenter(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->reportIncident($booking, 'Vitre cassée', 5000, null, 1);

        $this->service->decideIncident($booking, $id, IncidentDecision::CHARGE, null, 1);

        $this->assertCount(1, $this->service->incidentsVisibleToRenter($booking->id));
    }

    public function testAnEmptyDescriptionIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service->reportIncident($this->createBooking(), '   ', 5000, null, 1);
    }

    public function testDecidingBackToPendingIsRefused(): void
    {
        // "Undecide" is not a decision; a manager who changes their mind
        // picks one of the three.
        $booking = $this->createBooking();
        $id = $this->service->reportIncident($booking, 'Vitre cassée', 5000, null, 1);

        $this->expectException(RentalException::class);

        $this->service->decideIncident($booking, $id, IncidentDecision::PENDING, null, 1);
    }

    public function testAnIncidentOfAnotherBookingCannotBeDecidedHere(): void
    {
        $mine = $this->createBooking('LOC-2027-0001');
        $other = $this->createBooking('LOC-2027-0002');
        $foreignId = $this->service->reportIncident($other, 'Vitre cassée', 5000, null, 1);

        $this->expectException(RentalException::class);

        $this->service->decideIncident($mine, $foreignId, IncidentDecision::CHARGE, null, 1);
    }

    // ── The final settlement (§6.21) ────────────────────────────────────

    public function testAPreviewStoresNothing(): void
    {
        // Looking must not create a version, or a manager who opens the page
        // twice ends up with two.
        $booking = $this->createBooking();

        $this->service->previewSettlement($booking, $this->assetId, 28);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_settlements')->fetchColumn());
    }

    public function testRecordingASettlementNeverTouchesTheAgreedPrice(): void
    {
        // §6.21 is emphatic, and the separation is structural: the
        // calculator returns new lines and the service writes them to a
        // different table.
        $booking = $this->createBooking(persons: 40);
        $agreedBefore = $booking->effectiveTotalCents();

        $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);

        $reloaded = $this->bookingRepository->findById($booking->id);
        $this->assertSame($agreedBefore, $reloaded?->effectiveTotalCents());
    }

    public function testTheFinalHeadCountRescalesOnlyThePerPersonLine(): void
    {
        $booking = $this->createBooking(persons: 40);

        $settlement = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);

        $this->assertSame(36000 + 2800, $settlement->totalCents);
        $this->assertSame(28, $settlement->finalPersons);
    }

    public function testEachSettlementIsANewVersion(): void
    {
        $booking = $this->createBooking();

        $first = $this->service->recordSettlement($booking, $this->assetId, 40, [], 1);
        $second = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertCount(2, $this->service->settlementsFor($booking->id));
        $this->assertSame(2, $this->service->latestSettlement($booking->id)?->version);
    }

    public function testADeletedVersionDoesNotFreeItsNumber(): void
    {
        // v2 may already have been sent; two different settlements called v2
        // is the confusion versioning exists to prevent.
        $booking = $this->createBooking();
        $this->service->recordSettlement($booking, $this->assetId, 40, [], 1);
        $second = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);
        $this->stayRepository->deleteSettlement($second->id);

        $third = $this->service->recordSettlement($booking, $this->assetId, 30, [], 1);

        $this->assertSame(3, $third->version);
    }

    public function testAValidatedSettlementCannotBeValidatedTwice(): void
    {
        $booking = $this->createBooking();
        $settlement = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);
        $this->service->validateSettlement($booking, $settlement->id, 1);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/déjà validé/');

        $this->service->validateSettlement($booking, $settlement->id, 2);
    }

    public function testAValidatedSettlementIsImmutable(): void
    {
        $booking = $this->createBooking();
        $settlement = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);
        $this->service->validateSettlement($booking, $settlement->id, 1);

        $this->stayRepository->deleteSettlement($settlement->id);

        $this->assertNotNull($this->stayRepository->findSettlement($settlement->id));
    }

    public function testCorrectingAValidatedSettlementMeansANewVersion(): void
    {
        $booking = $this->createBooking();
        $first = $this->service->recordSettlement($booking, $this->assetId, 40, [], 1);
        $this->service->validateSettlement($booking, $first->id, 1);

        $second = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);

        $this->assertSame(2, $second->version);
        $this->assertTrue($this->stayRepository->findSettlement($first->id)?->isValidated);
        $this->assertFalse($second->isValidated);
    }

    public function testASettlementOfAnotherBookingCannotBeValidatedHere(): void
    {
        $mine = $this->createBooking('LOC-2027-0001');
        $other = $this->createBooking('LOC-2027-0002');
        $foreign = $this->service->recordSettlement($other, $this->assetId, 28, [], 1);

        $this->expectException(RentalException::class);

        $this->service->validateSettlement($mine, $foreign->id, 1);
    }

    public function testANegativeHeadCountIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service->recordSettlement($this->createBooking(), $this->assetId, -1, [], 1);
    }

    public function testZeroParticipantsIsARealAnswer(): void
    {
        // A group that cancelled on the day still owes for the hall.
        $booking = $this->createBooking(persons: 40);

        $settlement = $this->service->recordSettlement($booking, $this->assetId, 0, [], 1);

        $this->assertSame(0, $settlement->finalPersons);
        $this->assertSame(36000, $settlement->totalCents);
    }

    public function testMetersIncidentsAndTheHeadCountAllReachTheSettlement(): void
    {
        $feeId = $this->meterFee(25);
        $meterId = $this->addMeter(feeId: $feeId);
        $booking = $this->createBooking(persons: 40);

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1
        );
        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::DEPARTURE, '1342,5', $this->now(), null, null, 1
        );
        $charged = $this->service->reportIncident($booking, 'Vitre cassée', 5000, null, 1);
        $this->service->decideIncident($booking, $charged, IncidentDecision::CHARGE, null, 1);
        $pending = $this->service->reportIncident($booking, 'Trace au mur, à évaluer', 99999, null, 1);

        $settlement = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);

        // 360,00 hall + 28,00 tax + 85,63 electricity + 50,00 damage. The
        // pending one contributes nothing.
        $this->assertSame(36000 + 2800 + 8563 + 5000, $settlement->totalCents);
        $this->assertNotNull($this->service->incidentsFor($booking->id)[1]);
        $this->assertSame(IncidentDecision::PENDING, $this->service->incidentsFor($booking->id)[1]->decision);
        $this->assertSame($pending, $this->service->incidentsFor($booking->id)[1]->id);
    }

    public function testTheSettlementLinesSurviveAReload(): void
    {
        $booking = $this->createBooking(persons: 40);
        $settlement = $this->service->recordSettlement($booking, $this->assetId, 28, [], 1);

        $reloaded = $this->stayRepository->findSettlement($settlement->id);

        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->lines);
        $this->assertSame('3 nuits', $reloaded->lines[0]->label);
        $this->assertStringContainsString('28 participants', (string) $reloaded->lines[1]->detail);
    }

    public function testWithoutFinanceNothingIsCountedAsAlreadyPaid(): void
    {
        // The settlement is then a statement of what is owed rather than of
        // what is left — honest, and the module keeps working.
        $booking = $this->createBooking(persons: 40);

        $settlement = $this->service->recordSettlement($booking, $this->assetId, 40, [], 1);

        $this->assertSame(0, $settlement->alreadyPaidCents);
        $this->assertSame($settlement->totalCents, $settlement->balanceCents);
    }

    public function testASecurityDepositIsReturnedInFullWhenNothingWasWithheld(): void
    {
        $booking = $this->createBooking();

        $settlement = $this->service->recordSettlement(
            $booking,
            $this->assetId,
            40,
            [],
            1,
            new PaymentSettings(securityDepositEnabled: true, securityDepositAmountCents: 50000)
        );

        $this->assertSame(0, $settlement->securityDepositWithheldCents);
        $this->assertSame(50000, $settlement->securityDepositReturnCents);
    }

    public function testAWithheldIncidentReducesWhatIsGivenBack(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->reportIncident($booking, 'Vitre cassée', 3000, null, 1);
        $this->service->decideIncident($booking, $id, IncidentDecision::WITHHOLD, null, 1);

        $settlement = $this->service->recordSettlement(
            $booking,
            $this->assetId,
            40,
            [],
            1,
            new PaymentSettings(securityDepositEnabled: true, securityDepositAmountCents: 50000)
        );

        $this->assertSame(3000, $settlement->securityDepositWithheldCents);
        $this->assertSame(47000, $settlement->securityDepositReturnCents);
        // And it did NOT enter the total: it is withheld, not invoiced.
        $this->assertSame(40000, $settlement->totalCents);
    }

    public function testWithholdingEverythingLeavesNothingToReturn(): void
    {
        $booking = $this->createBooking();
        $id = $this->service->reportIncident($booking, 'Dégâts majeurs', 50000, null, 1);
        $this->service->decideIncident($booking, $id, IncidentDecision::WITHHOLD, null, 1);

        $settlement = $this->service->recordSettlement(
            $booking,
            $this->assetId,
            40,
            [],
            1,
            new PaymentSettings(securityDepositEnabled: true, securityDepositAmountCents: 50000)
        );

        $this->assertSame(50000, $settlement->securityDepositWithheldCents);
        $this->assertSame(0, $settlement->securityDepositReturnCents);
    }
}
