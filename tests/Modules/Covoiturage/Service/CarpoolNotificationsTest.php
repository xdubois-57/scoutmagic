<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Member\MemberProfile;
use Core\Notification\NotificationService;
use Core\Security\Role;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolNotifier;
use Modules\Covoiturage\Service\OfferService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * Each of the eight types goes to its one party and to nobody else; the
 * staff gets nothing on account of its role (D11); a chief who drives gets
 * the driver's, once; and no phone number is ever in one.
 */
final class CarpoolNotificationsTest extends TestCase
{
    private const DRIVER = 1;
    private const RIDER = 2;
    private const PENDING_RIDER = 3;
    private const STAFF = 50;

    private \PDO $pdo;
    private OfferService $service;
    private OfferRepository $offers;
    private SeatRequestRepository $requests;
    private CarpoolRepository $carpools;
    /** @var list<array{type: string, to: list<int>, title: string, body: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->offers = new OfferRepository($this->pdo, H::encryption());
        $this->requests = new SeatRequestRepository($this->pdo, H::encryption());
        $this->carpools = new CarpoolRepository($this->pdo);

        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(function (string $type, array $recipients, array $payload): void {
            $this->sent[] = [
                'type' => $type,
                'to' => array_map(static fn(array $r): int => (int) $r['userAccountId'], $recipients),
                'title' => (string) $payload['title'],
                'body' => (string) $payload['body'],
            ];
        });
        $this->service = new OfferService($this->offers, $this->requests, $this->pdo, new CarpoolNotifier($notifications));
    }

    /**
     * @return array{0: \Modules\Covoiturage\Repository\Carpool, 1: \Modules\Covoiturage\Repository\Offer}
     */
    private function car(int $seats = 4): array
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo, 10, 11));
        $offer = $this->offers->findById(H::offer($this->pdo, (int) $carpool?->id, self::DRIVER, $seats));
        $this->assertNotNull($carpool);
        $this->assertNotNull($offer);

        return [$carpool, $offer];
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_column($this->sent, 'type');
    }

    private function assertOnlySent(string $type, array $to): void
    {
        $this->assertSame([$type], $this->types());
        $this->assertSame($to, $this->sent[0]['to']);
        $this->assertStringNotContainsString('0478', $this->sent[0]['body']);
        $this->assertStringNotContainsString('0495', $this->sent[0]['body']);
    }

    public function testANewRequestGoesToTheDriverOnly(): void
    {
        [$carpool, $offer] = $this->car();
        $family = [
            new MemberProfile(11, 11, 'D11', 'Tom', 'Leroy', null, null, null, null, null, null, null, null, null, false, false, [], [], '2026'),
            new MemberProfile(12, 12, 'D12', 'Léa', 'Leroy', null, null, null, null, null, null, null, null, null, false, false, [], [], '2026'),
        ];

        $this->service->request(
            $carpool,
            $offer,
            ['passengers' => ['11', '12'], 'phone' => '0495 88 77 66'],
            H::viewer(self::RIDER),
            $family,
            'Famille Leroy'
        );

        $this->assertOnlySent('covoiturage.request_received', [self::DRIVER]);
        $this->assertStringStartsWith('Famille Leroy demande 2 places — aller du ', $this->sent[0]['body']);
    }

    public function testAcceptingRefusingAndRevokingGoToTheRequesterOnly(): void
    {
        [$carpool, $offer] = $this->car();
        $accept = $this->requests->findById(H::request($this->pdo, $offer->id, self::RIDER, ['Tom']));
        $refuse = $this->requests->findById(H::request($this->pdo, $offer->id, self::PENDING_RIDER, ['Kim']));
        $this->assertNotNull($accept);
        $this->assertNotNull($refuse);

        $this->service->accept($accept, $offer, H::viewer(self::DRIVER), $carpool);
        $this->assertOnlySent('covoiturage.request_accepted', [self::RIDER]);
        $this->assertStringContainsString('Parking des locaux, 8 h 30', $this->sent[0]['body']);

        $this->sent = [];
        $this->service->refuse($refuse, $offer, H::viewer(self::DRIVER), $carpool);
        $this->assertOnlySent('covoiturage.request_refused', [self::PENDING_RIDER]);

        $this->sent = [];
        $accepted = $this->requests->findById($accept->id);
        $this->assertNotNull($accepted);
        $this->service->revoke($accepted, $offer, H::viewer(self::DRIVER), $carpool);
        $this->assertOnlySent('covoiturage.seat_revoked', [self::RIDER]);
    }

    public function testARevokedSeatIsNotWordedLikeARefusal(): void
    {
        [$carpool, $offer] = $this->car();
        $request = $this->requests->findById(H::request($this->pdo, $offer->id, self::RIDER, ['Tom'], SeatRequest::ACCEPTED));
        $pending = $this->requests->findById(H::request($this->pdo, $offer->id, self::PENDING_RIDER, ['Kim']));
        $this->assertNotNull($request);
        $this->assertNotNull($pending);

        $this->service->revoke($request, $offer, H::viewer(self::DRIVER), $carpool);
        $this->service->refuse($pending, $offer, H::viewer(self::DRIVER), $carpool);

        $this->assertNotSame($this->sent[0]['title'], $this->sent[1]['title']);
        $this->assertStringContainsString('ne peut plus', $this->sent[0]['body']);
        $this->assertStringContainsString('ne peut pas', $this->sent[1]['body']);
    }

    public function testAWithdrawalGoesToTheDriverOnly(): void
    {
        [$carpool, $offer] = $this->car();
        $request = $this->requests->findById(H::request($this->pdo, $offer->id, self::RIDER, ['Tom', 'Léa'], SeatRequest::ACCEPTED));
        $this->assertNotNull($request);

        $this->service->withdraw($request, H::viewer(self::RIDER), $carpool, $offer);

        $this->assertOnlySent('covoiturage.request_withdrawn', [self::DRIVER]);
        $this->assertStringContainsString('2 places se libèrent', $this->sent[0]['body']);
    }

    public function testAChangedCarIsToldToTheAcceptedPassengersOnly(): void
    {
        [$carpool, $offer] = $this->car();
        H::request($this->pdo, $offer->id, self::RIDER, ['Tom'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $offer->id, self::PENDING_RIDER, ['Kim']);
        $input = [
            'departure_time' => '09:00', 'endpoint' => 'Parking des locaux', 'seats' => '4',
            'driver_name' => 'Sophie Martin', 'phone' => '0478 12 34 56', 'note' => '',
        ];

        $this->service->update($offer, $input, H::viewer(self::DRIVER), $carpool);

        $this->assertOnlySent('covoiturage.offer_changed', [self::RIDER]);
        $this->assertStringContainsString('départ à 9 h 00 au lieu de 8 h 30', $this->sent[0]['body']);

        // A change of seats or of note is not news to anybody.
        $this->sent = [];
        $after = $this->offers->findById($offer->id);
        $this->assertNotNull($after);
        $this->service->update($after, array_merge($input, ['seats' => '5', 'note' => 'Un sac']), H::viewer(self::DRIVER), $carpool);
        $this->assertSame([], $this->sent);
    }

    public function testACancelledCarIsToldToTheAcceptedAndThePending(): void
    {
        [$carpool, $offer] = $this->car();
        H::request($this->pdo, $offer->id, self::RIDER, ['Tom'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $offer->id, self::PENDING_RIDER, ['Kim']);
        H::request($this->pdo, $offer->id, 4, ['Zoé'], SeatRequest::REFUSED);

        $this->service->cancel($offer, H::viewer(self::DRIVER), $carpool);

        $this->assertSame(['covoiturage.offer_cancelled', 'covoiturage.offer_cancelled'], $this->types());
        $this->assertSame([self::RIDER], $this->sent[0]['to']);
        $this->assertStringContainsString("n'est plus réservée", $this->sent[0]['body']);
        $this->assertSame([self::PENDING_RIDER], $this->sent[1]['to']);
    }

    public function testTheStaffReceivesNothingOnAccountOfItsRole(): void
    {
        // A chief of the section watches a request being accepted and a
        // car changing: no notification names them.
        [$carpool, $offer] = $this->car();
        $request = $this->requests->findById(H::request($this->pdo, $offer->id, self::RIDER, ['Tom']));
        $this->assertNotNull($request);
        $this->service->accept($request, $offer, H::viewer(self::DRIVER, Role::CHIEF, [20]), $carpool);
        $this->service->cancel($offer, H::viewer(self::DRIVER), $carpool);

        foreach ($this->sent as $sent) {
            $this->assertNotContains(self::STAFF, $sent['to']);
        }
    }

    public function testAChiefWhoDrivesGetsTheDriversNotificationOnce(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offer = $this->offers->findById(H::offer($this->pdo, (int) $carpool?->id, self::STAFF));
        $this->assertNotNull($carpool);
        $this->assertNotNull($offer);
        $family = [new MemberProfile(11, 11, 'D11', 'Tom', 'Leroy', null, null, null, null, null, null, null, null, null, false, false, [], [], '2026')];

        $this->service->request($carpool, $offer, ['passengers' => ['11'], 'phone' => '0495 88 77 66'], H::viewer(self::RIDER), $family, 'Famille Leroy');

        $this->assertOnlySent('covoiturage.request_received', [self::STAFF]);
    }
}
