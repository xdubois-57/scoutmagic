<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\PlaceArchiveService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class PlaceArchiveServiceTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private CampRepository $camps;
    private PlaceArchiveService $service;
    private int $placeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->service = new PlaceArchiveService(
            $this->places,
            $this->camps,
            new AuditService(new AuditRepository($this->pdo, $encryption))
        );
        $this->placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
    }

    public function testAPlaceWithNothingUpcomingArchivesCleanly(): void
    {
        $this->stay('2024-07-19', Camp::STATUS_CONFIRMED);

        $this->service->archive($this->place(), 42, $this->today());

        $this->assertTrue($this->places->findById($this->placeId)?->isArchived);
    }

    public function testAConfirmedUpcomingStayBlocksArchiving(): void
    {
        $this->stay('2028-07-19', Camp::STATUS_CONFIRMED);

        // A place hidden from search while the unit is booked to leave
        // for it in July is how a staff loses the address of the field
        // they are going to.
        $this->expectException(CampsException::class);
        $this->service->archive($this->place(), 42, $this->today());
    }

    public function testABlockedArchiveChangesNothing(): void
    {
        $this->stay('2028-07-19', Camp::STATUS_CONFIRMED);

        try {
            $this->service->archive($this->place(), 42, $this->today());
            $this->fail('archiving should have been refused');
        } catch (CampsException) {
        }

        $this->assertFalse($this->places->findById($this->placeId)?->isArchived);
    }

    public function testAnUnconfirmedUpcomingStayOnlyWarns(): void
    {
        $this->stay('2028-07-19', Camp::STATUS_TO_CONFIRM);

        // An unconfirmed booking is often exactly what somebody is
        // archiving the place to get away from.
        $warning = $this->service->pendingWarning($this->place(), $this->today());
        $this->assertNotNull($warning);
        $this->assertStringContainsString('à confirmer', $warning);

        $this->service->archive($this->place(), 42, $this->today());
        $this->assertTrue($this->places->findById($this->placeId)?->isArchived);
    }

    public function testACancelledUpcomingStayNeitherBlocksNorWarns(): void
    {
        $this->stay('2028-07-19', Camp::STATUS_CANCELLED);

        $this->assertNull($this->service->pendingWarning($this->place(), $this->today()));
        $this->service->archive($this->place(), 42, $this->today());
        $this->assertTrue($this->places->findById($this->placeId)?->isArchived);
    }

    public function testArchivingHidesThePlaceFromEveryNormalScreen(): void
    {
        $this->service->archive($this->place(), 42, $this->today());

        $this->assertSame([], $this->places->findAllVisible());
        $this->assertSame([], $this->places->search('Mozet'));
        $this->assertCount(1, $this->places->findAllVisible(true));
    }

    public function testArchivingKeepsTheStaysAndTheirHistory(): void
    {
        $stayId = $this->stay('2024-07-19', Camp::STATUS_CONFIRMED);

        $this->service->archive($this->place(), 42, $this->today());

        // Hidden, never deleted: the history is the module.
        $this->assertNotNull($this->camps->findById($stayId));
        $this->assertSame(1, $this->camps->countByPlace($this->placeId));
    }

    public function testRestoringPutsThePlaceBack(): void
    {
        $this->service->archive($this->place(), 42, $this->today());
        $this->service->restore($this->place(), 42);

        $this->assertFalse($this->places->findById($this->placeId)?->isArchived);
        $this->assertCount(1, $this->places->findAllVisible());
    }

    private function stay(string $endDate, string $status): int
    {
        return $this->camps->create(
            $this->placeId, Camp::STAY_GRAND_CAMP, $endDate, $endDate, null, $status,
            null, null, null, null, []
        );
    }

    private function place(): Place
    {
        $place = $this->places->findById($this->placeId);
        $this->assertNotNull($place);

        return $place;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-24');
    }
}
