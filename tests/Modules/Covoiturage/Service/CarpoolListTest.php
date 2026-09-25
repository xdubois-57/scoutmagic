<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\Role;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\CarpoolFormat;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * The members' list: to come by default, the past folded and bounded by
 * the retention (D9), free seats counted in people.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CarpoolListTest extends TestCase
{
    private \PDO $pdo;
    private CarpoolBoard $board;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->board = new CarpoolBoard(
            new CarpoolRepository($this->pdo),
            new OfferRepository($this->pdo, H::encryption()),
            new SeatRequestRepository($this->pdo, H::encryption()),
            new SettingService(new SettingRepository($this->pdo))
        );
    }

    public function testUpcomingFirstThePastFoldedAndNothingOlderThanTheRetention(): void
    {
        $soon = H::carpool($this->pdo, 3);
        $later = H::carpool($this->pdo, 9);
        $past = H::carpool($this->pdo, -5);
        H::carpool($this->pdo, -45);

        $list = $this->board->memberList(H::viewer(1));

        $this->assertSame([$soon, $later], array_column($list['upcoming'], 'id'));
        $this->assertSame([$past], array_column($list['past'], 'id'));
        $this->assertSame(30, $list['retention_days']);
    }

    public function testFreeSeatsAreCountedInPeople(): void
    {
        $id = H::carpool($this->pdo, 3);
        $offer = H::offer($this->pdo, $id, 1, 4);
        H::request($this->pdo, $offer, 2, ['Tom', 'Léa'], SeatRequest::ACCEPTED);
        H::request($this->pdo, $offer, 3, ['Kim']);
        $full = H::offer($this->pdo, $id, 4, 1);
        H::request($this->pdo, $full, 5, ['Zoé'], SeatRequest::ACCEPTED);

        $row = $this->board->memberList(H::viewer(1))['upcoming'][0];

        $this->assertSame(2, $row['cars']);
        // 4 − 2 people accepted; the pending request holds nothing.
        $this->assertSame(2, $row['free']);
        $this->assertSame('2 places libres', $row['free_text']);
    }

    public function testThePastShowsRidersOnlyToThoseWhoMaySeeThem(): void
    {
        $id = H::carpool($this->pdo, -3);
        $offer = H::offer($this->pdo, $id, 1);
        H::request($this->pdo, $offer, 2, ['Tom Leroy'], SeatRequest::ACCEPTED);

        $this->assertNull($this->board->memberList(H::viewer(9))['past'][0]['offers'][0]['riders']);
        $this->assertSame(['Tom Leroy'], $this->board->memberList(H::viewer(1))['past'][0]['offers'][0]['riders']);
        $this->assertSame(['Tom Leroy'], $this->board->memberList(H::viewer(7, Role::ADMIN))['past'][0]['offers'][0]['riders']);
    }

    public function testTheWayAFamilySaysADayAndAnHour(): void
    {
        $today = new \DateTimeImmutable('2026-09-24');
        $this->assertSame('samedi 7 novembre', CarpoolFormat::day('2026-11-07', $today));
        $this->assertSame('samedi 6 novembre 2027', CarpoolFormat::day('2027-11-06', $today));
        $this->assertSame('8 h 30', CarpoolFormat::time('08:30'));
        $this->assertSame('complet', CarpoolFormat::freeSeats(0));
        $this->assertSame('1 place libre', CarpoolFormat::freeSeats(1));
    }
}
