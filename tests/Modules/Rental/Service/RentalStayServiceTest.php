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
use Modules\Rental\Audit\BookingAudit;
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
use Modules\Rental\Stay\InventoryKind;
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
        $bookingAudit = RentalTestHelper::bookingAudit($this->pdo, $this->encryption);

        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );

        $this->service = new RentalStayService(
            $this->stayRepository,
            $bookingAudit,
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
            $bookingAudit,
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
        $id = $this->assetRepository->create('Local', $name, $slug, 60, 1, null, null, null, true);
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

    public function testAReadingOfAValidatedInventoryIsRefused(): void
    {
        // The reading belongs to its inventory (#708, IT-17): the renter
        // holds the PDF it was printed in.
        $meterId = $this->addMeter();
        $booking = $this->createBooking();
        $this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), 1);

        $this->expectException(RentalException::class);

        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1
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
        $this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), 1);
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

        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $booking->id);
        $this->assertNotSame([], $history);
        $this->assertStringContainsString('Électricité', (string) $history[0]->toValue);
    }

    // ── Inventory snapshot (§6.23) ──────────────────────────────────────

    public function testTheChecklistIsCopiedIntoTheBookingAtConfirmation(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $this->service->addInventoryItem($this->assetId, 'Tables');
        $booking = $this->createBooking();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $this->operationsService->confirm($booking, $asset, 1, $this->now());

        $inventory = $this->service->inventoryFor($booking->id);
        $this->assertCount(2, $inventory);
        $this->assertSame('Clés', $inventory[0]['label']);
        // Nothing is pre-filled: an empty value is « nobody has looked yet ».
        $this->assertNull($inventory[0]['arrival_value']);
    }

    public function testEditingTheTemplateAfterwardsChangesNoExistingInventory(): void
    {
        // An item renamed in June must not rewrite what was checked in
        // March, and one deleted must not erase a finding.
        $itemId = $this->service->addInventoryItem($this->assetId, 'Chaises');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);

        $this->service->removeInventoryItem($this->assetId, $itemId);
        $this->service->addInventoryItem($this->assetId, 'Chaises (x40)');

        $inventory = $this->service->inventoryFor($booking->id);
        $this->assertCount(1, $inventory);
        $this->assertSame('Chaises', $inventory[0]['label']);
    }

    /**
     * The sort, the count and the dragged order are what a booking
     * confirmed afterwards copies (#708, IT-10).
     */
    public function testTheSortTheCountAndTheOrderAreCopiedAtConfirmation(): void
    {
        $keys = $this->service->addInventoryItem($this->assetId, 'Clés', InventoryKind::QUANTITY, 3);
        $kitchen = $this->service->addInventoryItem($this->assetId, 'Cuisine propre', InventoryKind::YES_NO);
        $this->service->reorderInventory($this->assetId, [$kitchen, $keys]);
        $this->service->updateInventoryItem($this->assetId, $keys, InventoryKind::QUANTITY, 4);
        $booking = $this->createBooking();
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        $this->operationsService->confirm($booking, $asset, 1, $this->now());

        $inventory = $this->service->inventoryFor($booking->id);
        $this->assertSame(['Cuisine propre', 'Clés'], array_column($inventory, 'label'));
        $this->assertSame(InventoryKind::YES_NO, $inventory[0]['kind']);
        $this->assertNull($inventory[0]['expected_count']);
        $this->assertSame(InventoryKind::QUANTITY, $inventory[1]['kind']);
        $this->assertSame(4, $inventory[1]['expected_count']);
    }

    public function testAQuantityItemDefaultsToOneAndRefusesLess(): void
    {
        $itemId = $this->service->addInventoryItem($this->assetId, 'Extincteur');
        $this->assertSame(1, $this->service->inventoryItem($this->assetId, $itemId)['expected_count'] ?? null);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage("au moins 1");

        $this->service->updateInventoryItem($this->assetId, $itemId, InventoryKind::QUANTITY, 0);
    }

    public function testAnotherAssetsTemplateItemCannotBeTouched(): void
    {
        $itemId = $this->service->addInventoryItem($this->assetId, 'Clés');

        try {
            $this->service->removeInventoryItem($this->assetId + 1000, $itemId);
            $this->fail('another asset removed this item');
        } catch (RentalException) {
        }

        $this->expectException(RentalException::class);
        $this->service->updateInventoryItem($this->assetId + 1000, $itemId, InventoryKind::YES_NO, null);
    }

    public function testTheSnapshotIsTakenExactlyOnce(): void
    {
        // Confirmation can be reached more than once; re-snapshotting would
        // overwrite a completed inventory with blanks.
        $this->service->addInventoryItem($this->assetId, 'Clés');
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
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $line = $this->service->inventoryFor($booking->id)[0];

        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::ARRIVAL, '2', null);
        $this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), null);
        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::DEPARTURE, '1', 'Un jeu manquant');

        $updated = $this->service->inventoryFor($booking->id)[0];
        $this->assertSame('2', $updated['arrival_value']);
        $this->assertSame('1', $updated['departure_value']);
        $this->assertSame('Un jeu manquant', $updated['departure_note']);
    }

    public function testAValueTheKindCannotHoldIsRefusedWithTheItemsName(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $line = $this->service->inventoryFor($booking->id)[0];

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('Clés : ');

        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::ARRIVAL, 'beaucoup', null);
    }

    public function testTheDepartureWaitsForTheArrival(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $line = $this->service->inventoryFor($booking->id)[0];

        try {
            $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::DEPARTURE, '2', null);
            $this->fail('A departure was written before its arrival.');
        } catch (RentalException $e) {
            $this->assertStringContainsString("une fois celui d'entrée validé", $e->getMessage());
        }

        // An arrival ticked by hand opens the departure all the same.
        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::DEPARTURE, '2', null, true);
        $this->assertSame('2', $this->service->inventoryFor($booking->id)[0]['departure_value']);
    }

    /**
     * An arrival ticked by hand is never validated itself, but the
     * departure's PDF is read against it: once that PDF has gone out, the
     * arrival is frozen with it — its values and its readings.
     */
    public function testAnArrivalTickedByHandIsFrozenOnceTheDepartureIsValidated(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $meterId = $this->addMeter();
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $line = $this->service->inventoryFor($booking->id)[0];
        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::ARRIVAL, '2', null, true);
        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::DEPARTURE, '2', null, true);

        $this->assertTrue($this->service->recordInventoryValidation($booking, ReadingPhase::DEPARTURE, $this->now(), 1));

        foreach ([
            fn() => $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::ARRIVAL, '1', null, true),
            fn() => $this->service->recordReading(
                $booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1, true
            ),
        ] as $write) {
            try {
                $write();
                $this->fail('An arrival was rewritten under a departure already sent.');
            } catch (RentalException $e) {
                $this->assertStringContainsString("celui d'entrée, sur lequel il se lit", $e->getMessage());
            }
        }
        $this->assertSame('2', $this->service->inventoryFor($booking->id)[0]['arrival_value']);
    }

    public function testAValidatedPhaseIsFrozen(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $line = $this->service->inventoryFor($booking->id)[0];
        $this->assertTrue($this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), null));

        // Twice is refused at the key, not by a read-then-write race.
        $this->assertFalse($this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), null));

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('ne se modifie plus');

        $this->service->setInventoryValue($booking, $line['id'], ReadingPhase::ARRIVAL, '2', null);
    }

    public function testAForgottenValidationReopensThePhase(): void
    {
        $booking = $this->createBooking();
        $this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), null);

        $this->service->forgetInventoryValidation($booking, ReadingPhase::ARRIVAL);

        $this->assertSame([], $this->service->inventoryValidations($booking->id));
        $this->service->assertPhaseOpen($booking->id, ReadingPhase::ARRIVAL);
    }

    public function testAnAssetKeepsItsInventoryOnlyWithItemsOrMeters(): void
    {
        $this->assertFalse($this->service->keepsInventory($this->assetId));

        $this->service->addInventoryItem($this->assetId, 'Clés');

        $this->assertTrue($this->service->keepsInventory($this->assetId));
    }

    public function testAnInventoryLineOfAnotherBookingCannotBeWritten(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $mine = $this->createBooking('LOC-2027-0001');
        $other = $this->createBooking('LOC-2027-0002');
        $this->service->snapshotInventory($other, $this->assetId);
        $foreignLine = $this->service->inventoryFor($other->id)[0];

        $this->expectException(RentalException::class);

        $this->service->setInventoryValue($mine, $foreignLine['id'], ReadingPhase::ARRIVAL, '1', null);
    }

    // ── « Valider l'état des lieux » (#708, IT-17) ──────────────────────

    private function validationService(
        ?\Core\Pdf\DocumentPdfService $pdf = null,
        ?\Modules\Rental\Service\RentalDocumentService $documents = null
    ): \Modules\Rental\Service\RentalInventoryValidationService {
        return new \Modules\Rental\Service\RentalInventoryValidationService(
            $this->service,
            $documents ?? $this->createStub(\Modules\Rental\Service\RentalDocumentService::class),
            $this->createStub(\Modules\Rental\Service\RentalBookingMailService::class),
            $pdf ?? $this->createStub(\Core\Pdf\DocumentPdfService::class),
            $this->createStub(\Core\Config\SettingService::class),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption)
        );
    }

    private function asset(): \Modules\Rental\Repository\RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    /**
     * Frozen first, then produced: a PDF that cannot be made takes the
     * validation back, so the inventory stays open rather than frozen
     * with nothing to show for it.
     */
    public function testAValidationWhosePdfFailsIsTakenBack(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $pdf = $this->createStub(\Core\Pdf\DocumentPdfService::class);
        $pdf->method('generate')->willThrowException(new \RuntimeException('dompdf'));

        try {
            $this->validationService($pdf)->validate(
                $booking, $this->asset(), ReadingPhase::ARRIVAL, 1, 'Anne', $this->now()
            );
            $this->fail('A validation without its PDF went through.');
        } catch (RentalException $e) {
            $this->assertStringContainsString("Rien n'a été validé", $e->getMessage());
        }

        $this->assertSame([], $this->service->inventoryValidations($booking->id));
    }

    /**
     * A PDF already filed when the validation is taken back goes with it:
     * left in Documents, it could still be sent to the renter as if the
     * inventory had been validated.
     */
    public function testAValidationTakenBackAfterItsPdfWasFiledTakesThePdfAway(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        // The step after the filing fails: the validation row cannot take
        // its document.
        $this->pdo->exec(
            "CREATE TRIGGER no_document BEFORE UPDATE OF document_id ON rental_inventory_validations
             BEGIN SELECT RAISE(ABORT, 'disque plein'); END"
        );
        $filed = new \Modules\Rental\Document\RentalDocument(
            id: 41,
            bookingId: $booking->id,
            fileId: 141,
            type: \Modules\Rental\Document\DocumentType::INVENTORY,
            version: 1,
            isForRenter: true,
            originalName: 'etat-des-lieux-entree.pdf',
            sizeBytes: 1024,
            sentAt: null,
            createdByMemberId: 1,
            createdAt: $this->now()
        );
        $documents = $this->createMock(\Modules\Rental\Service\RentalDocumentService::class);
        $documents->method('attachPdf')->willReturn($filed);
        $documents->expects($this->once())->method('delete')->with($filed, 1);

        try {
            $this->validationService(null, $documents)->validate(
                $booking, $this->asset(), ReadingPhase::ARRIVAL, 1, 'Anne', $this->now()
            );
            $this->fail('A validation whose document could not be attached went through.');
        } catch (RentalException $e) {
            $this->assertStringContainsString("Rien n'a été validé", $e->getMessage());
        }

        $this->assertSame([], $this->service->inventoryValidations($booking->id));
    }

    /**
     * Once the e-mail has left, failing to record it must not tell the
     * manager to send it again: the renter would get the PDF twice.
     */
    public function testASendThatWentOutIsSaidSentEvenIfRecordingItFails(): void
    {
        $this->service->addInventoryItem($this->assetId, 'Clés');
        $booking = $this->createBooking();
        $this->service->snapshotInventory($booking, $this->assetId);
        $documents = $this->createStub(\Modules\Rental\Service\RentalDocumentService::class);
        $documents->method('attachPdf')->willReturn(new \Modules\Rental\Document\RentalDocument(
            id: 41,
            bookingId: $booking->id,
            fileId: 141,
            type: \Modules\Rental\Document\DocumentType::INVENTORY,
            version: 1,
            isForRenter: true,
            originalName: 'etat-des-lieux-entree.pdf',
            sizeBytes: 1024,
            sentAt: null,
            createdByMemberId: 1,
            createdAt: $this->now()
        ));
        $documents->method('absolutePath')->willReturn(__FILE__);
        $documents->method('markSent')->willThrowException(new \RuntimeException('verrou'));

        $result = $this->validationService(null, $documents)->validate(
            $booking, $this->asset(), ReadingPhase::ARRIVAL, 1, 'Anne', $this->now()
        );

        $this->assertTrue($result['sent']);
        $this->assertArrayHasKey('arrival', $this->service->inventoryValidations($booking->id));
    }

    /**
     * An arrival ticked by hand is frozen by the departure's validation:
     * its meter readings must be in by then, or the consumption could
     * never be billed. A validated arrival is not asked again.
     */
    public function testTheDepartureAsksForTheArrivalReadingsOfAnArrivalTickedByHand(): void
    {
        $meterId = $this->addMeter();
        $booking = $this->createBooking();
        $this->service->recordReading(
            $booking, $this->assetId, $meterId, ReadingPhase::DEPARTURE, '1500', $this->now(), null, null, 1, true
        );

        $this->assertSame(
            ['Électricité (entrée)'],
            $this->validationService()->missingReadings($booking, $this->assetId, ReadingPhase::DEPARTURE)
        );
        try {
            $this->validationService()->validate(
                $booking, $this->asset(), ReadingPhase::DEPARTURE, 1, 'Anne', $this->now(), true
            );
            $this->fail('A departure froze an arrival still missing its reading.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('Il manque le relevé de : Électricité (entrée)', $e->getMessage());
        }

        // Once the arrival is validated with its reading, nothing more is asked of it.
        $other = $this->createBooking('LOC-2027-0002');
        $this->service->recordReading($other, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $this->now(), null, null, 1);
        $this->assertTrue($this->service->recordInventoryValidation($other, ReadingPhase::ARRIVAL, $this->now(), 1));
        $this->assertSame(
            ['Électricité'],
            $this->validationService()->missingReadings($other, $this->assetId, ReadingPhase::DEPARTURE)
        );
    }

    public function testAMeterWithoutItsReadingBlocksTheValidation(): void
    {
        $this->addMeter();
        $booking = $this->createBooking();

        $this->assertSame(['Électricité'], $this->validationService()->missingReadings($booking, $this->assetId, ReadingPhase::ARRIVAL));

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('Il manque le relevé de : Électricité');

        $this->validationService()->validate($booking, $this->asset(), ReadingPhase::ARRIVAL, 1, 'Anne', $this->now());
    }

    public function testAnAssetWithNothingToWalkHasNothingToValidate(): void
    {
        $booking = $this->createBooking();

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('se coche à la main');

        $this->validationService()->validate($booking, $this->asset(), ReadingPhase::ARRIVAL, 1, 'Anne', $this->now());
    }

    // ── Incidents (§6.23) ───────────────────────────────────────────────

    public function testNoIncidentIsReportedOnceTheDepartureIsValidated(): void
    {
        // The departure's PDF lists the incidents (#708, IT-17): one added
        // afterwards would be missing from what the renter holds.
        $booking = $this->createBooking();
        $this->service->recordInventoryValidation($booking, ReadingPhase::DEPARTURE, $this->now(), 1);

        $this->expectException(RentalException::class);

        $this->service->reportIncident($booking, 'Vitre cassée', 5000, null, 1);
    }

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
        $this->service->recordInventoryValidation($booking, ReadingPhase::ARRIVAL, $this->now(), 1);
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
