<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\Participation;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * « Ma participation » (#835): ONE rule that the list's badges, the day's
 * banner and the agenda line all read, so they cannot interpret the same
 * state differently.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ParticipationTest extends TestCase
{
    private \PDO $pdo;
    private CarpoolBoard $board;
    private int $carpool;
    private int $offerOut;
    private int $offerBack;

    private const DRIVER = 1;
    private const FAMILY = 2;
    private const OTHER = 3;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->board = new CarpoolBoard(
            new CarpoolRepository($this->pdo),
            new OfferRepository($this->pdo, H::encryption()),
            new SeatRequestRepository($this->pdo, H::encryption()),
            new SettingService(new SettingRepository($this->pdo)),
            H::sections($this->pdo)
        );
        // Outbound today, return tomorrow.
        $this->carpool = H::carpool($this->pdo, 0, 1);
        $this->offerOut = H::offer($this->pdo, $this->carpool, self::DRIVER, 4);
        $this->offerBack = H::offer($this->pdo, $this->carpool, self::DRIVER, 4, 'return');
    }

    /**
     * @return list<Participation>
     */
    private function resolve(int $accountId): array
    {
        $offers = (new OfferRepository($this->pdo, H::encryption()))->findByCarpools([$this->carpool])[$this->carpool] ?? [];
        $requests = (new SeatRequestRepository($this->pdo, H::encryption()))
            ->findByOffers(array_map(static fn(Offer $o): int => $o->id, $offers));

        return Participation::forAccount($accountId, $offers, $requests);
    }

    public function testTheDriverTheConfirmedAndThePendingAreToldApart(): void
    {
        H::request($this->pdo, $this->offerOut, self::FAMILY, ['Tom'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $this->offerBack, self::OTHER, ['Léa'], SeatRequest::PENDING);

        $this->assertSame([Participation::DRIVER, Participation::DRIVER], array_map(
            static fn(Participation $p): string => $p->role,
            $this->resolve(self::DRIVER)
        ));
        $this->assertSame(['Place confirmée'], array_map(
            static fn(Participation $p): string => $p->label(),
            $this->resolve(self::FAMILY)
        ));
        $this->assertSame(['À confirmer'], array_map(
            static fn(Participation $p): string => $p->label(),
            $this->resolve(self::OTHER)
        ));
    }

    public function testARefusedRevokedOrWithdrawnRequestIsNoParticipation(): void
    {
        foreach ([SeatRequest::REFUSED, SeatRequest::REVOKED] as $status) {
            $id = H::request($this->pdo, $this->offerOut, self::FAMILY, ['Tom'], SeatRequest::PENDING);
            if ($status === SeatRequest::REVOKED) {
                (new SeatRequestRepository($this->pdo, H::encryption()))->transition($id, SeatRequest::PENDING, SeatRequest::ACCEPTED);
                (new SeatRequestRepository($this->pdo, H::encryption()))->transition($id, SeatRequest::ACCEPTED, SeatRequest::REVOKED);
            } else {
                (new SeatRequestRepository($this->pdo, H::encryption()))->transition($id, SeatRequest::PENDING, SeatRequest::REFUSED);
            }
            $this->assertSame([], $this->resolve(self::FAMILY), $status . ' still counts as a participation');
            $this->pdo->exec('DELETE FROM carpool_requests');
        }
    }

    public function testTheTwoDirectionsKeepTheirOwnRole(): void
    {
        // The driver of the outbound car rides back as a passenger.
        $this->pdo->exec('UPDATE carpool_offers SET driver_user_account_id = 9 WHERE id = ' . $this->offerBack);
        H::request($this->pdo, $this->offerBack, self::DRIVER, ['Marie'], SeatRequest::ACCEPTED);

        $best = Participation::best($this->resolve(self::DRIVER));

        $this->assertSame(Participation::DRIVER, $best[Offer::OUTBOUND]->role);
        $this->assertSame(Participation::CONFIRMED, $best[Offer::RETURN]->role);
    }

    public function testBadgesNameTheDirectionOnlyWhenItTellsSomething(): void
    {
        $driver = new Participation(Participation::DRIVER, $this->offer(Offer::OUTBOUND));
        $driverBack = new Participation(Participation::DRIVER, $this->offer(Offer::RETURN));
        $confirmedBack = new Participation(Participation::CONFIRMED, $this->offer(Offer::RETURN));

        // No return trip: nothing to disambiguate.
        $this->assertSame([['status' => 'info', 'label' => 'Conducteur']], Participation::badges([$driver], false));
        // Same role both ways: one badge.
        $this->assertSame([['status' => 'info', 'label' => 'Conducteur']], Participation::badges([$driver, $driverBack], true));
        // One direction only, on a carpool that has two: say which.
        $this->assertSame([['status' => 'confirmed', 'label' => 'Retour · Place confirmée']], Participation::badges([$confirmedBack], true));
        // Different roles: both, in order.
        $this->assertSame(
            [['status' => 'info', 'label' => 'Aller · Conducteur'], ['status' => 'confirmed', 'label' => 'Retour · Place confirmée']],
            Participation::badges([$confirmedBack, $driver], true)
        );
        $this->assertSame([], Participation::badges([], true));
    }

    public function testTheListBadgesTheReadersParticipationAndNobodyElses(): void
    {
        H::request($this->pdo, $this->offerBack, self::FAMILY, ['Tom'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $this->offerOut, self::OTHER, ['Léa'], SeatRequest::PENDING);

        $badges = fn(int $account): array => $this->board->memberList(H::viewer($account))['upcoming'][0]['participation'];

        $this->assertSame([['status' => 'info', 'label' => 'Conducteur']], $badges(self::DRIVER));
        $this->assertSame([['status' => 'confirmed', 'label' => 'Retour · Place confirmée']], $badges(self::FAMILY));
        $this->assertSame([['status' => 'pending', 'label' => 'Aller · À confirmer']], $badges(self::OTHER));
        $this->assertSame([], $badges(99));
    }

    public function testNoBannerBeforeTheDayOfTheTrip(): void
    {
        $tomorrow = H::carpool($this->pdo, 1);
        H::offer($this->pdo, $tomorrow, self::DRIVER);
        $carpool = (new CarpoolRepository($this->pdo))->findById($tomorrow);
        $this->assertNotNull($carpool);

        $this->assertNull($this->board->carpoolPage($carpool, H::viewer(self::DRIVER))['day_banner']);
    }

    public function testTheDriversBannerGivesTheTimeTheMeetingPointAndTheContactsTheyMaySee(): void
    {
        H::request($this->pdo, $this->offerOut, self::FAMILY, ['Tom', 'Léa'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $this->offerOut, self::OTHER, ['Paul'], SeatRequest::PENDING);

        $banner = $this->banner(self::DRIVER, Offer::OUTBOUND);

        $this->assertNotNull($banner);
        $this->assertSame('driver', $banner['role']);
        $this->assertSame('8 h 30', $banner['time']);
        $this->assertSame('Parking des locaux', $banner['meeting']);
        $this->assertSame('Gîte de Han-sur-Lesse, rue des Grottes 12', $banner['place']);
        $this->assertCount(2, $banner['passengers']);
        $this->assertSame(['Tom', 'Léa'], $banner['passengers'][0]['who']);
        $this->assertSame('0495 88 77 66', $banner['passengers'][0]['phone']);
        $this->assertTrue($banner['passengers'][0]['confirmed']);
        $this->assertFalse($banner['passengers'][1]['confirmed']);
    }

    public function testTheConfirmedPassengerSeesTheDriverAndTheirPhone(): void
    {
        H::request($this->pdo, $this->offerOut, self::FAMILY, ['Tom'], SeatRequest::ACCEPTED);

        $banner = $this->banner(self::FAMILY, Offer::OUTBOUND);

        $this->assertNotNull($banner);
        $this->assertSame('confirmed', $banner['role']);
        $this->assertSame(['Tom'], $banner['who']);
        $this->assertSame('Sophie Martin', $banner['driver']);
        $this->assertSame('0478 12 34 56', $banner['driver_phone']);
    }

    public function testThePendingRequesterIsWarnedAndNeverGetsTheDriversPhone(): void
    {
        H::request($this->pdo, $this->offerOut, self::OTHER, ['Léa'], SeatRequest::PENDING);

        $banner = $this->banner(self::OTHER, Offer::OUTBOUND);

        $this->assertNotNull($banner);
        $this->assertSame('pending', $banner['role']);
        $this->assertSame('warning', $banner['tone']);
        $this->assertSame(['Léa'], $banner['who']);
        $this->assertNull($banner['driver_phone']);
        $this->assertStringNotContainsString('0478', (string) json_encode($banner));
    }

    public function testTheReturnBannerStartsFromThePlaceAndEndsAtTheDriversPoint(): void
    {
        // The return is today too.
        $carpool = H::carpool($this->pdo, 0, 0);
        $back = H::offer($this->pdo, $carpool, self::DRIVER, 4, 'return');
        $entity = (new CarpoolRepository($this->pdo))->findById($carpool);
        $this->assertNotNull($entity);

        $banner = $this->board->carpoolPage($entity, H::viewer(self::DRIVER), null, Offer::RETURN)['day_banner'];

        $this->assertNotNull($banner);
        $this->assertSame('Gîte de Han-sur-Lesse, rue des Grottes 12', $banner['meeting']);
        $this->assertSame('Parking des locaux', $banner['destination']);
        $this->assertGreaterThan(0, $back);
    }

    public function testSomeoneWhoDoesNotTakePartGetsNoBanner(): void
    {
        $this->assertNull($this->banner(99, Offer::OUTBOUND));
    }

    /**
     * @return ?array<string, mixed>
     */
    private function banner(int $account, string $direction): ?array
    {
        $carpool = (new CarpoolRepository($this->pdo))->findById($this->carpool);
        $this->assertNotNull($carpool);
        $banner = $this->board->carpoolPage($carpool, H::viewer($account), null, $direction)['day_banner'];
        self::assertTrue($banner === null || is_array($banner));

        return $banner;
    }

    private function offer(string $direction): Offer
    {
        return new Offer(1, $this->carpool, $direction, '08:30', 'Parking', 4, self::DRIVER, 'Sophie', '0478', null);
    }
}
