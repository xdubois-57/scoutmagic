<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\StaySearchService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * « Rattacher à », answered by typing.
 *
 * The control this feeds replaced a `<select>` holding the cross product
 * of every visible place and every stay it ever hosted. What matters here
 * is not that a filter filters — it is the ORDER, because a picker that
 * opens on the right line is the difference between a search box and a
 * search box people have to use.
 */
class StaySearchServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private int $fresnaye;
    private int $mozet;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->camps = new CampRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $this->pdo->exec("INSERT INTO camp_places (name, city) VALUES ('Camp de La Fresnaye', 'Dworp')");
        $this->pdo->exec("INSERT INTO camp_places (name, city) VALUES ('Domaine de Mozet', 'Namur')");

        // Two long past, one just past, one to come — every half of the
        // ordering rule has something in it.
        $this->fresnaye = $this->stay(1, '2026-09-18', '2026-09-20', Camp::STAY_SHORT_CAMP);
        $this->mozet = $this->stay(2, '2020-07-12', '2020-07-19', Camp::STAY_GRAND_CAMP);
    }

    private function stay(int $placeId, string $start, string $end, string $type): int
    {
        return $this->camps->create(
            $placeId, $type, $start, $end, null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
    }

    private function service(): StaySearchService
    {
        return new StaySearchService($this->camps);
    }

    /** @param list<array{id:int,label:string,detail:string,reason:string}> $rows */
    private static function ids(array $rows): array
    {
        return array_map(static fn(array $row): int => $row['id'], $rows);
    }

    // ── What a chief types is what they read ────────────────────────────

    public function testAPlaceNameFindsItsStays(): void
    {
        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('fresnaye')));
    }

    public function testTheSearchIgnoresAccentsAndCase(): void
    {
        // Through Core\Service\TextNormalizerService, like every other
        // name comparison on the site: « Séjour » and « sejour » fold the
        // same way here as they do everywhere else.
        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('LA FRESNAYE')));
    }

    public function testAMonthFindsAStayThoughNoColumnSpellsIt(): void
    {
        // The whole reason matching happens in PHP: the words on the
        // screen are written by Service\CampLabels out of columns that
        // spell none of them, so no LIKE over start_date finds
        // « septembre ».
        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('septembre 2026')));
    }

    public function testEveryWordHasToAppearSomewhere(): void
    {
        // Words rather than one substring: « fresnaye 2026 » finds what
        // neither « fresnaye2026 » nor an ordered match would — and
        // « fresnaye 2020 » finds nothing, which is the same rule saying
        // no.
        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('fresnaye 2026')));
        $this->assertSame([], $this->service()->search('fresnaye 2020'));
    }

    public function testADateCanBeTypedTheWayItIsWritten(): void
    {
        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('18/09/2026')));
        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('2026-09-18')));
    }

    public function testTheKindOfStayIsSearchableToo(): void
    {
        $this->assertSame([$this->mozet], self::ids($this->service()->search('grand camp')));
    }

    // ── The order, which is the point ───────────────────────────────────

    public function testAPreferredStayLeadsTheListAndSaysWhy(): void
    {
        $rows = $this->service()->search('', [$this->mozet]);

        $this->assertSame($this->mozet, $rows[0]['id']);
        $this->assertSame(StaySearchService::REASON_PREFERRED, $rows[0]['reason']);
        // And only the stay that was preferred carries a reason: a note on
        // every line is a note nobody reads.
        $this->assertSame('', $rows[1]['reason']);
    }

    /**
     * Positions rather than a whole list, and dates far enough from today
     * that no calendar makes this test lie: the fixtures above sit near
     * the present on purpose — they are what the SEARCH tests type — and a
     * suite that asserted their side of today would start failing on a
     * date nobody chose.
     *
     * @param list<array{id:int,label:string,detail:string,reason:string}> $rows
     */
    private static function positionOf(array $rows, int $campId): int
    {
        $position = array_search($campId, self::ids($rows), true);
        self::assertIsInt($position, 'stay #' . $campId . ' is not in the answer at all');

        return $position;
    }

    public function testWithNothingTypedWhatIsStillToComeComesFirst(): void
    {
        // A chief attaching a contract is almost always looking at a camp
        // that has not happened yet.
        $past = $this->stay(2, '2000-07-01', '2000-07-10', Camp::STAY_GRAND_CAMP);
        $future = $this->stay(1, '2099-07-01', '2099-07-10', Camp::STAY_GRAND_CAMP);

        $rows = $this->service()->search('', [], 50);

        $this->assertLessThan(self::positionOf($rows, $past), self::positionOf($rows, $future));
    }

    public function testAmongPastStaysTheMostRecentComesFirst(): void
    {
        // "Nearest to today" counts backwards on that side of it.
        $older = $this->stay(2, '2000-07-01', '2000-07-10', Camp::STAY_GRAND_CAMP);
        $newer = $this->stay(1, '2010-07-01', '2010-07-10', Camp::STAY_GRAND_CAMP);

        $rows = $this->service()->search('', [], 50);

        $this->assertLessThan(self::positionOf($rows, $older), self::positionOf($rows, $newer));
    }

    public function testAmongFutureStaysTheSoonestComesFirst(): void
    {
        $later = $this->stay(1, '2099-08-01', '2099-08-10', Camp::STAY_GRAND_CAMP);
        $sooner = $this->stay(2, '2099-07-01', '2099-07-10', Camp::STAY_GRAND_CAMP);

        $rows = $this->service()->search('', [], 50);

        $this->assertLessThan(self::positionOf($rows, $later), self::positionOf($rows, $sooner));
    }

    public function testAPreferredStayThatDoesNotMatchTheSearchIsStillFilteredOut(): void
    {
        // Preference is about ORDER, never about admission: a chief who
        // typed « mozet » asked for Mozet, and answering with something
        // else because the message hinted at it would be the picker
        // arguing.
        $rows = self::ids($this->service()->search('mozet', [$this->fresnaye]));

        $this->assertSame([$this->mozet], $rows);
    }

    public function testAPreferredIdMatchingNoStayRanksNothingAndBreaksNothing(): void
    {
        $rows = $this->service()->search('', [99999]);

        $this->assertCount(2, $rows);
        $this->assertSame('', $rows[0]['reason']);
    }

    // ── Bounds ──────────────────────────────────────────────────────────

    public function testTheAnswerIsBounded(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->stay(1, '2024-07-0' . ($i % 9 + 1), '2024-07-0' . ($i % 9 + 1), Camp::STAY_OTHER);
        }

        $this->assertLessThanOrEqual(3, count($this->service()->search('', [], 3)));
        $this->assertCount(StaySearchService::LIMIT, $this->service()->search(''));
    }

    public function testAStayAtAnArchivedPlaceIsNotProposed(): void
    {
        // A place a chief archived is one they said they were done with;
        // proposing its stays is proposing the answer they retired.
        $this->pdo->exec('UPDATE camp_places SET is_archived = 1 WHERE id = 2');

        $this->assertSame([$this->fresnaye], self::ids($this->service()->search('')));
    }

    public function testTheLabelIsThePlaceAndThePeriodTheOtherScreensShow(): void
    {
        $label = $this->service()->search('fresnaye')[0]['label'];

        $this->assertStringContainsString('Camp de La Fresnaye', $label);
        $this->assertStringContainsString('septembre', $label);
    }

    public function testAYearOnlyStayIsProposedByItsYear(): void
    {
        $someday = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, null, null, 2031,
            Camp::STATUS_TO_CONFIRM, null, null, null, null, []
        );

        $this->assertSame([$someday], self::ids($this->service()->search('2031')));
    }

    public function testTheStaysAreReadOnceHoweverManyQuestionsAreAsked(): void
    {
        // The mail screen asks this service once per message — a hundred
        // messages must not be a hundred identical queries.
        $counting = new class ($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))
            extends CampRepository {
            public int $reads = 0;

            public function findAllWithPlaceName(): array
            {
                $this->reads++;

                return parent::findAllWithPlaceName();
            }
        };

        $service = new StaySearchService($counting);
        $service->search('');
        $service->search('fresnaye');
        $service->search('', [1]);

        $this->assertSame(1, $counting->reads);
    }
}
