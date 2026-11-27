<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\PlaceRepository;
use Core\Geo\GeoPointException;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\PlaceService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
class PlaceServiceTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private AuditService $audit;
    private PlaceService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->places = new PlaceRepository($this->pdo);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->service = new PlaceService($this->places, $this->audit);
    }

    public function testAPlaceNeedsAName(): void
    {
        $this->expectException(CampsException::class);
        $this->service->create(['name' => '   '], 1);
    }

    public function testAPlaceNeedsNothingElse(): void
    {
        // Many camp sites are a field behind a farm, with no address a
        // postman would recognise. Demanding one would make the module
        // unusable for exactly the places it exists to remember.
        $id = $this->service->create(['name' => 'Le pré de Jules'], 1);

        $place = $this->places->findById($id);
        $this->assertNotNull($place);
        $this->assertSame('Le pré de Jules', $place->name);
        $this->assertNull($place->address);
        $this->assertNull($place->city);
    }

    public function testCreationOpensTheHistory(): void
    {
        $id = $this->service->create(['name' => 'Domaine de Mozet'], 42);

        $page = $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10);
        $this->assertSame(1, $page->total);
        $this->assertSame('Lieu créé', $page->entries[0]->summary);
        $this->assertSame('Domaine de Mozet', $page->entries[0]->toValue);
    }

    public function testAWebsiteWithoutASchemeGetsOne(): void
    {
        $id = $this->service->create([
            'name' => 'Domaine de Mozet', 'website_url' => 'domainedemozet.be',
        ], 1);

        $this->assertSame('https://domainedemozet.be', $this->places->findById($id)?->websiteUrl);
    }

    public function testAWebsiteThatIsNotAUrlIsRefused(): void
    {
        // A place sheet renders this value as an href. "javascript:..." is
        // the whole reason this check exists.
        $this->expectException(CampsException::class);
        $this->service->create(['name' => 'X', 'website_url' => 'javascript:alert(1)'], 1);
    }

    /**
     * @dataProvider dangerousWebsites
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousWebsites')]
    public function testAWebsiteWithANonHttpSchemeIsRefusedNotRewritten(string $typed): void
    {
        // The "add https:// if it is missing" convenience must never
        // apply to a URL that already declares a scheme: prefixing
        // "file:///etc/passwd" produces "https://file:///etc/passwd",
        // which parses as https and passes every later check.
        $this->expectException(CampsException::class);
        $this->service->create(['name' => 'X', 'website_url' => $typed], 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dangerousWebsites(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    public function testTypingCoordinatesMarksThePlaceManualForEver(): void
    {
        $id = $this->service->create(['name' => 'Domaine de Mozet'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->service->update($place, [
            'name' => 'Domaine de Mozet', 'latitude' => '50.443210', 'longitude' => '5.001234',
        ], 42);

        $updated = $this->places->findById($id);
        $this->assertTrue($updated?->coordinatesAreManual);
        $this->assertEqualsWithDelta(50.443210, (float) $updated->latitude, 0.000001);
    }

    public function testACommaDecimalIsAcceptedBecauseThatIsHowItIsTyped(): void
    {
        $id = $this->service->create(['name' => 'X'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->service->update($place, ['name' => 'X', 'latitude' => '50,443210', 'longitude' => '5,001234'], 42);

        $this->assertEqualsWithDelta(50.443210, (float) $this->places->findById($id)?->latitude, 0.000001);
    }

    public function testHalfAPointIsRefused(): void
    {
        $id = $this->service->create(['name' => 'X'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->expectException(GeoPointException::class);
        $this->service->update($place, ['name' => 'X', 'latitude' => '50.44', 'longitude' => ''], 42);
    }

    /**
     * @dataProvider impossibleCoordinates
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('impossibleCoordinates')]
    public function testCoordinatesOffTheGlobeAreRefused(string $latitude, string $longitude): void
    {
        $id = $this->service->create(['name' => 'X'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->expectException(GeoPointException::class);
        $this->service->update($place, ['name' => 'X', 'latitude' => $latitude, 'longitude' => $longitude], 42);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function impossibleCoordinates(): array
    {
        return [
            'latitude past the pole' => ['91', '5'],
            'longitude past the date line' => ['50', '181'],
            'not a number at all' => ['nord', '5'],
        ];
    }

    public function testAFormWithoutCoordinateFieldsLeavesThePinAlone(): void
    {
        $id = $this->service->create(['name' => 'Domaine de Mozet'], 1);
        $this->places->setManualCoordinates($id, 50.443210, 5.001234);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        // The camp-creation form has no coordinate inputs. If its absent
        // fields read as "clear the point", every new camp at a known
        // place would wipe a pin somebody had placed by hand.
        $this->service->update($place, ['name' => 'Domaine de Mozet', 'city' => 'Mozet'], 42);

        $this->assertEqualsWithDelta(50.443210, (float) $this->places->findById($id)?->latitude, 0.000001);
    }

    /**
     * The point picker (#1353): a pin found from the address and left alone
     * is stored as the background task would store it — automatic, so a
     * chief who only looked at the map does not freeze the place.
     */
    public function testAPointFoundFromTheAddressStaysAutomatic(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');

        $this->service->update($place, $this->form($place, [
            'latitude' => '50.442100', 'longitude' => '4.987600',
            'point_automatic' => '1', 'point_address' => 'Rue du Tronquoy 4, 5340 Mozet, Belgique',
        ]), 42);

        $updated = $this->places->findById($place->id);
        $this->assertFalse($updated?->coordinatesAreManual);
        $this->assertEqualsWithDelta(50.4421, (float) $updated->latitude, 0.000001);
        $this->assertNotNull($updated->geocodedAt, 'stamped as found, so the task does not ask again');
    }

    /** Sent before the lookup of the new address answered: not this address's point. */
    public function testAnAutomaticPointFoundForAnotherAddressIsNotSaved(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');

        $this->service->update($place, $this->form($place, [
            'address' => 'Chemin des Prés 2',
            'latitude' => '50.442100', 'longitude' => '4.987600',
            'point_automatic' => '1', 'point_address' => 'Rue du Tronquoy 4, 5340 Mozet, Belgique',
        ]), 42);

        $updated = $this->places->findById($place->id);
        $this->assertNull($updated?->latitude, 'the task will find the new address\'s point');
        $this->assertFalse($updated->coordinatesAreManual);
    }

    public function testAPinMovedOnTheMapIsAHumansPoint(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');

        $this->service->update($place, $this->form($place, [
            'latitude' => '50.443000', 'longitude' => '4.990000', 'point_automatic' => '0', 'point_manual' => '1',
        ]), 42);

        $this->assertTrue($this->places->findById($place->id)?->coordinatesAreManual);
    }

    /** « Retirer » on an automatic point: a human's « no point », locked so the task does not put it back. */
    public function testRemovingThePointIsLockedLikePlacingOne(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');
        $this->places->recordGeocoding($place->id, 50.4421, 4.9876, new \DateTimeImmutable());
        $place = $this->places->findById($place->id);
        $this->assertNotNull($place);

        $this->service->update($place, $this->form($place, [
            'latitude' => '', 'longitude' => '', 'point_automatic' => '0', 'point_manual' => '1',
        ]), 42);

        $updated = $this->places->findById($place->id);
        $this->assertNull($updated?->latitude);
        $this->assertTrue($updated->coordinatesAreManual);
    }

    /** The saved automatic point posted back untouched writes nothing, and freezes nothing. */
    public function testAnUntouchedAutomaticPointChangesNothing(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');
        $this->places->recordGeocoding($place->id, 50.4421, 4.9876, new \DateTimeImmutable());
        $place = $this->places->findById($place->id);
        $this->assertNotNull($place);
        $before = $this->audit->page(PlaceService::ENTITY_TYPE, $place->id, 1, 10)->total;

        $this->service->update($place, $this->form($place, [
            'latitude' => '50.442100', 'longitude' => '4.987600',
            'point_automatic' => '1', 'point_address' => 'Rue du Tronquoy 4, 5340 Mozet, Belgique',
        ]), 42);

        $this->assertFalse($this->places->findById($place->id)?->coordinatesAreManual);
        $this->assertSame($before, $this->audit->page(PlaceService::ENTITY_TYPE, $place->id, 1, 10)->total);
    }

    /** A hand-placed point stays where it is when the address changes and the map proposes another. */
    public function testAHumansPointSurvivesANewAddressAndItsProposal(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');
        $this->places->setManualCoordinates($place->id, 50.443210, 5.001234);
        $place = $this->places->findById($place->id);
        $this->assertNotNull($place);

        $this->service->update($place, $this->form($place, [
            'address' => 'Chemin des Prés 2',
            'latitude' => '50.400000', 'longitude' => '4.900000',
            'point_automatic' => '1', 'point_address' => 'Chemin des Prés 2, 5340 Mozet, Belgique',
        ]), 42);

        $updated = $this->places->findById($place->id);
        $this->assertTrue($updated?->coordinatesAreManual);
        $this->assertEqualsWithDelta(50.443210, (float) $updated->latitude, 0.000001);
    }

    /** The task's own rule: « Belgique » alone is not an address to find, its « point » is the middle of the country. */
    public function testAnAutomaticPointForAnAddressWithoutPostalCodeOrCityIsNotSaved(): void
    {
        $id = $this->service->create(['name' => 'Le pré de Jules', 'country' => 'Belgique'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->service->update($place, $this->form($place, [
            'latitude' => '50.640000', 'longitude' => '4.660000',
            'point_automatic' => '1', 'point_address' => 'Belgique',
        ]), 42);

        $this->assertNull($this->places->findById($id)?->latitude);
    }

    /** Refused coordinates leave the place as it was — not half saved with its new address. */
    public function testRefusedCoordinatesSaveNothing(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');

        try {
            $this->service->update($place, $this->form($place, [
                'address' => 'Chemin des Prés 2', 'latitude' => '999', 'longitude' => '4.9',
            ]), 42);
            $this->fail('the coordinates should have been refused');
        } catch (GeoPointException) {
        }

        $this->assertSame('Rue du Tronquoy 4', $this->places->findById($place->id)?->address);
    }

    /** A pin placed by hand since the form was opened stays, and the history does not claim otherwise. */
    public function testAnAutomaticPointArrivingAfterAHumansOneWritesNothing(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');
        $this->places->setManualCoordinates($place->id, 50.443210, 5.001234);
        $before = $this->audit->page(PlaceService::ENTITY_TYPE, $place->id, 1, 10)->total;

        // $place is the row as the form saw it: no point, not manual.
        $this->service->update($place, $this->form($place, [
            'latitude' => '50.442100', 'longitude' => '4.987600',
            'point_automatic' => '1', 'point_address' => 'Rue du Tronquoy 4, 5340 Mozet, Belgique',
        ]), 42);

        $stored = $this->places->findById($place->id);
        $this->assertTrue($stored?->coordinatesAreManual);
        $this->assertEqualsWithDelta(50.443210, (float) $stored->latitude, 0.000001);
        $this->assertSame($before, $this->audit->page(PlaceService::ENTITY_TYPE, $place->id, 1, 10)->total);
    }

    /** The pin dragged away and back onto the task's point: the chief's now, locked. */
    public function testAPinMovedAndPutBackIsStillAHumansPoint(): void
    {
        $place = $this->placeAt('Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique');
        $this->places->recordGeocoding($place->id, 50.4421, 4.9876, new \DateTimeImmutable());
        $place = $this->places->findById($place->id);
        $this->assertNotNull($place);

        $this->service->update($place, $this->form($place, [
            'latitude' => '50.442100', 'longitude' => '4.987600', 'point_automatic' => '0', 'point_manual' => '1',
        ]), 42);

        $this->assertTrue($this->places->findById($place->id)?->coordinatesAreManual);
    }

    /** The stay form's new-place block (#1353): a pin found for its address is saved with it, automatic. */
    public function testANewPlaceKeepsTheAutomaticPointOfItsForm(): void
    {
        $id = $this->service->create([
            'name' => 'Domaine de Mozet', 'address' => 'Rue du Tronquoy 4', 'postal_code' => '5340',
            'city' => 'Mozet', 'country' => 'Belgique',
            'latitude' => '50.442100', 'longitude' => '4.987600',
            'point_automatic' => '1', 'point_address' => 'Rue du Tronquoy 4, 5340 Mozet, Belgique',
        ], 42);

        $place = $this->places->findById($id);
        $this->assertFalse($place?->coordinatesAreManual);
        $this->assertEqualsWithDelta(50.4421, (float) $place->latitude, 0.000001);
        $this->assertNotNull($place->geocodedAt);
    }

    public function testANewPlaceKeepsAHumansPointLocked(): void
    {
        $id = $this->service->create([
            'name' => 'Le pré de Jules',
            'latitude' => '50,443210', 'longitude' => '5.001234', 'point_automatic' => '0', 'point_manual' => '1',
        ], 42);

        $place = $this->places->findById($id);
        $this->assertTrue($place?->coordinatesAreManual);
        $this->assertEqualsWithDelta(5.001234, (float) $place->longitude, 0.000001);
    }

    /** Typed without the script: a human's point too. */
    public function testANewPlaceWithCoordinatesTypedWithoutTheScriptIsLocked(): void
    {
        $id = $this->service->create(['name' => 'Le pré de Jules', 'latitude' => '50.4', 'longitude' => '5.0'], 42);

        $this->assertTrue($this->places->findById($id)?->coordinatesAreManual);
    }

    public function testANewPlaceWithRefusedCoordinatesIsNotCreated(): void
    {
        try {
            $this->service->create(['name' => 'Le pré de Jules', 'latitude' => '91', 'longitude' => '5'], 42);
            $this->fail('the coordinates should have been refused');
        } catch (GeoPointException) {
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM camp_places')?->fetchColumn();
        $this->assertSame(0, (int) $count);
    }

    /** Empty fields and nobody touched the pin: the task finds the point later. */
    public function testANewPlaceWithEmptyCoordinatesWaitsForTheTask(): void
    {
        $id = $this->service->create([
            'name' => 'Domaine de Mozet', 'city' => 'Mozet',
            'latitude' => '', 'longitude' => '', 'point_automatic' => '0', 'point_manual' => '0',
        ], 42);

        $place = $this->places->findById($id);
        $this->assertNull($place?->latitude);
        $this->assertFalse($place->coordinatesAreManual);
        $this->assertNull($place->geocodedAt);
    }

    /** « Retirer » on a new place: nothing saved to remove, and the task must still find the point. */
    public function testANewPlaceWhosePinWasRemovedIsNotLocked(): void
    {
        $id = $this->service->create([
            'name' => 'Domaine de Mozet', 'city' => 'Mozet',
            'latitude' => '', 'longitude' => '', 'point_automatic' => '0', 'point_manual' => '1',
        ], 42);

        $place = $this->places->findById($id);
        $this->assertNull($place?->latitude);
        $this->assertFalse($place->coordinatesAreManual);
        $this->assertSame(1, $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->total, 'only « Lieu créé »');
    }

    private function placeAt(
        string $address,
        string $postalCode,
        string $city,
        string $country
    ): \Modules\Camps\Repository\Place
    {
        $id = $this->service->create([
            'name' => 'Domaine de Mozet', 'address' => $address, 'postal_code' => $postalCode,
            'city' => $city, 'country' => $country,
        ], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        return $place;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string|null>
     */
    private function form(\Modules\Camps\Repository\Place $place, array $overrides): array
    {
        return array_merge([
            'name' => $place->name, 'address' => $place->address, 'postal_code' => $place->postalCode,
            'city' => $place->city, 'country' => $place->country,
        ], $overrides);
    }

    public function testTheAddressIsRecordedAsOneReadableLine(): void
    {
        $id = $this->service->create(['name' => 'Domaine de Mozet'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->service->update($place, [
            'name' => 'Domaine de Mozet',
            'address' => 'Rue du Tronquoy 4',
            'postal_code' => '5340',
            'city' => 'Mozet',
            'country' => 'Belgique',
        ], 42);

        $entries = $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->entries;
        $address = null;
        foreach ($entries as $entry) {
            if ($entry->fieldKey === 'address') {
                $address = $entry;
            }
        }

        // One entry, not five: a reader wants "what was the address
        // before", not a column-by-column diff.
        $this->assertNotNull($address);
        $this->assertNull($address->fromValue);
        $this->assertSame('Rue du Tronquoy 4, 5340 Mozet, Belgique', $address->toValue);
    }

    public function testAnUnchangedPlaceWritesNoHistory(): void
    {
        $id = $this->service->create(['name' => 'Domaine de Mozet', 'city' => 'Mozet'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);
        $before = $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->total;

        $this->service->update($place, ['name' => 'Domaine de Mozet', 'city' => 'Mozet'], 1);

        $this->assertSame($before, $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->total);
    }

    public function testRenamingAPlaceIsRecordedWithItsOldName(): void
    {
        $id = $this->service->create(['name' => 'Ferme du Moulin'], 1);
        $place = $this->places->findById($id);
        $this->assertNotNull($place);

        $this->service->update($place, ['name' => 'Ferme du Vieux Moulin'], 42);

        $entries = $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->entries;
        $this->assertSame('name', $entries[0]->fieldKey);
        $this->assertSame('Ferme du Moulin', $entries[0]->fromValue);
        $this->assertSame('Ferme du Vieux Moulin', $entries[0]->toValue);
    }
}
