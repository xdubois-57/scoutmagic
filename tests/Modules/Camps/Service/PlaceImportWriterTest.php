<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Security\EncryptionService;
use Modules\Camps\Api\ImportedPlace;
use Modules\Camps\Api\PlaceImportWriterInterface;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceImportWriter;
use Modules\Camps\Service\PlaceService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * The write contract the data migration's « Endroits de camp » import
 * goes through: a place created with the module's rules and nothing else —
 * no geocoding, no model call, no mail.
 */
#[Group('database')]
final class PlaceImportWriterTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private AuditService $audit;
    private PlaceService $placeService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->places = new PlaceRepository($this->pdo);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->placeService = new PlaceService($this->places, $this->audit);
    }

    public function testThePlaceIsCreatedWithEverythingTheSourceGave(): void
    {
        $id = $this->writer()->create(new ImportedPlace(
            '  Ferme du Bois-Haut ',
            'Rue du Tronquoy 4',
            '5360',
            'Hamois',
            'Belgique',
            'fermeduboishaut.be',
            '50.3412',
            '5.1501'
        ), 7);

        $place = $this->places->findById($id) ?? $this->fail('No place.');
        $this->assertSame('Ferme du Bois-Haut', $place->name);
        $this->assertSame('Rue du Tronquoy 4', $place->address);
        $this->assertSame('5360', $place->postalCode);
        $this->assertSame('Hamois', $place->city);
        $this->assertSame('Belgique', $place->country);
        $this->assertSame('https://fermeduboishaut.be', $place->websiteUrl);
        $this->assertFalse($place->isArchived);
        $this->assertEqualsWithDelta(50.3412, $place->latitude, 0.000001);
        $this->assertEqualsWithDelta(5.1501, $place->longitude, 0.000001);
    }

    public function testCoordinatesFromTheSourceAreManualAndNeverGeocoded(): void
    {
        $id = $this->writer()->create(new ImportedPlace('Prairie Lambert', null, null, 'Leignon', null, null, '50.27', '5.02'), 7);

        $place = $this->places->findById($id) ?? $this->fail('No place.');
        $this->assertTrue($place->coordinatesAreManual);
        $this->assertNull($place->geocodedAt, 'The import geocoded the place.');
        $this->assertNull($this->places->findNextToGeocode(), 'A manual point is queued for geocoding.');

        // And the geocoding task cannot move it either.
        $this->places->recordGeocoding($id, 49.0, 4.0, new \DateTimeImmutable());
        $this->assertEqualsWithDelta(50.27, $this->places->findById($id)?->latitude, 0.000001);
    }

    public function testAPlaceWithoutCoordinatesIsLeftToTheOrdinaryGeocoding(): void
    {
        $id = $this->writer()->create(new ImportedPlace('Domaine de Mozet', null, '5340', 'Mozet'), 7);

        $place = $this->places->findById($id) ?? $this->fail('No place.');
        $this->assertFalse($place->coordinatesAreManual);
        $this->assertNull($place->latitude);
        $this->assertNull($place->geocodedAt, 'The import geocoded the place.');
        $this->assertSame($id, $this->places->findNextToGeocode()?->id);
    }

    public function testAnArchivedPlaceIsCreatedArchived(): void
    {
        $id = $this->writer()->create(new ImportedPlace('Vieux pré', isArchived: true), 7);

        $this->assertTrue($this->places->findById($id)?->isArchived);
        $this->assertSame([], $this->places->findAllVisible());
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function unusableCoordinates(): array
    {
        return [
            'latitude only' => ['50.3', null],
            'longitude only' => [null, '5.1'],
            'latitude out of bounds' => ['91', '5.1'],
            'longitude out of bounds' => ['50.3', '-180.5'],
            'not a number' => ['cinquante', '5.1'],
        ];
    }

    #[DataProvider('unusableCoordinates')]
    public function testUnusableCoordinatesAreLeftOutAndThePlaceIsStillCreated(?string $latitude, ?string $longitude): void
    {
        $place = new ImportedPlace('Ferme du Moulin', null, null, 'Vielsalm', null, null, $latitude, $longitude);

        $this->assertSame(['coordinates'], $this->writer()->unrecognisedFields($place));
        $id = $this->writer()->create($place, 7);

        $created = $this->places->findById($id) ?? $this->fail('No place.');
        $this->assertNull($created->latitude);
        $this->assertNull($created->longitude);
        $this->assertFalse($created->coordinatesAreManual);
    }

    public function testValuesThatDoNotFitAreLeftOutAndNamed(): void
    {
        $place = new ImportedPlace(
            'Ferme du Moulin',
            str_repeat('a', 256),
            str_repeat('1', 21),
            'Vielsalm',
            str_repeat('b', 81),
            'javascript:alert(1)'
        );

        $this->assertSame(['address', 'postal_code', 'country', 'website_url'], $this->writer()->unrecognisedFields($place));

        $created = $this->places->findById($this->writer()->create($place, 7)) ?? $this->fail('No place.');
        $this->assertNull($created->address);
        $this->assertNull($created->postalCode);
        $this->assertSame('Vielsalm', $created->city);
        $this->assertNull($created->country);
        $this->assertNull($created->websiteUrl);
    }

    public function testAFullyUsablePlaceHasNothingLeftOut(): void
    {
        $this->assertSame([], $this->writer()->unrecognisedFields(
            new ImportedPlace('Ferme du Moulin', 'Rue 1', '6690', 'Vielsalm', 'Belgique', 'https://exemple.be', '50.28', '5.91')
        ));
    }

    public function testANameIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer()->create(new ImportedPlace('   '), 7);
    }

    public function testATooLongNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer()->create(new ImportedPlace(str_repeat('é', ImportedPlace::MAX_NAME_LENGTH + 1)), 7);
    }

    public function testTheHistorySaysWhereThePlaceCameFrom(): void
    {
        $id = $this->writer()->create(new ImportedPlace('Prairie Lambert', latitude: '50.27', longitude: '5.02', isArchived: true), 7);

        $summaries = array_map(
            static fn ($entry): ?string => $entry->summary,
            $this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->entries
        );
        $this->assertContains('Lieu créé', $summaries);
        $this->assertContains('Lieu repris de l\'ancien site par la migration de données', $summaries);
        $this->assertContains('Coordonnées reprises de l\'ancien site — le géocodage automatique ne touchera pas ce lieu', $summaries);
        $this->assertContains('Lieu archivé sur l\'ancien site, repris archivé', $summaries);
    }

    public function testEveryHistoryLineItWritesHasAFrenchLabel(): void
    {
        $id = $this->writer()->create(new ImportedPlace('Prairie Lambert', latitude: '50.27', longitude: '5.02', isArchived: true), 7);

        foreach ($this->audit->page(PlaceService::ENTITY_TYPE, $id, 1, 10)->entries as $entry) {
            $this->assertArrayHasKey($entry->fieldKey, CampLabels::FIELD_LABELS, $entry->fieldKey . ' has no French label');
        }
    }

    public function testAnImportedPlaceStaysEditableInCamps(): void
    {
        $id = $this->writer()->create(new ImportedPlace('Prairie Lambert', null, null, 'Leignon', null, null, '50.27', '5.02'), 7);
        $place = $this->places->findById($id) ?? $this->fail('No place.');

        $this->placeService->update($place, ['name' => 'Prairie Lambert (Leignon)', 'city' => 'Leignon'], 7);

        $edited = $this->places->findById($id) ?? $this->fail('No place.');
        $this->assertSame('Prairie Lambert (Leignon)', $edited->name);
        // An edit that does not carry coordinates keeps the imported pin.
        $this->assertEqualsWithDelta(50.27, $edited->latitude, 0.000001);
        $this->assertTrue($edited->coordinatesAreManual);
    }

    // ── Duplicates ──────────────────────────────────────────────────

    public function testASameNamedPlaceInTheSameLocalityIsACertainMatch(): void
    {
        $existing = $this->places->create('Ferme du Bois-Haut', null, '5360', 'Hamois', null, null);

        $match = $this->writer()->findExisting(new ImportedPlace('ferme du bois haut', null, null, 'Hamois'));

        $this->assertNotNull($match);
        $this->assertTrue($match->isCertain);
        $this->assertSame($existing, $match->id);
        $this->assertSame('Ferme du Bois-Haut', $match->name);
        $this->assertSame('Hamois', $match->locality);
    }

    public function testAnArchivedPlaceIsMatchedToo(): void
    {
        $existing = $this->places->create('Ferme du Bois-Haut', null, null, 'Hamois', null, null);
        $this->places->archive($existing, true);

        $match = $this->writer()->findExisting(new ImportedPlace('Ferme du Bois-Haut', null, null, 'Hamois'));

        $this->assertSame($existing, $match?->id);
        $this->assertTrue($match->isCertain);
    }

    public function testAResemblanceIsOnlyPossibleAndNamesThePlace(): void
    {
        $this->places->create('Prairie Lambert', null, '5590', 'Leignon', null, null);

        $match = $this->writer()->findExisting(new ImportedPlace('Prairie Lambert', null, null, 'Ciney'));

        $this->assertNotNull($match);
        $this->assertFalse($match->isCertain);
        $this->assertSame('Prairie Lambert', $match->name);
        $this->assertSame('Leignon', $match->locality);
    }

    public function testTheLocalityFallsBackOnThePostalCode(): void
    {
        $this->places->create('Domaine de Mozet', null, '5340', null, null, null);

        $this->assertSame('5340', $this->writer()->findExisting(new ImportedPlace('Domaine de Mozzet', null, '5340'))?->locality);
    }

    public function testACertainMatchWinsOverAPossibleOne(): void
    {
        // Listed first (by name), and only a typo away.
        $this->places->create('Domaine de Mozat', null, null, 'Mozet', null, null);
        $certain = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $match = $this->writer()->findExisting(new ImportedPlace('Domaine de Mozet', null, null, 'Mozet'));

        $this->assertSame($certain, $match?->id);
        $this->assertTrue($match->isCertain);
    }

    public function testAnUnknownPlaceHasNoMatch(): void
    {
        $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $this->assertNull($this->writer()->findExisting(new ImportedPlace('Ferme du Moulin', null, null, 'Vielsalm')));
    }

    public function testTheLanguageModelIsNeverAsked(): void
    {
        // Same town, unrelated names: the shape that sends the creation
        // form to the model. An import never does.
        $this->places->create('Ferme du Moulin', null, null, 'Vielsalm', null, null);
        $llm = $this->createMock(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->expects($this->never())->method('complete');

        $writer = $this->writer(new DuplicatePlaceDetector($this->places, $llm));

        $this->assertNull($writer->findExisting(new ImportedPlace('Chez Delvaux', null, null, 'Vielsalm')));
        $writer->create(new ImportedPlace('Chez Delvaux', null, null, 'Vielsalm'), 7);
    }

    public function testFindingAMatchChangesNothing(): void
    {
        $existing = $this->places->create('Ferme du Bois-Haut', null, null, 'Hamois', null, null);
        $before = $this->places->findById($existing);

        $this->writer()->findExisting(new ImportedPlace('Ferme du Bois-Haut', 'Autre adresse', '5360', 'Hamois', 'France', null, '1', '1', true));

        $this->assertEquals($before, $this->places->findById($existing));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_places')->fetchColumn());
    }

    private function writer(?DuplicatePlaceDetector $detector = null): PlaceImportWriterInterface
    {
        return new PlaceImportWriter(
            $this->places,
            $this->placeService,
            $detector ?? new DuplicatePlaceDetector($this->places),
            $this->audit
        );
    }
}
