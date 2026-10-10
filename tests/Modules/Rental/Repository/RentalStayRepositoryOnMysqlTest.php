<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\InventoryKind;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;
use Tests\UsesProductionEngine;

/**
 * An inventory line, a meter reading or an incident never written onto a validated
 * phase (#708, IT-17), on the engine production runs.
 *
 * `RentalStayRepository::setInventoryValue()`, `saveReading()` and `createIncident()` refuse
 * the write in the statement itself (`NOT EXISTS` on the phase's
 * validation) and report it through `rowCount()`. That count is where the
 * engines differ: SQLite counts the rows MATCHED, MySQL and MariaDB the
 * rows CHANGED, so on production a save that changes nothing reads exactly
 * like a refused one. `RentalStayService` therefore asks the phase again
 * whenever no row changed. This class holds both halves where it matters:
 * the refusal, and an unchanged save that must not be mistaken for one.
 */
#[Group('database')]
final class RentalStayRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalStayRepository $repository;
    private RentalBookingRepository $bookings;
    private int $assetId;
    private int $bookingId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalStayRepository($this->pdo, $this->encryption);
        $this->bookings = new RentalBookingRepository($this->pdo, $this->encryption);

        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $this->assetId = (int) $this->pdo->lastInsertId();

        $created = $this->bookings->create(
            $this->assetId,
            'LOC-A2B3C4',
            '2027-07-17',
            '2027-07-20',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
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
        $this->bookingId = $created['id'];
    }

    /**
     * The premise: this engine counts changed rows, not matched ones. Were
     * it to count matched rows, an unchanged save would report true and the
     * service's second look would never be exercised here.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $inventoryId = $this->inventoryLine();
        $this->pdo->prepare('UPDATE rental_booking_inventory SET arrival_value = ? WHERE id = ?')
            ->execute(['4', $inventoryId]);

        $update = $this->pdo->prepare('UPDATE rental_booking_inventory SET arrival_value = ? WHERE id = ?');
        $update->execute(['4', $inventoryId]);

        $this->assertSame(
            0,
            $update->rowCount(),
            'if this ever reports 1, FOUND_ROWS got turned on and an unchanged save no longer reads as refused.'
        );
    }

    public function testAValueIsNeverWrittenOntoAValidatedPhase(): void
    {
        $inventoryId = $this->inventoryLine();
        $this->assertTrue($this->repository->setInventoryValue($inventoryId, ReadingPhase::ARRIVAL, '4', null));

        $this->validate(ReadingPhase::ARRIVAL);

        $this->assertFalse(
            $this->repository->setInventoryValue($inventoryId, ReadingPhase::ARRIVAL, '3', 'Trop tard')
        );
        $this->assertTrue($this->repository->setInventoryValue($inventoryId, ReadingPhase::DEPARTURE, '3', null));
        $line = $this->repository->findBookingInventory($this->bookingId)[0];
        $this->assertSame('4', $line['arrival_value']);
        $this->assertNull($line['arrival_note']);
        $this->assertSame('3', $line['departure_value']);
    }

    public function testAReadingIsNeverWrittenOntoAValidatedPhase(): void
    {
        $meterId = $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $otherMeterId = $this->repository->createMeter($this->assetId, 'Eau', MeterKind::WATER, 'm³', null);
        $at = new \DateTimeImmutable('2027-07-17 18:00:00');
        $this->assertTrue(
            $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $at, null, null, 7)
        );

        $this->validate(ReadingPhase::ARRIVAL);

        // Neither the correction (UPDATE) nor a first reading (INSERT … SELECT).
        $this->assertFalse(
            $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_050_000, $at, null, null, 7)
        );
        $this->assertFalse(
            $this->repository->saveReading($this->bookingId, $otherMeterId, ReadingPhase::ARRIVAL, 5_000, $at, null, null, 7)
        );
        $this->assertSame(
            1_000_000,
            $this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL)?->valueMilli
        );
        $this->assertNull($this->repository->findReading($this->bookingId, $otherMeterId, ReadingPhase::ARRIVAL));
        $this->assertTrue(
            $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::DEPARTURE, 1_120_000, $at, null, null, 7)
        );
    }

    public function testAValidatedDepartureFreezesTheArrivalItWasReadAgainst(): void
    {
        $inventoryId = $this->inventoryLine();
        $meterId = $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $otherMeterId = $this->repository->createMeter($this->assetId, 'Eau', MeterKind::WATER, 'm³', null);
        $at = new \DateTimeImmutable('2027-07-17 18:00:00');
        $this->assertTrue($this->repository->setInventoryValue($inventoryId, ReadingPhase::ARRIVAL, '4', null));
        $this->assertTrue(
            $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $at, null, null, 7)
        );

        // The arrival ticked by hand: only the departure is validated.
        $this->validate(ReadingPhase::DEPARTURE);

        $this->assertFalse($this->repository->setInventoryValue($inventoryId, ReadingPhase::ARRIVAL, '3', null));
        $this->assertFalse(
            $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 900_000, $at, null, null, 7)
        );
        $this->assertFalse(
            $this->repository->saveReading($this->bookingId, $otherMeterId, ReadingPhase::ARRIVAL, 5_000, $at, null, null, 7)
        );
        $this->assertSame('4', $this->repository->findBookingInventory($this->bookingId)[0]['arrival_value']);
    }

    /**
     * Only the unique key reads as « already validated »: a foreign-key
     * failure shares SQLSTATE 23000 and must surface, not be told to the
     * manager as a validation that never happened.
     */
    public function testOnlyTheUniqueKeyReadsAsAlreadyValidated(): void
    {
        $at = new \DateTimeImmutable('2027-07-17 19:00:00');
        $this->assertTrue($this->repository->recordInventoryValidation($this->bookingId, ReadingPhase::ARRIVAL, $at, null));
        $this->assertFalse($this->repository->recordInventoryValidation($this->bookingId, ReadingPhase::ARRIVAL, $at, null));

        $this->expectException(\PDOException::class);
        $this->repository->recordInventoryValidation($this->bookingId + 1000, ReadingPhase::ARRIVAL, $at, null);
    }

    public function testNoIncidentIsRecordedOnceTheDepartureIsValidated(): void
    {
        $this->validate(ReadingPhase::ARRIVAL);
        $this->assertNotNull($this->repository->createIncident($this->bookingId, 'Avant', null, null, 7));

        $this->validate(ReadingPhase::DEPARTURE);

        $this->assertNull($this->repository->createIncident($this->bookingId, 'Trop tard', 2000, null, 7));
        $this->assertSame(
            ['Avant'],
            array_map(static fn ($incident) => $incident->description, $this->repository->findIncidents($this->bookingId))
        );
    }

    /**
     * The engine-specific half: on this engine a save that changes nothing
     * reports no row, exactly like a refused one, and the service must
     * tell them apart rather than refuse a manager who re-typed the same
     * value — while still refusing a validated phase.
     */
    public function testAnUnchangedSaveIsNotMistakenForARefusedOne(): void
    {
        $inventoryId = $this->inventoryLine();
        $meterId = $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $at = new \DateTimeImmutable('2027-07-17 18:00:00');
        $service = $this->service();
        $booking = $this->booking();

        $service->setInventoryValue($booking, $inventoryId, ReadingPhase::ARRIVAL, '4', null);
        $service->recordReading($booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $at, null, null, 7);
        // Same value, same second: no row changes, and nothing is refused.
        $this->assertFalse($this->repository->setInventoryValue($inventoryId, ReadingPhase::ARRIVAL, '4', null));
        $service->setInventoryValue($booking, $inventoryId, ReadingPhase::ARRIVAL, '4', null);
        $service->recordReading($booking, $this->assetId, $meterId, ReadingPhase::ARRIVAL, '1000', $at, null, null, 7);

        $this->validate(ReadingPhase::ARRIVAL);

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('validé et envoyé au locataire');
        $service->setInventoryValue($booking, $inventoryId, ReadingPhase::ARRIVAL, '4', null);
    }

    private function inventoryLine(): int
    {
        $this->repository->createInventoryItem($this->assetId, 'Trousseau de clés', InventoryKind::QUANTITY, 4);
        $this->repository->snapshotInventory($this->bookingId, $this->assetId);

        return $this->repository->findBookingInventory($this->bookingId)[0]['id'];
    }

    private function validate(ReadingPhase $phase): void
    {
        $this->assertTrue($this->repository->recordInventoryValidation(
            $this->bookingId,
            $phase,
            new \DateTimeImmutable('2027-07-17 19:00:00'),
            null
        ));
    }

    private function booking(): RentalBooking
    {
        $booking = $this->bookings->findById($this->bookingId);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function service(): RentalStayService
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new RentalStayService(
            $this->repository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal),
            new SettlementCalculator(),
            $journal
        );
    }
}
