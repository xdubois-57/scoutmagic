<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Member\MemberProfile;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolException;
use Modules\Covoiturage\Service\OfferService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * Offers and requests: the place is the carpool's, seats are counted in
 * people, only acceptance holds them, and a request is accepted whole.
 */
final class OfferServiceTest extends TestCase
{
    private \PDO $pdo;
    private OfferService $service;
    private OfferRepository $offers;
    private SeatRequestRepository $requests;
    private CarpoolRepository $carpools;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->offers = new OfferRepository($this->pdo, H::encryption());
        $this->requests = new SeatRequestRepository($this->pdo, H::encryption());
        $this->carpools = new CarpoolRepository($this->pdo);
        $this->service = new OfferService($this->offers, $this->requests, $this->pdo);
    }

    /**
     * @return array<string, string>
     */
    private function offerInput(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'outbound',
            'departure_time' => '08:30',
            'endpoint' => 'Parking des locaux',
            'seats' => '4',
            'driver_name' => 'Sophie Martin',
            'phone' => '0471 23 45 67',
            'note' => '',
        ], $overrides);
    }

    private static function member(int $memberYearId, string $first, string $last): MemberProfile
    {
        return new MemberProfile(
            $memberYearId, $memberYearId, 'D' . $memberYearId, $first, $last, null, null, null, null,
            null, null, null, null, null, false, false, [], [], '2026-2027'
        );
    }

    /** @return list<MemberProfile> */
    private static function family(): array
    {
        return [self::member(11, 'Tom', 'Leroy'), self::member(12, 'Léa', 'Leroy'), self::member(13, 'Zoé', 'Leroy')];
    }

    public function testAnOfferCarriesOnlyItsMeetingPointNeverThePlace(): void
    {
        // Whatever a form posts about the other end is not read: the
        // destination of an outbound trip is the carpool's place.
        $carpoolId = H::carpool($this->pdo, 10, 11);
        $carpool = $this->carpools->findById($carpoolId);
        $this->assertNotNull($carpool);

        [$outbound] = $this->service->propose($carpool, $this->offerInput([
            'destination' => 'Ailleurs, rue inventée 1',
            'address' => 'Ailleurs, rue inventée 1',
        ]), H::viewer(1));

        $offer = $this->offers->findById($outbound);
        $this->assertSame('Parking des locaux', $offer?->endpoint);
        $this->assertSame(Offer::OUTBOUND, $offer->direction);
        $this->assertSame('Gîte de Han-sur-Lesse, rue des Grottes 12', $this->carpools->findById($carpoolId)?->address);
        $this->assertStringNotContainsString(
            'Ailleurs',
            (string) json_encode($this->pdo->query('SELECT * FROM carpool_offers')->fetchAll(\PDO::FETCH_ASSOC))
        );
    }

    public function testAReturnOfferKeepsTheCarpoolPlaceAsItsDeparture(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo, 10, 11));
        $this->assertNotNull($carpool);

        [$id] = $this->service->propose($carpool, $this->offerInput([
            'direction' => 'return',
            'endpoint' => 'Gare de Wavre',
        ]), H::viewer(1));

        $offer = $this->offers->findById($id);
        $this->assertSame(Offer::RETURN, $offer?->direction);
        $this->assertSame('Gare de Wavre', $offer->endpoint);
    }

    public function testThereIsNoReturnOfferWithoutAReturnDate(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo, 10));
        $this->assertNotNull($carpool);

        $this->expectException(CarpoolException::class);
        $this->service->propose($carpool, $this->offerInput(['direction' => 'return']), H::viewer(1));
    }

    public function testAlsoOfferingTheReturnMakesTwoSeparateCars(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo, 10, 11));
        $this->assertNotNull($carpool);

        $ids = $this->service->propose($carpool, $this->offerInput([
            'also_return' => '1',
            'return_departure_time' => '16:00',
        ]), H::viewer(1));

        $this->assertCount(2, $ids);
        $this->assertSame(Offer::RETURN, $this->offers->findById($ids[1])?->direction);
        $this->assertSame('16:00', $this->offers->findById($ids[1])?->departureTime);
    }

    public function testAMissingReturnTimeSavesNeitherCar(): void
    {
        // Checked before anything is written: an outbound car saved on its
        // own would be created a second time when the driver resubmits.
        $carpool = $this->carpools->findById(H::carpool($this->pdo, 10, 11));
        $this->assertNotNull($carpool);

        try {
            $this->service->propose($carpool, $this->offerInput([
                'also_return' => '1',
                'return_departure_time' => '',
            ]), H::viewer(1));
            $this->fail('A missing return time must be refused.');
        } catch (CarpoolException $e) {
            $this->assertStringContainsString('retour', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM carpool_offers')->fetchColumn());
    }

    public function testAFullCarSaysSoInAWholeSentence(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $this->assertNotNull($carpool);
        $offerId = H::offer($this->pdo, $carpool->id, 1, 2);
        H::request($this->pdo, $offerId, 2, ['Alice', 'Bob'], 'accepted');
        $second = H::request($this->pdo, $offerId, 3, ['Chloé']);
        $offer = $this->offers->findById($offerId);
        $request = $this->requests->findById($second);
        $this->assertNotNull($offer);
        $this->assertNotNull($request);

        try {
            $this->service->accept($request, $offer, H::viewer(1));
            $this->fail('A full car accepted one more passenger.');
        } catch (CarpoolException $e) {
            $this->assertStringStartsWith('Cette voiture est complète', $e->getMessage());
            $this->assertStringNotContainsString('reste que complet', $e->getMessage());
        }
    }

    public function testARequestAlreadyDecidedElsewhereIsNotDecidedAgain(): void
    {
        // The object was read before another tab accepted it; the write is
        // guarded by the status it expects, not by that stale copy.
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $this->assertNotNull($carpool);
        $offerId = H::offer($this->pdo, $carpool->id, 1, 4);
        $requestId = H::request($this->pdo, $offerId, 2, ['Alice']);
        $offer = $this->offers->findById($offerId);
        $stale = $this->requests->findById($requestId);
        $this->assertNotNull($offer);
        $this->assertNotNull($stale);
        $this->service->accept($stale, $offer, H::viewer(1));

        $this->expectException(CarpoolException::class);
        $this->service->refuse($stale, $offer, H::viewer(1));
    }

    public function testARequestNamesSeveralPeopleAndCountsThemAll(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1, 3);
        $offer = $this->offers->findById($offerId);
        $this->assertNotNull($carpool);
        $this->assertNotNull($offer);

        $requestId = $this->service->request(
            $carpool,
            $offer,
            ['passengers' => ['11', '12', '99'], 'phone' => '0495 88 77 66'],
            H::viewer(2),
            self::family(),
            'Famille Leroy'
        );
        $request = $this->requests->findById($requestId);

        // 99 is not in the family: only the family's own members are named.
        $this->assertSame(['Tom Leroy', 'Léa Leroy'], $request?->passengerNames);
        $this->assertSame(2, $request->passengerCount);

        // Pending holds nothing; accepted holds two seats, not one.
        $this->assertSame(0, $this->requests->acceptedSeats($offerId));
        $this->service->accept($request, $offer, H::viewer(1));
        $this->assertSame(2, $this->requests->acceptedSeats($offerId));
    }

    public function testARequestLargerThanTheFreeSeatsIsRefusedWhole(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1, 3);
        H::request($this->pdo, $offerId, 5, ['A', 'B'], SeatRequest::ACCEPTED);
        $pendingId = H::request($this->pdo, $offerId, 6, ['C', 'D']);
        $offer = $this->offers->findById($offerId);
        $pending = $this->requests->findById($pendingId);
        $this->assertNotNull($offer);
        $this->assertNotNull($pending);

        // One seat left, two people asked: accepted whole or not at all.
        try {
            $this->service->accept($pending, $offer, H::viewer(1));
            $this->fail('A request for two was accepted with one seat left.');
        } catch (CarpoolException $e) {
            $this->assertStringContainsString('1 place libre', $e->getMessage());
        }
        $this->assertSame(SeatRequest::PENDING, $this->requests->findById($pendingId)?->status);
        $this->assertSame(2, $this->requests->acceptedSeats($offerId));
    }

    public function testAskingForMorePeopleThanThereAreSeatsLeftIsRefused(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1, 2);
        $offer = $this->offers->findById($offerId);
        $this->assertNotNull($carpool);
        $this->assertNotNull($offer);

        $this->expectException(CarpoolException::class);
        $this->service->request(
            $carpool,
            $offer,
            ['passengers' => ['11', '12', '13'], 'phone' => '0495 88 77 66'],
            H::viewer(2),
            self::family(),
            'Famille Leroy'
        );
    }

    public function testSeatsCannotGoBelowWhatIsAlreadyGranted(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1, 4);
        H::request($this->pdo, $offerId, 5, ['A', 'B', 'C'], SeatRequest::ACCEPTED);
        $offer = $this->offers->findById($offerId);
        $this->assertNotNull($offer);

        try {
            $this->service->update($offer, $this->offerInput(['seats' => '2']), H::viewer(1));
            $this->fail('Seats went below the three already granted.');
        } catch (CarpoolException $e) {
            $this->assertSame(OfferService::seatsBelowTaken(3), $e->getMessage());
        }
        $this->assertSame(4, $this->offers->findById($offerId)?->seats);

        // Exactly the granted number is fine.
        $this->service->update($offer, $this->offerInput(['seats' => '3']), H::viewer(1));
        $this->assertSame(3, $this->offers->findById($offerId)?->seats);
    }

    public function testAChangeOfTimeOrPlaceIsReportedSoPassengersCanBeTold(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offer = $this->offers->findById(H::offer($this->pdo, (int) $carpool?->id, 1));
        $this->assertNotNull($offer);

        $this->assertFalse($this->service->update($offer, $this->offerInput(['seats' => '5']), H::viewer(1)));
        $this->assertTrue($this->service->update($offer, $this->offerInput(['departure_time' => '09:00']), H::viewer(1)));
    }

    public function testOnlyTheDriverDecides(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1);
        $request = $this->requests->findById(H::request($this->pdo, $offerId, 2, ['A']));
        $offer = $this->offers->findById($offerId);
        $this->assertNotNull($request);
        $this->assertNotNull($offer);

        foreach (['accept', 'refuse'] as $action) {
            try {
                $this->service->{$action}($request, $offer, H::viewer(3));
                $this->fail("{$action} by somebody who does not drive.");
            } catch (CarpoolException) {
                $this->assertSame(SeatRequest::PENDING, $this->requests->findById($request->id)?->status);
            }
        }
    }

    public function testRevokingAGrantedSeatIsNotARefusal(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1);
        $requestId = H::request($this->pdo, $offerId, 2, ['A'], SeatRequest::ACCEPTED);
        $offer = $this->offers->findById($offerId);
        $request = $this->requests->findById($requestId);
        $this->assertNotNull($offer);
        $this->assertNotNull($request);

        $this->service->revoke($request, $offer, H::viewer(1));

        $this->assertSame(SeatRequest::REVOKED, $this->requests->findById($requestId)?->status);
        $this->assertSame(0, $this->requests->acceptedSeats($offerId));
    }

    public function testAPendingRequestCannotBeRevokedOnlyRefused(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1);
        $request = $this->requests->findById(H::request($this->pdo, $offerId, 2, ['A']));
        $offer = $this->offers->findById($offerId);
        $this->assertNotNull($offer);
        $this->assertNotNull($request);

        $this->expectException(CarpoolException::class);
        $this->service->revoke($request, $offer, H::viewer(1));
    }

    public function testTheRequesterWithdrawsAndTheRowGoes(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1);
        $requestId = H::request($this->pdo, $offerId, 2, ['A'], SeatRequest::ACCEPTED);
        $request = $this->requests->findById($requestId);
        $this->assertNotNull($request);

        try {
            $this->service->withdraw($request, H::viewer(3));
            $this->fail('Somebody else withdrew the request.');
        } catch (CarpoolException) {
        }
        $this->service->withdraw($request, H::viewer(2));

        $this->assertNull($this->requests->findById($requestId));
    }

    public function testNobodyAsksASeatInTheirOwnCarNorTwiceInTheSameCar(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offer = $this->offers->findById(H::offer($this->pdo, (int) $carpool?->id, 1));
        $this->assertNotNull($carpool);
        $this->assertNotNull($offer);
        $input = ['passengers' => ['11'], 'phone' => '0495 88 77 66'];

        try {
            $this->service->request($carpool, $offer, $input, H::viewer(1), self::family(), 'Famille Martin');
            $this->fail('The driver asked a seat in their own car.');
        } catch (CarpoolException) {
        }

        $this->service->request($carpool, $offer, $input, H::viewer(2), self::family(), 'Famille Leroy');
        $this->expectException(CarpoolException::class);
        $this->service->request($carpool, $offer, $input, H::viewer(2), self::family(), 'Famille Leroy');
    }

    public function testCancellingACarRemovesItsRequestsAndReturnsThoseStillHolding(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $offerId = H::offer($this->pdo, (int) $carpool?->id, 1);
        H::request($this->pdo, $offerId, 2, ['A'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $offerId, 3, ['B']);
        H::request($this->pdo, $offerId, 4, ['C'], SeatRequest::REFUSED);
        $offer = $this->offers->findById($offerId);
        $this->assertNotNull($offer);

        $affected = $this->service->cancel($offer, H::viewer(1));

        $this->assertCount(2, $affected);
        $this->assertNull($this->offers->findById($offerId));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM carpool_requests')->fetchColumn());
    }

    public function testAPastCarpoolTakesNoNewCar(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo, -3));
        $this->assertNotNull($carpool);

        $this->expectException(CarpoolException::class);
        $this->service->propose($carpool, $this->offerInput(), H::viewer(1));
    }

    public function testThePhoneIsRequiredAndLooksLikeOne(): void
    {
        $carpool = $this->carpools->findById(H::carpool($this->pdo));
        $this->assertNotNull($carpool);

        $this->expectException(CarpoolException::class);
        $this->service->propose($carpool, $this->offerInput(['phone' => 'appelez-moi']), H::viewer(1));
    }
}
