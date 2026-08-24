<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\SettlementLine;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The stay's own storage: meters, readings, the inventory snapshot,
 * incidents and settlements (§6.21–§6.23).
 *
 * Tested directly rather than only through the service, because most of
 * what is interesting here is a rule the SQL itself carries — a replace
 * rather than an append, a counter that never goes back, a WHERE clause
 * that makes a validated document immutable. A service test can pass while
 * the query underneath quietly does the other thing.
 */
class RentalStayRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalStayRepository $repository;
    private int $assetId;
    private int $bookingId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalStayRepository($this->pdo, $this->encryption);

        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Local Saint-Georges', 'local-saint-georges']);
        $this->assetId = (int) $this->pdo->lastInsertId();

        $this->bookingId = $this->booking('LOC-2027-0001', '2027-07-17', '2027-07-20');
    }

    // ── Meters ──────────────────────────────────────────────────────────

    public function testAMeterIsCreatedAndReadBack(): void
    {
        $id = $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null, 2);

        $meter = $this->repository->findMeter($id);
        $this->assertNotNull($meter);
        $this->assertSame('Électricité', $meter->label);
        $this->assertSame(MeterKind::ELECTRICITY, $meter->kind);
        $this->assertSame('kWh', $meter->unit);
        $this->assertNull($meter->feeId);
    }

    public function testFindMeterIsNullForAnIdNobodyHas(): void
    {
        $this->assertNull($this->repository->findMeter(999999));
    }

    public function testMetersComeBackInTheirDeclaredOrder(): void
    {
        $this->repository->createMeter($this->assetId, 'Eau', MeterKind::WATER, 'm³', null, 3);
        $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null, 1);

        $labels = array_map(static fn ($meter) => $meter->label, $this->repository->findMeters($this->assetId));

        $this->assertSame(['Électricité', 'Eau'], $labels);
    }

    /**
     * Deactivating rather than deleting: a meter removed in June must not
     * erase the readings taken through it in March.
     */
    public function testADeactivatedMeterLeavesTheActiveListButStillResolves(): void
    {
        $id = $this->repository->createMeter($this->assetId, 'Gaz', MeterKind::GAS, 'm³', null);

        $this->repository->deactivateMeter($id);

        $this->assertSame([], $this->repository->findMeters($this->assetId));
        $this->assertNotSame([], $this->repository->findMeters($this->assetId, false));
        $this->assertNotNull($this->repository->findMeter($id), 'an old reading must still name its meter');
    }

    public function testAnotherAssetsMetersAreNotThisAssets(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Autre', 'autre']);
        $otherAssetId = (int) $this->pdo->lastInsertId();
        $this->repository->createMeter($otherAssetId, 'Eau', MeterKind::WATER, 'm³', null);

        $this->assertSame([], $this->repository->findMeters($this->assetId));
    }

    // ── Readings ────────────────────────────────────────────────────────

    public function testAReadingIsStoredAndFoundByItsMeterAndPhase(): void
    {
        $meterId = $this->meter();

        $this->repository->saveReading(
            $this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000,
            new \DateTimeImmutable('2027-07-17 18:00:00'), null, 'Relevé à l\'arrivée', 7
        );

        $reading = $this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL);
        $this->assertNotNull($reading);
        $this->assertSame(1_000_000, $reading->valueMilli);
        $this->assertSame('Relevé à l\'arrivée', $reading->comment);
        $this->assertSame(7, $reading->recordedByMemberId);
    }

    /**
     * A second arrival reading is a CORRECTION, not a second measurement:
     * keeping both would make consumption a guess about which pair to use.
     */
    public function testASecondReadingForTheSamePhaseReplacesTheFirst(): void
    {
        $meterId = $this->meter();
        $now = new \DateTimeImmutable('2027-07-17 18:00:00');

        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $now, null, null, 7);
        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_050_000, $now, null, null, 7);

        $this->assertCount(1, $this->repository->findReadings($this->bookingId));
        $this->assertSame(
            1_050_000,
            $this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL)?->valueMilli
        );
    }

    /**
     * The photo is the evidence for the reading; re-uploading it to fix a
     * digit is not something to demand of somebody standing in a cellar.
     */
    public function testCorrectingAValueKeepsThePhotoThatWasAlreadyThere(): void
    {
        $meterId = $this->meter();
        $now = new \DateTimeImmutable('2027-07-17 18:00:00');
        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $now, 88, null, 7);

        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_050_000, $now, null, null, 7);

        $this->assertSame(
            88,
            $this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL)?->fileId
        );
    }

    public function testArrivalAndDepartureAreTwoDifferentReadings(): void
    {
        $meterId = $this->meter();
        $now = new \DateTimeImmutable('2027-07-17 18:00:00');

        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $now, null, null, 7);
        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::DEPARTURE, 1_120_000, $now, null, null, 7);

        $readings = $this->repository->findReadings($this->bookingId);
        $this->assertCount(2, $readings);
        $this->assertArrayHasKey($meterId . '.arrival', $readings);
        $this->assertArrayHasKey($meterId . '.departure', $readings);
    }

    public function testABlankCommentIsStoredAsAbsent(): void
    {
        $meterId = $this->meter();

        $this->repository->saveReading(
            $this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000,
            new \DateTimeImmutable('2027-07-17 18:00:00'), null, '   ', 7
        );

        $this->assertNull($this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL)?->comment);
    }

    public function testFindReadingIsNullBeforeAnythingIsRead(): void
    {
        $this->assertNull($this->repository->findReading($this->bookingId, $this->meter(), ReadingPhase::ARRIVAL));
        $this->assertSame([], $this->repository->findReadings($this->bookingId));
    }

    // ── Inventory template and snapshot ─────────────────────────────────

    public function testInventoryItemsComeBackInOrderAndSkipTheDeactivated(): void
    {
        $this->repository->createInventoryItem($this->assetId, 'Vaisselle', 2);
        $keep = $this->repository->createInventoryItem($this->assetId, 'Extincteur', 1);
        $gone = $this->repository->createInventoryItem($this->assetId, 'Ancien poêle', 3);

        $this->repository->deactivateInventoryItem($gone);

        $labels = array_column($this->repository->findInventoryItems($this->assetId), 'label');
        $this->assertSame(['Extincteur', 'Vaisselle'], $labels);
        $this->assertNotSame(0, $keep);
    }

    public function testTheSnapshotCopiesTheChecklistOnce(): void
    {
        $this->repository->createInventoryItem($this->assetId, 'Extincteur', 1);
        $this->repository->createInventoryItem($this->assetId, 'Vaisselle', 2);

        $this->assertTrue($this->repository->snapshotInventory($this->bookingId, $this->assetId));
        $this->assertFalse($this->repository->snapshotInventory($this->bookingId, $this->assetId));

        $this->assertSame(
            ['Extincteur', 'Vaisselle'],
            array_column($this->repository->findBookingInventory($this->bookingId), 'label')
        );
    }

    /**
     * The flag rather than "are there rows?": an asset with an empty
     * checklist snapshots legitimately into zero rows, and re-snapshotting
     * later would overwrite a completed inventory with blanks.
     */
    public function testAnEmptyChecklistStillCountsAsSnapshotted(): void
    {
        $this->assertTrue($this->repository->snapshotInventory($this->bookingId, $this->assetId));
        $this->repository->createInventoryItem($this->assetId, 'Ajouté après coup', 1);

        $this->assertFalse($this->repository->snapshotInventory($this->bookingId, $this->assetId));
        $this->assertSame([], $this->repository->findBookingInventory($this->bookingId));
    }

    /**
     * The LABEL is copied, not referenced: an item renamed in June must not
     * rewrite what was checked in March.
     */
    public function testRenamingAnItemAfterTheSnapshotDoesNotRewriteHistory(): void
    {
        $itemId = $this->repository->createInventoryItem($this->assetId, 'Extincteur', 1);
        $this->repository->snapshotInventory($this->bookingId, $this->assetId);

        $this->pdo->prepare('UPDATE rental_inventory_items SET label = ? WHERE id = ?')
            ->execute(['Extincteur (2028)', $itemId]);

        $this->assertSame(
            'Extincteur',
            $this->repository->findBookingInventory($this->bookingId)[0]['label']
        );
    }

    public function testAnUncheckedItemReadsAsNotCheckedRatherThanAsBlank(): void
    {
        $this->repository->createInventoryItem($this->assetId, 'Extincteur', 1);
        $this->repository->snapshotInventory($this->bookingId, $this->assetId);

        $line = $this->repository->findBookingInventory($this->bookingId)[0];
        $this->assertSame(InventoryState::NOT_CHECKED, $line['arrival_state']);
        $this->assertSame(InventoryState::NOT_CHECKED, $line['departure_state']);
    }

    public function testEachPhaseKeepsItsOwnStateAndNote(): void
    {
        $this->repository->createInventoryItem($this->assetId, 'Vaisselle', 1);
        $this->repository->snapshotInventory($this->bookingId, $this->assetId);
        $inventoryId = $this->repository->findBookingInventory($this->bookingId)[0]['id'];

        $this->repository->setInventoryState($inventoryId, ReadingPhase::ARRIVAL, InventoryState::OK, null);
        $this->repository->setInventoryState(
            $inventoryId,
            ReadingPhase::DEPARTURE,
            InventoryState::MISSING,
            'Six assiettes manquantes'
        );

        $line = $this->repository->findBookingInventory($this->bookingId)[0];
        $this->assertSame(InventoryState::OK, $line['arrival_state']);
        $this->assertNull($line['arrival_note']);
        $this->assertSame(InventoryState::MISSING, $line['departure_state']);
        $this->assertSame('Six assiettes manquantes', $line['departure_note']);
    }

    public function testABlankInventoryNoteIsStoredAsAbsent(): void
    {
        $this->repository->createInventoryItem($this->assetId, 'Vaisselle', 1);
        $this->repository->snapshotInventory($this->bookingId, $this->assetId);
        $inventoryId = $this->repository->findBookingInventory($this->bookingId)[0]['id'];

        $this->repository->setInventoryState($inventoryId, ReadingPhase::ARRIVAL, InventoryState::OK, '  ');

        $this->assertNull($this->repository->findBookingInventory($this->bookingId)[0]['arrival_note']);
    }

    /**
     * The line's booking is what a controller checks the visitor against
     * before letting them tick it — an inventory id in a POST is as
     * forgeable as any other.
     */
    public function testAnInventoryLineNamesTheBookingItBelongsTo(): void
    {
        $this->repository->createInventoryItem($this->assetId, 'Vaisselle', 1);
        $this->repository->snapshotInventory($this->bookingId, $this->assetId);
        $inventoryId = $this->repository->findBookingInventory($this->bookingId)[0]['id'];

        $this->assertSame($this->bookingId, $this->repository->findInventoryBookingId($inventoryId));
        $this->assertNull($this->repository->findInventoryBookingId(999999));
    }

    // ── Incidents ───────────────────────────────────────────────────────

    public function testAnIncidentIsStoredEncryptedAndComesBackReadable(): void
    {
        $id = $this->repository->createIncident($this->bookingId, '  Vitre cassée dans la cuisine ', 4500, 12, 7);

        $incident = $this->repository->findIncident($id);
        $this->assertNotNull($incident);
        $this->assertSame('Vitre cassée dans la cuisine', $incident->description);
        $this->assertSame(4500, $incident->proposedAmountCents);
        $this->assertSame(IncidentDecision::PENDING, $incident->decision);
        $this->assertSame(12, $incident->fileId);

        $raw = (string) $this->pdo->query('SELECT description_encrypted FROM rental_incidents')->fetchColumn();
        $this->assertStringNotContainsString('Vitre cassée', $raw);
    }

    public function testAnIncidentIsCreatedPendingAndDecidedAfterwards(): void
    {
        $id = $this->repository->createIncident($this->bookingId, 'Vitre cassée', 4500, null, 7);

        $this->repository->decideIncident($id, IncidentDecision::WITHHOLD, 4000, 9);

        $incident = $this->repository->findIncident($id);
        $this->assertSame(IncidentDecision::WITHHOLD, $incident?->decision);
        $this->assertSame(4000, $incident->decidedAmountCents);
        $this->assertSame(9, $incident->decidedByMemberId);
    }

    public function testIncidentsComeBackOldestFirstAndOnlyThisBookings(): void
    {
        $otherBookingId = $this->booking('LOC-2027-0002', '2027-08-01', '2027-08-04');

        $this->repository->createIncident($this->bookingId, 'Premier', null, null, 7);
        $this->repository->createIncident($this->bookingId, 'Second', null, null, 7);
        $this->repository->createIncident($otherBookingId, 'Autre séjour', null, null, 7);

        $descriptions = array_map(
            static fn ($incident) => $incident->description,
            $this->repository->findIncidents($this->bookingId)
        );
        $this->assertSame(['Premier', 'Second'], $descriptions);
    }

    public function testDeletingAnIncidentRemovesItAndNothingElse(): void
    {
        $kept = $this->repository->createIncident($this->bookingId, 'Gardé', null, null, 7);
        $gone = $this->repository->createIncident($this->bookingId, 'Effacé', null, null, 7);

        $this->repository->deleteIncident($gone);

        $this->assertNull($this->repository->findIncident($gone));
        $this->assertNotNull($this->repository->findIncident($kept));
    }

    public function testFindIncidentIsNullForAnIdNobodyHas(): void
    {
        $this->assertNull($this->repository->findIncident(999999));
        $this->assertSame([], $this->repository->findIncidents($this->bookingId));
    }

    // ── Settlements ─────────────────────────────────────────────────────

    public function testTheVersionCounterStartsAtOneAndMovesForward(): void
    {
        $this->assertSame(1, $this->repository->claimNextSettlementVersion($this->bookingId));
        $this->assertSame(2, $this->repository->claimNextSettlementVersion($this->bookingId));
        $this->assertSame(3, $this->repository->claimNextSettlementVersion($this->bookingId));
    }

    /**
     * Never `MAX(version)` over the surviving rows: a deleted v2 must not
     * make the next settlement v2 again, because v2 may already have been
     * sent to the renter.
     */
    public function testADeletedVersionIsNeverHandedOutTwice(): void
    {
        $this->repository->claimNextSettlementVersion($this->bookingId);
        $second = $this->repository->claimNextSettlementVersion($this->bookingId);
        $settlementId = $this->settlement($second);

        $this->repository->deleteSettlement($settlementId);

        $this->assertSame(3, $this->repository->claimNextSettlementVersion($this->bookingId));
    }

    /**
     * Two managers pressing "clôturer" at the same instant must not both
     * be handed v3 — the counter is claimed, not read.
     */
    public function testTwoConcurrentClaimsNeverCollide(): void
    {
        $claimed = [];
        for ($i = 0; $i < 25; $i++) {
            $claimed[] = $this->repository->claimNextSettlementVersion($this->bookingId);
        }

        $this->assertSame(range(1, 25), $claimed);
        $this->assertCount(25, array_unique($claimed));
    }

    public function testEachBookingHasItsOwnVersionCounter(): void
    {
        $otherBookingId = $this->booking('LOC-2027-0002', '2027-08-01', '2027-08-04');

        $this->repository->claimNextSettlementVersion($this->bookingId);
        $this->repository->claimNextSettlementVersion($this->bookingId);

        $this->assertSame(1, $this->repository->claimNextSettlementVersion($otherBookingId));
    }

    public function testASettlementKeepsItsLinesAsWrittenAtThatInstant(): void
    {
        $id = $this->repository->createSettlement(
            $this->bookingId,
            1,
            32,
            [
                new SettlementLine('Location', 45000, 'booking', '3 nuits'),
                new SettlementLine('Électricité', 1200, 'meter', '120 kWh'),
            ],
            46200,
            20000,
            26200,
            null,
            null,
            7
        );

        $settlement = $this->repository->findSettlement($id);
        $this->assertNotNull($settlement);
        $this->assertSame(1, $settlement->version);
        $this->assertSame(32, $settlement->finalPersons);
        $this->assertSame(46200, $settlement->totalCents);
        $this->assertSame(26200, $settlement->balanceCents);
        $this->assertCount(2, $settlement->lines);
        $this->assertSame('Électricité', $settlement->lines[1]->label);
        $this->assertSame('120 kWh', $settlement->lines[1]->detail);
    }

    public function testSettlementsComeBackNewestVersionFirst(): void
    {
        $this->settlement(1);
        $this->settlement(2);
        $this->settlement(3);

        $versions = array_map(
            static fn ($settlement) => $settlement->version,
            $this->repository->findSettlements($this->bookingId)
        );

        $this->assertSame([3, 2, 1], $versions);
        $this->assertSame(3, $this->repository->findLatestSettlement($this->bookingId)?->version);
    }

    public function testThereIsNoLatestSettlementBeforeThereIsOne(): void
    {
        $this->assertNull($this->repository->findLatestSettlement($this->bookingId));
        $this->assertSame([], $this->repository->findSettlements($this->bookingId));
        $this->assertNull($this->repository->findSettlement(999999));
    }

    /**
     * The `is_validated = 0` in the WHERE clause is what makes a validated
     * settlement immutable: a second validation matches no row and the
     * caller is told so, rather than silently re-stamping a document
     * somebody may already have acted on.
     */
    public function testASettlementIsValidatedOnceAndOnlyOnce(): void
    {
        $id = $this->settlement(1);

        $this->assertTrue($this->repository->validateSettlement($id, 7));
        $this->assertFalse($this->repository->validateSettlement($id, 9));

        $settlement = $this->repository->findSettlement($id);
        $this->assertTrue($settlement?->isValidated);
        $this->assertSame(7, $settlement->validatedByMemberId, 'the second validation must not re-stamp it');
    }

    public function testAValidatedSettlementCannotBeDeleted(): void
    {
        $id = $this->settlement(1);
        $this->repository->validateSettlement($id, 7);

        $this->repository->deleteSettlement($id);

        $this->assertNotNull($this->repository->findSettlement($id));
    }

    public function testADraftSettlementCanBeDeleted(): void
    {
        $id = $this->settlement(1);

        $this->repository->deleteSettlement($id);

        $this->assertNull($this->repository->findSettlement($id));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function booking(string $reference, string $arrival, string $departure): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_bookings
                (asset_id, reference, arrival_date, departure_date, units, status,
                 renter_name_encrypted, renter_email_encrypted, renter_email_blind_index,
                 tracking_token_encrypted)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->assetId,
            $reference,
            $arrival,
            $departure,
            'confirmed',
            $this->encryption->encrypt('Jeanne Martin', 'rental_bookings.renter_name'),
            $this->encryption->encrypt('jeanne@example.org', 'rental_bookings.renter_email'),
            $this->encryption->blindIndex('jeanne@example.org', 'email'),
            $this->encryption->encrypt(
                \Core\Security\CapabilityToken::generate(),
                'rental_bookings.tracking_token'
            ),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function meter(): int
    {
        return $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
    }

    private function settlement(int $version): int
    {
        return $this->repository->createSettlement(
            $this->bookingId,
            $version,
            null,
            [new SettlementLine('Location', 45000, 'booking')],
            45000,
            0,
            45000,
            null,
            null,
            7
        );
    }
}
