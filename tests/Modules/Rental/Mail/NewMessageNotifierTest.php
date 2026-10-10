<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Mail\NewMessageNotifier;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\ManagerRecipientResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * « Nouveau message du locataire » (#720): who is told, and what they are
 * told — the booking, never the sender or the subject.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NewMessageNotifierTest extends TestCase
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

    public function testTheAssetsManagersAreToldWhichBookingAndWhereToReadIt(): void
    {
        $assetId = $this->asset('Local Saint-Georges', 'local-saint-georges');
        $accountId = $this->manager($assetId, 'gestionnaire@unite.be');
        $booking = $this->booking($assetId, 'LOC-K7Q2MX');

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            'rental.new_message',
            $this->callback(static fn(array $recipients): bool
                => array_column($recipients, 'userAccountId') === [$accountId]),
            $this->callback(static fn(array $payload): bool
                => $payload['title'] === 'Nouveau message du locataire — Local Saint-Georges'
                && str_contains($payload['body'], 'LOC-K7Q2MX')
                && $payload['url'] === '/mes-locations/local-saint-georges/reservations/' . $booking->id . '/courrier')
        );

        $this->notifier($notifications)->messageFiled($booking);
    }

    public function testNeitherTheSenderNorTheSubjectTravels(): void
    {
        $assetId = $this->asset('Local Saint-Georges', 'local-saint-georges');
        $this->manager($assetId, 'gestionnaire@unite.be');
        $booking = $this->booking($assetId, 'LOC-K7Q2MX');

        $payloads = [];
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(
            static function (string $type, array $recipients, array $payload) use (&$payloads): void {
                $payloads[] = $payload;
            }
        );

        $this->notifier($notifications)->messageFiled($booking);

        $this->assertCount(1, $payloads);
        foreach (['Jeanne', 'Martin', 'jeanne@example.be'] as $secret) {
            $this->assertStringNotContainsString($secret, implode(' ', $payloads[0]));
        }
    }

    public function testAnAssetNobodyCanBeToldAboutTellsNobody(): void
    {
        $assetId = $this->asset('Local Saint-Georges', 'local-saint-georges');
        $this->manager($assetId, 'sans-compte@unite.be', withAccount: false);
        $booking = $this->booking($assetId, 'LOC-K7Q2MX');

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->never())->method('dispatch');

        $this->notifier($notifications)->messageFiled($booking);
    }

    public function testTheTypeIsDeclaredInTheManifest(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );

        $this->assertContains(NewMessageNotifier::TYPE, array_column($manifest['notifications'], 'id'));
        $this->assertNotContains('rental.mail_proposition', array_column($manifest['notifications'], 'id'));
    }

    public function testAFailureIsJournaledWithoutItsTextAndNeverThrown(): void
    {
        $assetId = $this->asset('Local Saint-Georges', 'local-saint-georges');
        $this->manager($assetId, 'gestionnaire@unite.be');
        $booking = $this->booking($assetId, 'LOC-K7Q2MX');

        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willThrowException(
            new \RuntimeException('SMTP refused jeanne@example.be')
        );

        $this->notifier($notifications)->messageFiled($booking);

        $row = $this->pdo->query(
            "SELECT level, description, context FROM event_log WHERE event_type = 'rental_new_message_not_sent'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'the failure must leave a trace an operator can find');
        $this->assertSame('error', $row['level']);
        $this->assertStringContainsString('LOC-K7Q2MX', (string) $row['description']);
        $this->assertStringContainsString('RuntimeException', (string) $row['description']);
        $this->assertStringNotContainsString('jeanne@example.be', (string) $row['description'] . $row['context']);
    }

    private function notifier(NotificationService $notifications): NewMessageNotifier
    {
        return new NewMessageNotifier(
            $notifications,
            new ManagerRecipientResolver(
                $this->managers,
                new MemberYearRepository($this->pdo),
                $this->accounts,
                new JournalService(new JournalRepository($this->pdo))
            ),
            $this->assets,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function asset(string $name, string $slug): int
    {
        return $this->assets->create($name, $name, $slug, 60, 1, '18:00', '11:00', null, true);
    }

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
        $booking = $this->bookings->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }
}
