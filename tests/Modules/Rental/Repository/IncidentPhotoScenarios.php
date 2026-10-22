<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Stay\IncidentPhoto;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\ReadingPhase;

/**
 * The photo writes of `RentalStayRepository` (#989), run on SQLite by
 * `RentalStayRepositoryTest` and on the production engine by
 * `RentalStayRepositoryOnMysqlTest`: each refusal is read by the statement
 * itself — an INSERT selecting from the table it writes, a DELETE and an
 * UPDATE behind a `NOT EXISTS` — which is exactly where the engines differ.
 *
 * The using class provides `$repository`, `$bookingId` and `$assetId`.
 *
 * @property RentalStayRepository $repository
 * @property int $bookingId
 * @property int $assetId
 */
trait IncidentPhotoScenarios
{
    /** @return list<int> */
    private function photoPositions(int $incidentId): array
    {
        return array_map(
            static fn(IncidentPhoto $photo): int => $photo->position,
            $this->repository->findIncident($incidentId)?->photos ?? []
        );
    }

    private function validatePhaseForPhotos(ReadingPhase $phase): void
    {
        $this->assertTrue($this->repository->recordInventoryValidation(
            $this->bookingId,
            $phase,
            new \DateTimeImmutable('2027-07-17 19:00:00'),
            null
        ));
    }

    public function testPhotosAreAddedAfterTheLastOneUpToTheLimit(): void
    {
        $incident = $this->repository->createIncident($this->bookingId, 'Vitre cassée', null, [11, 12], 7);
        $this->assertNotNull($incident);

        $this->assertTrue($this->repository->addIncidentPhotos($incident, $this->bookingId, [13], 7, 5));
        $this->assertSame([1, 2, 3], $this->photoPositions($incident));
        $this->assertSame(3, $this->repository->countIncidentPhotos($incident));

        // All or none: two more would make five, three would make six.
        $this->assertFalse($this->repository->addIncidentPhotos($incident, $this->bookingId, [14, 15, 16], 7, 5));
        $this->assertSame([1, 2, 3], $this->photoPositions($incident));
        $this->assertTrue($this->repository->addIncidentPhotos($incident, $this->bookingId, [14, 15], 7, 5));
        $this->assertSame([1, 2, 3, 4, 5], $this->photoPositions($incident));
    }

    /** A removed photo leaves a gap; the next one still goes last, never onto a taken position. */
    public function testAPhotoAddedAfterARemovalGoesLast(): void
    {
        $incident = (int) $this->repository->createIncident($this->bookingId, 'Vitre cassée', null, [11, 12, 13], 7);
        $middle = ($this->repository->findIncident($incident)?->photos ?? [])[1];

        $this->assertTrue($this->repository->deleteIncidentPhoto($middle->id, $this->bookingId));
        $this->assertTrue($this->repository->addIncidentPhotos($incident, $this->bookingId, [14], 7, 5));

        $this->assertSame([1, 3, 4], $this->photoPositions($incident));
    }

    public function testNoPhotoIsAddedToAnotherBookingsIncident(): void
    {
        $incident = (int) $this->repository->createIncident($this->bookingId, 'Vitre cassée', null, [], 7);

        $this->assertFalse($this->repository->addIncidentPhotos($incident, $this->bookingId + 1000, [11], 7, 5));
        $this->assertFalse($this->repository->addIncidentPhotos($incident + 1000, $this->bookingId, [11], 7, 5));
        $this->assertSame([], $this->photoPositions($incident));
    }

    /** The departure PDF lists the incidents: their photos freeze with it. */
    public function testIncidentPhotosFreezeWithTheDeparture(): void
    {
        $incident = (int) $this->repository->createIncident($this->bookingId, 'Vitre cassée', null, [11, 12], 7);
        $first = ($this->repository->findIncident($incident)?->photos ?? [])[0];

        // The arrival does not freeze them.
        $this->validatePhaseForPhotos(ReadingPhase::ARRIVAL);
        $this->assertTrue($this->repository->addIncidentPhotos($incident, $this->bookingId, [13], 7, 5));

        $this->validatePhaseForPhotos(ReadingPhase::DEPARTURE);
        $this->assertFalse($this->repository->addIncidentPhotos($incident, $this->bookingId, [14], 7, 5));
        $this->assertFalse($this->repository->deleteIncidentPhoto($first->id, $this->bookingId));
        $this->assertSame([1, 2, 3], $this->photoPositions($incident));
    }

    public function testAPhotoIsRemovedOnlyFromItsOwnBooking(): void
    {
        $incident = (int) $this->repository->createIncident($this->bookingId, 'Vitre cassée', null, [11], 7);
        $photo = ($this->repository->findIncident($incident)?->photos ?? [])[0];
        $this->assertSame(11, $this->repository->findIncidentPhoto($photo->id)?->fileId);

        $this->assertFalse($this->repository->deleteIncidentPhoto($photo->id, $this->bookingId + 1000));
        $this->assertTrue($this->repository->deleteIncidentPhoto($photo->id, $this->bookingId));
        $this->assertFalse($this->repository->deleteIncidentPhoto($photo->id, $this->bookingId), 'already gone');
        $this->assertNull($this->repository->findIncidentPhoto($photo->id));
    }

    /**
     * The UPDATE behind clearReadingPhoto() decides on rowCount(): a
     * non-null file becoming NULL changes the row, so both engines count it.
     */
    public function testAReadingsPhotoIsClearedOnlyOnAnOpenPhase(): void
    {
        $meterId = $this->repository->createMeter($this->assetId, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $at = new \DateTimeImmutable('2027-07-17 18:00:00');
        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $at, 21, null, 7);
        $reading = $this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL);
        $this->assertNotNull($reading);

        $this->assertFalse($this->repository->clearReadingPhoto($reading->id, $this->bookingId, 99, ReadingPhase::ARRIVAL));
        $this->assertTrue($this->repository->clearReadingPhoto($reading->id, $this->bookingId, 21, ReadingPhase::ARRIVAL));
        $this->assertNull($this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL)?->fileId);
        $this->assertFalse($this->repository->clearReadingPhoto($reading->id, $this->bookingId, 21, ReadingPhase::ARRIVAL));

        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 1_000_000, $at, 22, null, 7);
        $this->validatePhaseForPhotos(ReadingPhase::ARRIVAL);
        $this->assertFalse($this->repository->clearReadingPhoto($reading->id, $this->bookingId, 22, ReadingPhase::ARRIVAL));
        $this->assertSame(22, $this->repository->findReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL)?->fileId);
    }

    /** What the retention purge deletes: every incident photo and reading photo, nothing else. */
    public function testPhotoFileIdsListTheIncidentAndReadingPhotos(): void
    {
        $meterId = $this->repository->createMeter($this->assetId, 'Eau', MeterKind::WATER, 'm³', null);
        $at = new \DateTimeImmutable('2027-07-17 18:00:00');
        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::ARRIVAL, 5_000, $at, 31, null, 7);
        $this->repository->saveReading($this->bookingId, $meterId, ReadingPhase::DEPARTURE, 6_000, $at, null, null, 7);
        $this->repository->createIncident($this->bookingId, 'Vitre cassée', null, [41, 42], 7);
        $this->repository->createIncident($this->bookingId, 'Rayure', null, [], 7);

        $this->assertSame([31, 41, 42], $this->repository->photoFileIds($this->bookingId));
        $this->assertSame([], $this->repository->photoFileIds($this->bookingId + 1000));
    }
}
