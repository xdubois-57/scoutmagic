<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Security\EncryptionService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampDelegatedAlbumDescriber;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * What an administrator reads in gallery's list of albums taking space on
 * a storage location, for a stay's album (issue #749).
 *
 * The module created those albums and registered their access rules while
 * providing nothing to NAME them, so the page fell through to gallery's
 * fallback and showed `camp_camp #10` — the identifier of a row, to
 * somebody deciding whether to move it.
 *
 * Two properties matter here and the tests are split along them. The label
 * has to be recognisable without knowing any id, and it has to be computed
 * from the stay as it stands NOW: a label copied at album-creation time
 * would be a second place for the truth, drifting the first time anybody
 * corrected a date.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampDelegatedAlbumDescriberTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private PlaceRepository $places;
    private CampDelegatedAlbumDescriber $describer;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->places = new PlaceRepository($this->pdo);
        $this->describer = new CampDelegatedAlbumDescriber($this->camps, $this->places);
    }

    private function place(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO camp_places (name) VALUES (?)');
        $stmt->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function camp(
        int $placeId,
        string $stayType = Camp::STAY_GRAND_CAMP,
        ?string $start = '2028-07-12',
        ?string $end = '2028-07-19',
        ?int $yearOnly = null
    ): int {
        return $this->camps->create(
            $placeId,
            $stayType,
            $start,
            $end,
            $yearOnly,
            Camp::STATUS_CONFIRMED,
            null,
            null,
            null,
            null,
            []
        );
    }

    public function testItClaimsItsOwnOwnerTypeAndNoOther(): void
    {
        $this->assertTrue($this->describer->supports('camp_camp'));
        $this->assertFalse($this->describer->supports('discussion_group'));
    }

    /**
     * The stay type, the place and the dates — enough to recognise the
     * stay without knowing its id, which is the whole of what the page
     * was missing.
     */
    public function testAStayIsNamedByItsTypeItsPlaceAndItsDates(): void
    {
        $campId = $this->camp($this->place('Ferme de la Hulotte'));

        $this->assertSame(
            'Grand camp — Ferme de la Hulotte — 12–19 juillet 2028',
            $this->describer->describe($campId)
        );
    }

    /**
     * The dynamic half, measured on the place: nothing is copied into
     * gallery's row, so the administrator's label follows the stay.
     */
    public function testRenamingThePlaceMovesTheLabelWithNoAlbumTouched(): void
    {
        $placeId = $this->place('Ferme de la Hulotte');
        $campId = $this->camp($placeId);

        $stmt = $this->pdo->prepare('UPDATE camp_places SET name = ? WHERE id = ?');
        $stmt->execute(['Domaine de Mozet', $placeId]);

        $this->assertSame(
            'Grand camp — Domaine de Mozet — 12–19 juillet 2028',
            $this->describer->describe($campId)
        );
    }

    /**
     * And on the dates, which is the other thing somebody corrects after
     * the album already exists.
     */
    public function testCorrectingTheDatesMovesTheLabelToo(): void
    {
        $campId = $this->camp($this->place('Ferme de la Hulotte'));

        $stmt = $this->pdo->prepare('UPDATE camp_camps SET start_date = ?, end_date = ? WHERE id = ?');
        $stmt->execute(['2028-08-02', '2028-08-09', $campId]);

        $this->assertSame(
            'Grand camp — Ferme de la Hulotte — 2–9 août 2028',
            $this->describer->describe($campId)
        );
    }

    /**
     * A stay recorded with a bare year — half of what a unit remembers
     * about its own past is "on est allés là en 2012", and the module
     * accepts exactly that (ARCHITECTURE.md §8.67: dates OR a bare year,
     * never both, never neither). The label still has to read as a stay,
     * and this is the case where the place and the type carry it alone.
     */
    public function testAStayKnownOnlyByItsYearStillReadsAsOne(): void
    {
        $campId = $this->camp(
            $this->place('Ferme de la Hulotte'),
            Camp::STAY_SHORT_CAMP,
            null,
            null,
            2012
        );

        $this->assertSame('Petit camp — Ferme de la Hulotte — 2012', $this->describer->describe($campId));
    }

    /**
     * The fallback this describer is responsible for triggering: the stay
     * itself is gone, so nobody can name the album and gallery says so.
     * Answering a label anyway — "Grand camp", say, built from nothing —
     * would tell an administrator the owner still exists.
     */
    public function testAnAlbumWhoseStayHasBeenDeletedIsNotNamed(): void
    {
        $this->assertNull($this->describer->describe(4242));
    }
}
