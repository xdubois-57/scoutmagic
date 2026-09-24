<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Service\IcsBuilder;
use Modules\Covoiturage\Repository\CarpoolEvent;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolAgendaEnricher;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * The agenda line (IT-05): direction, time, place, status and link, on
 * every linked event — and never a phone number in the ICS (D12).
 */
final class CarpoolAgendaTest extends TestCase
{
    private \PDO $pdo;
    private CarpoolAgendaEnricher $enricher;
    private int $driver;
    private int $rider;
    private int $offerOut;
    private int $offerBack;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $encryption = H::encryption();
        $account = function (string $email) use ($encryption): int {
            $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
            $stmt->execute([$encryption->encrypt($email, 'user_accounts.email'), $encryption->blindIndex($email, 'email')]);

            return (int) $this->pdo->lastInsertId();
        };
        $this->driver = $account('driver@test.be');
        $this->rider = $account('rider@test.be');
        $account('staff@test.be');

        $carpool = H::carpool($this->pdo, 10, 11, [
            new CarpoolEvent(701, "Fête d'unité — Baladins", 10, 'Baladins'),
            new CarpoolEvent(702, "Fête d'unité — Louveteaux", 20, 'Louveteaux'),
        ]);
        $this->offerOut = H::offer($this->pdo, $carpool, $this->driver, 4);
        $this->offerBack = (new OfferRepository($this->pdo, $encryption))->create(
            $carpool, 'return', '16:00', 'Gare de Wavre', 2, $this->driver, 'Marc Dubois', '0495 11 22 33', null
        );

        $this->enricher = new CarpoolAgendaEnricher(
            new CarpoolRepository($this->pdo),
            new OfferRepository($this->pdo, $encryption),
            new SeatRequestRepository($this->pdo, $encryption),
            new UserAccountRepository($this->pdo, $encryption),
            'https://unite.example'
        );
    }

    /**
     * @return array<int, list<string>>
     */
    private function linesFor(string $email): array
    {
        return $this->enricher->describeEvents([701, 702, 703], new VirtualEventViewer(Role::IDENTIFIED, $email, 1));
    }

    public function testTheDriverSeesTheirCarsOnEveryLinkedEvent(): void
    {
        H::request($this->pdo, $this->offerOut, $this->rider, ['Tom', 'Léa'], SeatRequest::ACCEPTED);

        $lines = $this->linesFor('driver@test.be');

        $this->assertSame([701, 702], array_keys($lines));
        $this->assertSame([
            "Covoiturage — vous conduisez à l'aller, 8 h 30, Parking des locaux. 2 places libres.",
            'Covoiturage — vous conduisez au retour, 16 h 00, Gare de Wavre. 2 places libres.',
            'Voir : https://unite.example/covoiturage/1',
        ], $lines[701]);
    }

    public function testTheRequesterSeesEachTripWithItsStatus(): void
    {
        H::request($this->pdo, $this->offerOut, $this->rider, ['Tom'], SeatRequest::PENDING);
        H::request($this->pdo, $this->offerBack, $this->rider, ['Tom'], SeatRequest::ACCEPTED);

        $this->assertSame([
            'Covoiturage — aller 8 h 30, Parking des locaux : en attente.',
            'Covoiturage — retour 16 h 00, Gare de Wavre : confirmé.',
            'Voir : https://unite.example/covoiturage/1',
        ], $this->linesFor('rider@test.be')[702]);
    }

    public function testARefusedRequestLeavesNoLine(): void
    {
        $id = H::request($this->pdo, $this->offerOut, $this->rider, ['Tom']);
        $this->assertNotSame([], $this->linesFor('rider@test.be'));

        (new SeatRequestRepository($this->pdo, H::encryption()))->transition($id, SeatRequest::PENDING, SeatRequest::REFUSED);

        // The feed is rebuilt at every fetch: at the next refresh, gone.
        $this->assertSame([], $this->linesFor('rider@test.be'));
    }

    public function testSomebodyWithNothingInTheCarpoolGetsNoLine(): void
    {
        $this->assertSame([], $this->linesFor('staff@test.be'));
        $this->assertSame([], $this->enricher->describeEvents([701], new VirtualEventViewer(Role::PUBLIC, null, 1)));
    }

    public function testNoPhoneNumberEverReachesTheIcs(): void
    {
        H::request($this->pdo, $this->offerOut, $this->rider, ['Tom'], SeatRequest::ACCEPTED);
        $description = "Au programme : jeux.\n\n" . implode("\n", $this->linesFor('driver@test.be')[701]);

        $ics = (new IcsBuilder())->build('Mon calendrier', [new CalendarEvent(
            id: 701,
            calendarId: 1,
            title: "Fête d'unité",
            startDate: H::day(10),
            endDate: null,
            startTime: null,
            endTime: null,
            location: 'Plaine de Basse-Wavre',
            description: $description,
            sequence: 0,
            autoCreateRetro: false,
            createdBy: null,
            updatedAt: '2026-09-24 10:00:00'
        )]);

        $unfolded = str_replace(["\r\n ", "\r\n\t"], '', $ics);
        $this->assertStringContainsString('vous conduisez', $unfolded);
        foreach (['0478', '0495', '12 34 56', '11 22 33', '88 77 66'] as $digits) {
            $this->assertStringNotContainsString($digits, $unfolded);
        }
    }
}
