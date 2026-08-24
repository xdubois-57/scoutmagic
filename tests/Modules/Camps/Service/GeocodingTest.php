<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\GeocodingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * The geocoding QUEUE, not the network call — nothing here reaches
 * Nominatim. What matters and what breaks silently is which places get
 * picked, which never do, and what happens after a lookup that found
 * nothing.
 */
class GeocodingTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->places = new PlaceRepository($this->pdo);
    }

    public function testAPlaceIsGeocodedOnceAndThenLeftAlone(): void
    {
        $id = $this->places->create('Domaine de Mozet', 'Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique', null);

        $this->assertSame($id, $this->places->findNextToGeocode()?->id);
        $this->places->recordGeocoding($id, 50.44, 5.00, new \DateTimeImmutable());
        $this->assertNull($this->places->findNextToGeocode());
    }

    public function testALookupThatFoundNothingStillCountsAsDone(): void
    {
        $id = $this->places->create('Le pré de Jules', null, null, null, null, null);

        $this->places->recordGeocoding($id, null, null, new \DateTimeImmutable());

        // Without stamping a failure, this place is retried on every
        // single run for ever — and blocks the queue behind it, since the
        // task takes one place at a time.
        $this->assertNull($this->places->findNextToGeocode());
        $this->assertFalse($this->places->findById($id)?->hasCoordinates());
    }

    public function testChangingTheAddressPutsThePlaceBackInTheQueue(): void
    {
        // `geocoded_at` means "we have tried this address". Left stamped,
        // a place corrected from "Rue du Tronquoy" to "Rue du Tronquoy 4"
        // keeps the pin the old, vaguer address produced — for ever — and
        // the map quietly shows the wrong field.
        $id = $this->places->create('Domaine de Mozet', 'Rue du Tronquoy', '5340', 'Mozet', 'Belgique', null);
        $this->places->recordGeocoding($id, 50.44, 5.00, new \DateTimeImmutable());
        $this->assertNull($this->places->findNextToGeocode());

        $place = $this->places->findById($id);
        $this->assertNotNull($place);
        $this->service()->update($place, [
            'name' => 'Domaine de Mozet',
            'address' => 'Rue du Tronquoy 4',
            'postal_code' => '5340',
            'city' => 'Mozet',
            'country' => 'Belgique',
        ], 42);

        $this->assertSame($id, $this->places->findNextToGeocode()?->id);
    }

    public function testEditingSomethingOtherThanTheAddressDoesNotRequeueIt(): void
    {
        // Re-geocoding on every save would spend the queue on nothing and
        // hand Nominatim a request per rename.
        $id = $this->places->create('Domaine de Mozet', 'Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique', null);
        $this->places->recordGeocoding($id, 50.44, 5.00, new \DateTimeImmutable());

        $place = $this->places->findById($id);
        $this->assertNotNull($place);
        $this->service()->update($place, [
            'name' => 'Domaine de Mozet asbl',
            'address' => 'Rue du Tronquoy 4',
            'postal_code' => '5340',
            'city' => 'Mozet',
            'country' => 'Belgique',
        ], 42);

        $this->assertNull($this->places->findNextToGeocode());
    }

    public function testAHandPlacedPinSurvivesAnAddressCorrection(): void
    {
        // Somebody who moved the pin onto the actual field knows something
        // Nominatim does not, and the whole feature is worthless if the
        // next run puts it back on the village square.
        $id = $this->places->create('Domaine de Mozet', 'Rue du Tronquoy', '5340', 'Mozet', 'Belgique', null);
        $this->places->setManualCoordinates($id, 50.44, 5.00);

        $place = $this->places->findById($id);
        $this->assertNotNull($place);
        $this->service()->update($place, [
            'name' => 'Domaine de Mozet',
            'address' => 'Rue du Tronquoy 4',
            'postal_code' => '5340',
            'city' => 'Mozet',
            'country' => 'Belgique',
        ], 42);

        $this->assertNull($this->places->findNextToGeocode());
        $this->assertTrue($this->places->findById($id)?->hasCoordinates());
    }

    private function service(): \Modules\Camps\Service\PlaceService
    {
        return new \Modules\Camps\Service\PlaceService(
            $this->places,
            new \Core\Audit\AuditService(new \Core\Audit\AuditRepository(
                $this->pdo,
                new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
            ))
        );
    }

    public function testManualCoordinatesAreNeverTouchedByGeocoding(): void
    {
        $id = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $this->places->setManualCoordinates($id, 50.443210, 5.001234);

        $this->assertNull($this->places->findNextToGeocode(), 'a manually placed pin must never be queued');

        // And even a direct call cannot overwrite it: somebody who moved
        // the pin onto the actual field knows something Nominatim does
        // not, and the feature is worthless if the next run undoes it.
        $this->places->recordGeocoding($id, 0.0, 0.0, new \DateTimeImmutable());
        $place = $this->places->findById($id);
        $this->assertEqualsWithDelta(50.443210, (float) $place?->latitude, 0.000001);
        $this->assertTrue($place->coordinatesAreManual);
    }

    public function testAnArchivedPlaceIsNotGeocoded(): void
    {
        $this->places->create('Prairie de Grandhan', null, null, 'Durbuy', null, null);
        $this->pdo->exec('UPDATE camp_places SET is_archived = 1');

        $this->assertNull($this->places->findNextToGeocode());
        $this->assertSame(0, $this->places->countPendingGeocoding());
    }

    public function testTheQueueDrainsInAPredictableOrder(): void
    {
        $first = $this->places->create('A', null, null, 'Namur', null, null);
        $this->places->create('B', null, null, 'Liège', null, null);

        $this->assertSame(2, $this->places->countPendingGeocoding());
        $this->assertSame($first, $this->places->findNextToGeocode()?->id);
    }

    public function testOnlyPlacesWithCoordinatesAppearOnTheMap(): void
    {
        $mapped = $this->places->create('Avec point', null, null, 'Namur', null, null);
        $this->places->create('Sans point', null, null, 'Liège', null, null);
        $this->places->setManualCoordinates($mapped, 50.46, 4.86);

        $mappable = $this->places->findMappable();
        $this->assertCount(1, $mappable);
        $this->assertSame('Avec point', $mappable[0]->name);
    }

    /**
     * @dataProvider thinAddresses
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('thinAddresses')]
    public function testAnAddressTooThinToSearchIsNeverSent(?string $postalCode, ?string $city): void
    {
        // Sending "Le pré de Jules" to a gazetteer returns whatever it
        // feels like, and a confidently wrong pin is worse than no pin.
        $this->assertNull(
            (new GeocodingService())->geocode('Chez le voisin', $postalCode, $city, 'Belgique')
        );
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function thinAddresses(): array
    {
        return [
            'nothing but a country' => [null, null],
            'blank strings' => ['   ', '  '],
        ];
    }
}
