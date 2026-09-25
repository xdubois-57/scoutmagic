<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Repository;

use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\NothingInClear;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * Names, phones and the driver's note are BLOBs encrypted at rest, and come
 * back readable through the repositories — nowhere else.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PersonalDataIsEncryptedTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
    }

    public function testNothingPersonalIsStoredInClear(): void
    {
        $carpool = H::carpool($this->pdo);
        $offerId = (new OfferRepository($this->pdo, H::encryption()))->create(
            $carpool, 'outbound', '08:30', 'Parking des locaux', 4, 1, 'Sophie Martin', '0478 12 34 56', 'Un sac par enfant'
        );
        $requestId = (new SeatRequestRepository($this->pdo, H::encryption()))->create(
            $offerId, 2, 'Famille Leroy', ['Tom Leroy', 'Léa Leroy'], '0495 88 77 66'
        );

        // Column by column through the shared reader, never a
        // `json_encode()` of the rows — `Tests\NothingInClear` carries the
        // reason (issue #533) and the demonstration.
        $stored = NothingInClear::inTables($this->pdo, 'carpool_offers', 'carpool_requests');
        $stored->assertAbsent('Sophie', '0478', 'sac par enfant', 'Leroy', 'Tom', '0495');

        // The meeting point is not personal (D7) — the schema calls it
        // `endpoint` and says why it stays in clear: it is shown to every
        // member, and it is a meeting point rather than a home address.
        $stored->assertReadableIn('carpool_offers.endpoint', 'Parking des locaux');

        $offer = (new OfferRepository($this->pdo, H::encryption()))->findById($offerId);
        $request = (new SeatRequestRepository($this->pdo, H::encryption()))->findById($requestId);
        $this->assertSame('0478 12 34 56', $offer?->phone);
        $this->assertSame('Un sac par enfant', $offer->note);
        $this->assertSame(['Tom Leroy', 'Léa Leroy'], $request?->passengerNames);
        $this->assertSame(2, $request->passengerCount);
    }
}
