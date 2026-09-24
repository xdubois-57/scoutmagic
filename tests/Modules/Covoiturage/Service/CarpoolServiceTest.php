<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Geo\GeoPointException;
use Core\Member\SectionService;
use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Service\CarpoolException;
use Modules\Covoiturage\Service\CarpoolService;
use Modules\Covoiturage\Service\DuplicateCarpoolException;
use Modules\Covoiturage\Service\LocationMismatchException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;
use Tests\Modules\Covoiturage\FakeCalendar;

/**
 * Organising a carpool: who may, the two guards at creation, the place and
 * its point, and why a carpool with cars cannot be deleted.
 */
final class CarpoolServiceTest extends TestCase
{
    private \PDO $pdo;
    private CarpoolRepository $carpools;
    private CarpoolService $service;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', "
            . (int) $this->pdo->lastInsertId() . ", 'Louveteaux')"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->carpools = new CarpoolRepository($this->pdo);
        $this->service = new CarpoolService(
            $this->carpools,
            new OfferRepository($this->pdo, H::encryption()),
            new SectionService(Connection::withPdo($this->pdo), H::encryption(), new MemberBadgeRepository($this->pdo)),
            new FakeCalendar([
                new EventSummary(501, "Fête d'unité — Baladins", 'Baladins', H::day(20), H::day(20), null, 'Plaine de Basse-Wavre', 10, 'Baladins'),
                new EventSummary(502, "Fête d'unité — Louveteaux", 'Louveteaux', H::day(20), H::day(20), null, 'Plaine de Basse-Wavre', 20, 'Louveteaux'),
                new EventSummary(503, 'Week-end — Éclaireurs', 'Éclaireurs', H::day(30), H::day(31), null, 'Gîte de Han', 30, 'Éclaireurs'),
                new EventSummary(504, 'Réunion', 'Animateurs', H::day(5), H::day(5), null, null, null, null),
            ])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'event_ids' => ['501', '502'],
            'address' => '',
            'outbound_date' => H::day(20),
            'return_date' => '',
            'section_id' => '',
        ], $overrides);
    }

    public function testOnlyAChiefCreatesACarpool(): void
    {
        $this->expectException(CarpoolException::class);
        $this->service->create($this->input(), H::viewer(1, Role::INTENDANT));
    }

    public function testOneCarpoolServesAnEventReplicatedOnSeveralCalendars(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $carpool = $this->carpools->findById($id);

        $this->assertCount(2, $carpool?->events ?? []);
        $this->assertSame([10, 20], $carpool->sectionIds());
        // The address is taken from the events when left empty.
        $this->assertSame('Plaine de Basse-Wavre', $carpool->address);
        $this->assertSame("Fête d'unité", $carpool->title());
    }

    public function testAnEventThatAlreadyHasACarpoolOffersToJoinIt(): void
    {
        $existing = $this->service->create($this->input(['event_ids' => ['501']]), H::viewer(1, Role::CHIEF));

        try {
            $this->service->create($this->input(['event_ids' => ['501', '502']]), H::viewer(2, Role::CHIEF));
            $this->fail('A second carpool was created for the same event.');
        } catch (DuplicateCarpoolException $e) {
            $this->assertSame($existing, $e->existingCarpoolId);
            $this->assertStringContainsString('Rejoignez-le', $e->getMessage());
        }

        // Editing the existing carpool to add the other event is fine.
        $carpool = $this->carpools->findById($existing);
        $this->assertNotNull($carpool);
        $this->service->update($carpool, $this->input(['event_ids' => ['501', '502']]), H::viewer(1, Role::CHIEF));
        $this->assertCount(2, $this->carpools->findById($existing)?->events ?? []);
    }

    public function testTheSearchWarnsOnAnEventThatAlreadyHasACarpool(): void
    {
        $this->service->create($this->input(['event_ids' => ['502']]), H::viewer(1, Role::CHIEF));

        $rows = [];
        foreach ($this->service->searchEvents('fête', H::viewer(1, Role::CHIEF)) as $row) {
            $rows[$row->id] = $row;
        }

        $this->assertNull($rows[501]->warning);
        $this->assertStringContainsString('existe déjà', (string) $rows[502]->warning);
        $this->assertSame('Louveteaux', $rows[502]->badge);
        $this->assertStringContainsString('calendrier Louveteaux', (string) $rows[502]->subtitle);
    }

    public function testEventsGivingDifferentPlacesNeedAConfirmation(): void
    {
        try {
            $this->service->create($this->input(['event_ids' => ['501', '503']]), H::viewer(1, Role::CHIEF));
            $this->fail('Two destinations went through unconfirmed.');
        } catch (LocationMismatchException $e) {
            $this->assertSame(['Plaine de Basse-Wavre', 'Gîte de Han'], $e->locations);
        }

        $id = $this->service->create(
            $this->input(['event_ids' => ['501', '503'], 'confirm_locations' => '1', 'address' => 'Gîte de Han']),
            H::viewer(1, Role::CHIEF)
        );
        $this->assertSame('Gîte de Han', $this->carpools->findById($id)?->address);
    }

    public function testWithoutAnEventTheSectionIsRequired(): void
    {
        try {
            $this->service->create($this->input(['event_ids' => [], 'address' => 'Bastogne']), H::viewer(1, Role::CHIEF));
            $this->fail('A carpool with no event and no section.');
        } catch (CarpoolException $e) {
            $this->assertStringContainsString('section', $e->getMessage());
        }

        $id = $this->service->create(
            $this->input(['event_ids' => [], 'address' => 'Bastogne', 'section_id' => (string) $this->sectionId]),
            H::viewer(1, Role::CHIEF)
        );
        $this->assertSame([$this->sectionId], $this->carpools->findById($id)?->sectionIds());
    }

    public function testAnEventTheChiefCannotSeeIsRefused(): void
    {
        $this->expectException(CarpoolException::class);
        $this->service->create($this->input(['event_ids' => ['999']]), H::viewer(1, Role::CHIEF));
    }

    public function testTheDatesMakeSense(): void
    {
        foreach ([
            ['outbound_date' => H::day(-1)],
            ['outbound_date' => H::day(10), 'return_date' => H::day(9)],
            ['outbound_date' => 'demain'],
        ] as $dates) {
            try {
                $this->service->create($this->input($dates), H::viewer(1, Role::CHIEF));
                $this->fail('Accepted dates: ' . json_encode($dates));
            } catch (CarpoolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAPointPlacedBeforeSavingIsAHumansPoint(): void
    {
        $id = $this->service->create(
            $this->input(['latitude' => '50,7201', 'longitude' => '4.6412']),
            H::viewer(1, Role::CHIEF)
        );
        $carpool = $this->carpools->findById($id);

        $this->assertSame('50.720100, 4.641200', $carpool?->point?->line());
        $this->assertTrue($carpool->pointIsManual);
    }

    public function testWithoutAPointTheAddressIsLeftToTheGeocodingTask(): void
    {
        $id = $this->service->create($this->input(['latitude' => '', 'longitude' => '']), H::viewer(1, Role::CHIEF));

        $this->assertFalse($this->carpools->findById($id)?->pointIsManual);
        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id);
    }

    public function testHalfAPointIsRefused(): void
    {
        $this->expectException(GeoPointException::class);
        $this->service->create($this->input(['latitude' => '50.72', 'longitude' => '']), H::viewer(1, Role::CHIEF));
    }

    public function testAChangedAddressSendsAnAutomaticPointBackToTheQueue(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $this->carpools->points()->recordGeocoding($id, new \Core\Geo\GeoPoint(50.7, 4.6), new \DateTimeImmutable());
        $this->assertNull($this->carpools->findNextToGeocode());

        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);
        $this->service->update(
            $carpool,
            $this->input(['address' => 'Plaine de Basse-Wavre, entrée nord', 'latitude' => '50.700000', 'longitude' => '4.600000']),
            H::viewer(1, Role::CHIEF)
        );

        $this->assertSame($id, $this->carpools->findNextToGeocode()?->id);
        $this->assertFalse($this->carpools->findById($id)?->pointIsManual, 'An unchanged point is not a human decision.');
    }

    public function testTheReturnCannotBeRemovedOnceACarIsProposedForIt(): void
    {
        // Without a return date the return tab disappears, and with it the
        // cars proposed for it and every seat already granted.
        $id = $this->service->create($this->input(['return_date' => H::day(22)]), H::viewer(1, Role::CHIEF));
        H::offer($this->pdo, $id, 7, 4, 'return');
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        try {
            $this->service->update($carpool, $this->input(['return_date' => '']), H::viewer(1, Role::CHIEF));
            $this->fail('The return was removed from under a car proposed for it.');
        } catch (CarpoolException $e) {
            $this->assertSame(CarpoolService::RETURN_REMOVAL_REFUSED, $e->getMessage());
        }
        $this->assertSame(H::day(22), $this->carpools->findById($id)?->returnDate);
    }

    public function testTheReturnCanBeRemovedWhileOnlyOutboundCarsExist(): void
    {
        $id = $this->service->create($this->input(['return_date' => H::day(22)]), H::viewer(1, Role::CHIEF));
        H::offer($this->pdo, $id, 7);
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->service->update($carpool, $this->input(['return_date' => '']), H::viewer(1, Role::CHIEF));

        $this->assertNull($this->carpools->findById($id)?->returnDate);
    }

    public function testACarpoolWithACarCannotBeDeleted(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        H::offer($this->pdo, $id, 7);
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        try {
            $this->service->delete($carpool, H::viewer(1, Role::CHIEF));
            $this->fail('A carpool families organised around was deleted.');
        } catch (CarpoolException $e) {
            $this->assertSame(CarpoolService::DELETE_REFUSED, $e->getMessage());
        }
        $this->assertNotNull($this->carpools->findById($id));
    }

    public function testAnEmptyCarpoolIsDeletedByWhoeverMayOrganizeIt(): void
    {
        $id = $this->service->create($this->input(), H::viewer(1, Role::CHIEF));
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        try {
            $this->service->delete($carpool, H::viewer(9, Role::CHIEF, [30]));
            $this->fail('The staff of an unlinked section deleted it.');
        } catch (CarpoolException) {
        }

        $this->service->delete($carpool, H::viewer(9, Role::CHIEF, [20]));
        $this->assertNull($this->carpools->findById($id));
    }

    public function testWithoutTheCalendarEveryCarpoolCarriesASection(): void
    {
        $service = new CarpoolService(
            $this->carpools,
            new OfferRepository($this->pdo, H::encryption()),
            new SectionService(Connection::withPdo($this->pdo), H::encryption(), new MemberBadgeRepository($this->pdo)),
            null
        );

        $this->assertFalse($service->hasCalendar());
        $this->assertSame([], $service->searchEvents('fête', H::viewer(1, Role::CHIEF)));
        $this->expectException(CarpoolException::class);
        $service->create($this->input(), H::viewer(1, Role::CHIEF));
    }
}
