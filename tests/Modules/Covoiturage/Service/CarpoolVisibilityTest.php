<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\Role;
use Modules\Covoiturage\Repository\CarpoolEvent;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\CarpoolViewer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;
use Tests\NothingInClear;

/**
 * D8, one test per line of its table, through what the pages are actually
 * built from (Service\CarpoolBoard): a passenger or a phone the reader may
 * not see is never in the array.
 *
 * | the driver                 | every request on their own offers        |
 * | the requester              | their own request                        |
 * | staff of a linked section  | every offer and passenger of the carpool |
 * | Staff d'U and super-admin  | everything                               |
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CarpoolVisibilityTest extends TestCase
{
    private const DRIVER = 1;
    private const RIDER = 2;
    private const OTHER_FAMILY = 3;
    private const LOUVETEAUX = 20;
    private const ECLAIREURS = 30;

    private \PDO $pdo;
    private CarpoolBoard $board;
    private CarpoolRepository $carpools;
    private int $carpoolId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->carpools = new CarpoolRepository($this->pdo);
        $this->board = new CarpoolBoard(
            $this->carpools,
            new OfferRepository($this->pdo, H::encryption()),
            new SeatRequestRepository($this->pdo, H::encryption()),
            new SettingService(new SettingRepository($this->pdo))
        );

        // A Louveteaux weekend: one car, the driver's; the rider's family
        // accepted, another family pending.
        $this->carpoolId = H::carpool($this->pdo, 10, null, [
            new CarpoolEvent(501, 'Week-end de section — Louveteaux', self::LOUVETEAUX, 'Louveteaux'),
        ]);
        $offerId = H::offer($this->pdo, $this->carpoolId, self::DRIVER);
        H::request($this->pdo, $offerId, self::RIDER, ['Tom Leroy', 'Léa Leroy'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $offerId, self::OTHER_FAMILY, ['Kim Nguyen']);
    }

    /**
     * @return array<string, mixed>
     */
    private function offerSeenBy(CarpoolViewer $viewer): array
    {
        $carpool = $this->carpools->findById($this->carpoolId);
        $this->assertNotNull($carpool);

        return $this->board->carpoolPage($carpool, $viewer)['directions']['outbound'][0];
    }

    /**
     * @param array<string, mixed> $offer
     * @return list<string>
     */
    private static function passengers(array $offer): array
    {
        $names = [];
        foreach ($offer['requests'] as $request) {
            array_push($names, ...$request['who']);
        }

        return $names;
    }

    public function testEverybodySeesTheCarItself(): void
    {
        $offer = $this->offerSeenBy(H::viewer(99));

        $this->assertSame('Sophie Martin', $offer['driver']);
        $this->assertSame('Parking des locaux', $offer['endpoint']);
        $this->assertSame(2, $offer['taken']);
        // … and nobody who rides in it.
        $this->assertSame([], $offer['requests']);
        $this->assertFalse($offer['sees_requests']);
    }

    public function testTheDriverSeesEveryRequestOnTheirCar(): void
    {
        $offer = $this->offerSeenBy(H::viewer(self::DRIVER));

        $this->assertSame(['Tom Leroy', 'Léa Leroy', 'Kim Nguyen'], self::passengers($offer));
        $this->assertTrue($offer['requests'][1]['may_decide']);
        $this->assertTrue($offer['requests'][0]['may_revoke']);
    }

    public function testTheRequesterSeesTheirOwnRequestAndNoOneElses(): void
    {
        $offer = $this->offerSeenBy(H::viewer(self::OTHER_FAMILY));

        $this->assertSame(['Kim Nguyen'], $offer['my_request']['who']);
        $this->assertFalse($offer['sees_requests']);
        $this->assertSame(['Kim Nguyen'], self::passengers($offer));
    }

    public function testTheStaffOfALinkedSectionSeesEveryPassenger(): void
    {
        $offer = $this->offerSeenBy(H::viewer(50, Role::CHIEF, [self::LOUVETEAUX]));

        $this->assertTrue($offer['sees_requests']);
        $this->assertSame(['Tom Leroy', 'Léa Leroy', 'Kim Nguyen'], self::passengers($offer));
        $this->assertFalse($offer['requests'][1]['may_decide'], 'Staff sees; the driver decides.');
    }

    public function testTheStaffOfAnUnlinkedSectionSeesNeitherOffersDetailNorPassengers(): void
    {
        $viewer = H::viewer(51, Role::CHIEF, [self::ECLAIREURS]);
        $carpool = $this->carpools->findById($this->carpoolId);
        $this->assertNotNull($carpool);
        $page = $this->board->carpoolPage($carpool, $viewer);
        $offer = $page['directions']['outbound'][0];

        $this->assertSame([], $offer['requests']);
        $this->assertTrue($page['staff_hides_passengers']);

        $row = $this->board->organizerList($viewer)[0];
        $this->assertFalse($row['visible']);
        $this->assertNull($row['cars']);
        $this->assertNull($row['taken']);
        $this->assertNull($row['pending']);
    }

    public function testTheStaffDuniteAndTheSuperAdminSeeEverything(): void
    {
        foreach ([Role::ADMIN, Role::SUPERADMIN] as $role) {
            $offer = $this->offerSeenBy(H::viewer(60, $role));
            $this->assertSame(['Tom Leroy', 'Léa Leroy', 'Kim Nguyen'], self::passengers($offer));

            $row = $this->board->organizerList(H::viewer(60, $role))[0];
            $this->assertTrue($row['visible']);
            $this->assertSame(1, $row['pending']);
        }
    }

    public function testACarpoolWithoutEventsIsSeenByTheStaffOfItsChosenSection(): void
    {
        $id = H::carpool($this->pdo, 12, null, [], self::ECLAIREURS, 'Bastogne, centre scout');
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->assertTrue(H::viewer(51, Role::CHIEF, [self::ECLAIREURS])->seesPassengersOf($carpool));
        $this->assertFalse(H::viewer(50, Role::CHIEF, [self::LOUVETEAUX])->seesPassengersOf($carpool));
    }

    public function testOneLinkedSectionIsEnoughForItsStaff(): void
    {
        // A unit party replicated on two calendars: the staff of either
        // section sees it.
        $id = H::carpool($this->pdo, 20, null, [
            new CarpoolEvent(601, "Fête d'unité — Louveteaux", self::LOUVETEAUX, 'Louveteaux'),
            new CarpoolEvent(602, "Fête d'unité — Éclaireurs", self::ECLAIREURS, 'Éclaireurs'),
        ]);
        $carpool = $this->carpools->findById($id);
        $this->assertNotNull($carpool);

        $this->assertTrue(H::viewer(51, Role::CHIEF, [self::ECLAIREURS])->seesPassengersOf($carpool));
        $this->assertSame("Fête d'unité", $carpool->title());
    }

    public function testAPhoneGoesToTheOtherPartyOfAnAcceptedRequestAndToNobodyElse(): void
    {
        // The rider whose request was accepted sees the driver's phone.
        $this->assertSame('0478 12 34 56', $this->offerSeenBy(H::viewer(self::RIDER))['my_request']['driver_phone']);
        // The one still pending does not.
        $this->assertNull($this->offerSeenBy(H::viewer(self::OTHER_FAMILY))['my_request']['driver_phone']);

        // The driver sees the accepted family's phone, not the pending one's.
        $driverView = $this->offerSeenBy(H::viewer(self::DRIVER));
        $this->assertSame('0495 88 77 66', $driverView['requests'][0]['phone']);
        $this->assertNull($driverView['requests'][1]['phone']);

        // The staff sees who rides, never a number — value by value
        // through the shared reader rather than in a `json_encode()` of
        // the view, for the reason `Tests\NothingInClear` gives: an escape
        // spells « 0478 » out of bytes that do not contain it (#533).
        foreach ([H::viewer(50, Role::CHIEF, [self::LOUVETEAUX]), H::viewer(60, Role::SUPERADMIN)] as $staff) {
            NothingInClear::inValuesOf($this->offerSeenBy($staff))->assertAbsent('0495', '0478');
        }
    }

    public function testADriverWhoIsAlsoAChiefGetsBothViewsAndLosesNothing(): void
    {
        // D1: the staff view is additive.
        $offer = $this->offerSeenBy(H::viewer(self::DRIVER, Role::CHIEF, [self::ECLAIREURS]));

        $this->assertTrue($offer['mine']);
        $this->assertSame(['Tom Leroy', 'Léa Leroy', 'Kim Nguyen'], self::passengers($offer));
    }
}
