<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\PlaceService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

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

        $this->expectException(CampsException::class);
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

        $this->expectException(CampsException::class);
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
