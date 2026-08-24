<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Repository;

use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class PlaceRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private CampRepository $camps;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testPlacesAreOrderedByTheirMostRecentStay(): void
    {
        $old = $this->places->create('Ancien', null, null, null, null, null);
        $recent = $this->places->create('Récent', null, null, null, null, null);
        $this->stay($old, '2019-07-19');
        $this->stay($recent, '2025-07-19');

        $names = array_map(static fn(Place $p): string => $p->name, $this->places->findAllVisible());

        // "Recent" means the unit was there recently, not that the row was
        // created recently — which is why this needs the join.
        $this->assertSame(['Récent', 'Ancien'], $names);
    }

    public function testAPlaceWithNoStayYetSortsLastRatherThanDisappearing(): void
    {
        $withStay = $this->places->create('Avec séjour', null, null, null, null, null);
        $this->places->create('Sans séjour', null, null, null, null, null);
        $this->stay($withStay, '2025-07-19');

        $names = array_map(static fn(Place $p): string => $p->name, $this->places->findAllVisible());

        // A place just created has no stay; dropping it here would make it
        // invisible on the only screen that lists places.
        $this->assertSame(['Avec séjour', 'Sans séjour'], $names);
    }

    public function testAYearOnlyStayCountsForRecency(): void
    {
        $dated = $this->places->create('Daté 2020', null, null, null, null, null);
        $yearOnly = $this->places->create('Année 2024', null, null, null, null, null);
        $this->stay($dated, '2020-07-19');
        $this->camps->create($yearOnly, Camp::STAY_GRAND_CAMP, null, null, 2024, Camp::STATUS_CONFIRMED, null, null, null, null, []);

        $names = array_map(static fn(Place $p): string => $p->name, $this->places->findAllVisible());

        $this->assertSame(['Année 2024', 'Daté 2020'], $names);
    }

    public function testArchivedPlacesAreASeparateList(): void
    {
        $live = $this->places->create('Actif', null, null, null, null, null);
        $this->places->create('Archivé', null, null, null, null, null);
        $this->pdo->exec("UPDATE camp_places SET is_archived = 1 WHERE name = 'Archivé'");

        $this->assertSame(['Actif'], array_map(static fn(Place $p): string => $p->name, $this->places->findAllVisible()));
        $this->assertSame(['Archivé'], array_map(static fn(Place $p): string => $p->name, $this->places->findAllVisible(true)));
        $this->assertSame($live, $this->places->findAllVisible()[0]->id);
    }

    /**
     * @dataProvider searchTerms
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('searchTerms')]
    public function testSearchCoversWhatThePlaceholderPromises(string $term, bool $found): void
    {
        $this->places->create('Domaine de Mozet', 'Rue du Tronquoy 4', '5340', 'Mozet', 'Belgique', null);

        $this->assertSame($found, $this->places->search($term) !== [], "searching « {$term} »");
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function searchTerms(): array
    {
        return [
            'by name' => ['Mozet', true],
            'by partial name' => ['omaine', true],
            'by street' => ['Tronquoy', true],
            'by postal code' => ['5340', true],
            'by city' => ['Mozet', true],
            'something else entirely' => ['Vielsalm', false],
        ];
    }

    public function testAnEmptySearchReturnsEverything(): void
    {
        $this->places->create('A', null, null, null, null, null);
        $this->places->create('B', null, null, null, null, null);

        $this->assertCount(2, $this->places->search('   '));
    }

    public function testSearchDoesNotLetWildcardsThrough(): void
    {
        $this->places->create('Domaine de Mozet', null, null, null, null, null);

        // A LIKE wildcard typed into the box must be a literal character,
        // or "%" alone would silently match every place.
        $this->assertSame([], $this->places->search('%'));
        $this->assertSame([], $this->places->search('_ozet'));
    }

    public function testSearchDoesNotReachArchivedPlaces(): void
    {
        $this->places->create('Domaine de Mozet', null, null, null, null, null);
        $this->pdo->exec('UPDATE camp_places SET is_archived = 1');

        $this->assertSame([], $this->places->search('Mozet'));
        $this->assertCount(1, $this->places->search('Mozet', true));
    }

    private function stay(int $placeId, string $endDate): void
    {
        $this->camps->create(
            $placeId, Camp::STAY_GRAND_CAMP, $endDate, $endDate, null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
    }
}
