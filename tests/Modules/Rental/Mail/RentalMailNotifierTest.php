<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use Core\Import\MemberYearRepository;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Mail\RentalMailNotifier;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Who is told of a proposition, and what they are told (§6.29).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalMailNotifierTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalAssetRepository $assets;
    private RentalAssetManagerRepository $managers;
    private RentalBookingRepository $bookings;
    private UserAccountRepository $accounts;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->assets = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managers = new RentalAssetManagerRepository($this->pdo);
        $this->bookings = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->scoutYearId = (new \Core\Config\ScoutYearService($this->pdo))->getCurrentYear()['id'];
    }

    private function asset(string $name, string $slug): int
    {
        return $this->assets->create($name, $name, $slug, 60, 1, '18:00', '11:00', null, true);
    }

    /** A manager of $assetId reachable at $email, with or without an account here. */
    private function manager(int $assetId, string $email, bool $withAccount = true): ?int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email . $assetId), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managers->grant($assetId, $memberId, false);

        return $withAccount ? $this->accounts->create($email)->id : null;
    }

    private function booking(int $assetId, string $reference): RentalBooking
    {
        $created = $this->bookings->create(
            $assetId, $reference, '2027-07-01', '2027-07-04', 1, 20, null,
            ['name' => 'Jeanne Martin', 'email' => 'jeanne@example.be', 'phone' => null, 'organisation' => null, 'purpose' => null, 'comment' => null],
            null, null, null, 'v1', str_repeat('0', 64), 'v1', str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $this->bookings->setStatus($created['id'], BookingStatus::CONFIRMED, new \DateTimeImmutable('2027-01-01 10:00:00'));
        $booking = $this->bookings->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function notifier(NotificationService $notifications): RentalMailNotifier
    {
        return new RentalMailNotifier(
            $notifications,
            $this->managers,
            new MemberYearRepository($this->pdo),
            $this->accounts,
            $this->assets
        );
    }

    public function testTheAssetsManagersWithAnAccountAreToldAndNobodyElse(): void
    {
        $assetId = $this->asset('Local Saint-Georges', 'local-saint-georges');
        $told = $this->manager($assetId, 'gestion@unite.be');
        $this->manager($assetId, 'sans-compte@unite.be', withAccount: false);
        $this->manager($this->asset('Autre', 'autre'), 'autre@unite.be');
        $booking = $this->booking($assetId, 'LOC-2027-0042');

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            RentalMailNotifier::TYPE_PROPOSITION,
            $this->callback(static fn(array $recipients): bool
                => array_column($recipients, 'userAccountId') === [$told]),
            $this->callback(static fn(array $payload): bool
                => str_contains($payload['body'], 'Local Saint-Georges, du 01/07/2027')
                    && !str_contains($payload['body'], 'jeanne')
                    && $payload['url'] === '/mes-locations/local-saint-georges/reservations/42')
        );

        $this->notifier($notifications)->proposed(
            [$booking],
            ['LOC-2027-0042' => 'LOC-2027-0042 — Local Saint-Georges, du 01/07/2027 au 04/07/2027'],
            ['LOC-2027-0042' => '/mes-locations/local-saint-georges/reservations/42']
        );
    }

    public function testEachAssetsManagersHearOnlyOfTheirOwnBookings(): void
    {
        $first = $this->asset('Local Saint-Georges', 'local-saint-georges');
        $second = $this->asset('Prairie', 'prairie');
        $this->manager($first, 'gestion@unite.be');
        $this->manager($second, 'prairie@unite.be');
        $bookingA = $this->booking($first, 'LOC-2027-0042');
        $bookingB = $this->booking($second, 'LOC-2027-0043');

        $bodies = [];
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function (string $type, array $recipients, array $payload) use (&$bodies): void {
                $bodies[] = $payload['body'];
            }
        );

        $this->notifier($notifications)->proposed(
            [$bookingA, $bookingB],
            ['LOC-2027-0042' => 'A', 'LOC-2027-0043' => 'B'],
            []
        );

        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('réservation A.', $bodies[0]);
        $this->assertStringNotContainsString('B', $bodies[0]);
        $this->assertStringContainsString('réservation B.', $bodies[1]);
    }

    public function testAnAssetWithNoManagerOnTheSiteTellsNobody(): void
    {
        $assetId = $this->asset('Local', 'local');
        $this->manager($assetId, 'sans-compte@unite.be', withAccount: false);
        $booking = $this->booking($assetId, 'LOC-2027-0042');

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->never())->method('dispatch');

        $this->notifier($notifications)->proposed([$booking], [], []);
    }
}
