<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Reminder;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Reminder\ReminderPlanner;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalComplianceRepository;
use Modules\Rental\Repository\RentalReminderRepository;
use Modules\Rental\Service\RentalComplianceService;
use Modules\Rental\Service\RentalReminderService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Sending the reminders, down the right channel, once (§6.29).
 *
 * Two rules carry this file:
 *
 * - **Two channels that must never be confused.** `NotificationService`
 *   targets `user_accounts`; a renter has none, so their one reminder is an
 *   email. Dispatching it as a notification would not fail — it would
 *   silently reach nobody.
 * - **Once.** The task runs daily and asks the same questions every
 *   morning. A unit that gets the same notification thirty times learns to
 *   ignore the channel, which is worse than not reminding at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalReminderServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalComplianceService $complianceService;
    private RentalReminderRepository $reminderRepository;
    private int $assetId;
    private int $scoutYearId;

    /** @var array<int, array{typeId: string, recipients: array<int, array{userAccountId: int, memberId: ?int}>, payload: array<string, mixed>}> */
    private array $dispatched = [];

    /** @var array<int, string> booking references whose renter was emailed */
    private array $emailed = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->reminderRepository = new RentalReminderRepository($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'rental',
            RentalComplianceService::SETTING_LABEL_SUGGESTIONS,
            'Attestation incendie',
            'text',
            'Intitulés',
            'Suggestions.',
        ]);

        $this->complianceService = new RentalComplianceService(
            new RentalComplianceRepository($this->pdo),
            $settingService,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->scoutYearId = (new \Core\Config\ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $this->assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            '18:00',
            '11:00',
            null,
            true,
            false
        );
    }

    // ── Test doubles ────────────────────────────────────────────────────

    private function notificationService(): \Core\Notification\NotificationService
    {
        $service = $this->createMock(\Core\Notification\NotificationService::class);
        $service->method('dispatch')->willReturnCallback(
            function (string $typeId, array $recipients, array $payload) : void {
                $this->dispatched[] = ['typeId' => $typeId, 'recipients' => $recipients, 'payload' => $payload];
            }
        );

        return $service;
    }

    private function mailService(bool $succeeds = true): \Modules\Rental\Service\RentalBookingMailService
    {
        $service = $this->createMock(\Modules\Rental\Service\RentalBookingMailService::class);
        $service->method('sendPracticalInfo')->willReturnCallback(
            function (RentalBooking $booking) use ($succeeds): bool {
                if ($succeeds) {
                    $this->emailed[] = $booking->reference;
                }

                return $succeeds;
            }
        );

        return $service;
    }

    private function service(
        ?\Core\Notification\NotificationService $notifications = null,
        ?\Modules\Rental\Service\RentalBookingMailService $mail = null
    ): RentalReminderService {
        return new RentalReminderService(
            $this->bookingRepository,
            $this->assetRepository,
            $this->managerRepository,
            $this->complianceService,
            $this->reminderRepository,
            new ReminderPlanner(),
            new MemberYearRepository($this->pdo),
            new UserAccountRepository($this->pdo, $this->encryption),
            new JournalService(new JournalRepository($this->pdo)),
            $notifications,
            null,
            null,
            null,
            $mail
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function addManagerWithAccount(string $email): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($this->assetId, $memberId, false);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $this->encryption->encrypt($email, 'user_accounts.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function addManagerWithoutAccount(string $email): void
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($this->assetId, $memberId, false);
    }

    private function createBooking(
        string $reference = 'LOC-2027-0042',
        string $arrival = '2027-07-01',
        string $departure = '2027-07-04',
        BookingStatus $status = BookingStatus::CONFIRMED,
        string $receivedAt = '2027-01-01 10:00:00'
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $this->assetId,
            $reference,
            $arrival,
            $departure,
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable($receivedAt)
        );

        if ($status !== BookingStatus::RECEIVED) {
            $this->bookingRepository->setStatus($created['id'], $status, new \DateTimeImmutable($receivedAt));
        }

        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    /**
     * @return string[]
     */
    private function dispatchedTypeIds(): array
    {
        return array_map(static fn(array $entry) => $entry['typeId'], $this->dispatched);
    }

    // ── The two channels (§6.29) ────────────────────────────────────────

    public function testAnInternalReminderGoesToTheManagersOfThatAsset(): void
    {
        $accountId = $this->addManagerWithAccount('chef@unite.be');
        $this->createBooking(arrival: '2027-07-10', departure: '2027-07-13');

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-01'));

        $contractMissing = array_values(array_filter(
            $this->dispatched,
            static fn(array $e) => $e['typeId'] === ReminderKind::CONTRACT_MISSING->notificationTypeId()
        ));

        $this->assertCount(1, $contractMissing);
        $this->assertSame([['userAccountId' => $accountId, 'memberId' => null]], array_map(
            static fn(array $r) => ['userAccountId' => $r['userAccountId'], 'memberId' => null],
            $contractMissing[0]['recipients']
        ));
    }

    public function testWithoutTheStayFeaturesNoInventoryIsEverChased(): void
    {
        // There is no inventory to be missing, so chasing one would be
        // chasing a manager for something the site does not offer them.
        $this->addManagerWithAccount('chef@unite.be');
        $this->createBooking(arrival: '2027-06-30', departure: '2027-07-04');

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-02'));

        $this->assertNotContains(
            ReminderKind::ARRIVAL_INVENTORY->notificationTypeId(),
            $this->dispatchedTypeIds()
        );
    }

    public function testTheRentersReminderGoesByEmailAndNeverAsANotification(): void
    {
        // The distinction §6.29 is emphatic about: a renter has no
        // user_account, so a notification would silently reach nobody.
        $this->addManagerWithAccount('chef@unite.be');
        $booking = $this->createBooking(arrival: '2027-07-05', departure: '2027-07-08');

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-01'));

        $this->assertSame([$booking->reference], $this->emailed);
        $this->assertNotContains(
            ReminderKind::PRACTICAL_INFO->notificationTypeId(),
            $this->dispatchedTypeIds()
        );
    }

    public function testEveryInternalReminderTypeIsDeclaredInTheManifest(): void
    {
        // NotificationService throws on an undeclared type — a runtime
        // failure for a code-level mistake, and one that would only show up
        // the day a particular reminder first came due.
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        $declared = array_column($manifest['notifications'] ?? [], 'id');

        foreach (RentalReminderService::declaredNotificationTypeIds() as $typeId) {
            $this->assertContains($typeId, $declared, $typeId . ' is dispatched but never declared.');
        }
    }

    public function testAManagerWithNoAccountIsSkippedRatherThanGuessedAt(): void
    {
        $this->addManagerWithoutAccount('jamais.connecte@unite.be');
        $this->createBooking(arrival: '2027-07-10', departure: '2027-07-13');

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-01'));

        $this->assertSame([], $this->dispatched);
    }

    public function testWithoutANotificationServiceNothingInternalIsClaimedAsSent(): void
    {
        // Nothing went out, so nothing has been said: the claim is released
        // rather than leaving the reminder permanently suppressed by a
        // failure nobody saw.
        $this->addManagerWithAccount('chef@unite.be');
        $booking = $this->createBooking(arrival: '2027-07-10', departure: '2027-07-13');

        $this->service(null, $this->mailService())->run(new \DateTimeImmutable('2027-07-01'));

        $this->assertFalse($this->reminderRepository->hasBeenSent(
            'booking',
            $booking->id,
            ReminderKind::CONTRACT_MISSING
        ));
    }

    public function testAFailedRenterEmailIsNotRecordedAsSentEither(): void
    {
        $this->addManagerWithAccount('chef@unite.be');
        $booking = $this->createBooking(arrival: '2027-07-05', departure: '2027-07-08');

        $this->service($this->notificationService(), $this->mailService(succeeds: false))
            ->run(new \DateTimeImmutable('2027-07-01'));

        $this->assertFalse($this->reminderRepository->hasBeenSent(
            'booking',
            $booking->id,
            ReminderKind::PRACTICAL_INFO
        ));
    }

    // ── Once, and only once ─────────────────────────────────────────────

    public function testTheSameReminderIsNotSentTwice(): void
    {
        $this->addManagerWithAccount('chef@unite.be');
        $this->createBooking(arrival: '2027-07-10', departure: '2027-07-13');

        $service = $this->service($this->notificationService(), $this->mailService());
        $service->run(new \DateTimeImmutable('2027-07-01'));
        $firstRun = count($this->dispatched);

        $service->run(new \DateTimeImmutable('2027-07-02'));

        $this->assertGreaterThan(0, $firstRun);
        $this->assertCount($firstRun, $this->dispatched, 'A second day must not repeat what was already said.');
    }

    public function testTwoOverlappingRunsStillSendOnce(): void
    {
        // Shared hosting makes an overlap entirely possible when one run is
        // slow. The claim is a compare-and-set, so the loser does nothing.
        $this->addManagerWithAccount('chef@unite.be');
        $booking = $this->createBooking();

        $today = new \DateTimeImmutable('2027-07-02');

        $this->assertTrue($this->reminderRepository->claim('booking', $booking->id, ReminderKind::ARRIVAL_INVENTORY, $today));
        $this->assertFalse($this->reminderRepository->claim('booking', $booking->id, ReminderKind::ARRIVAL_INVENTORY, $today));
    }

    public function testForgettingAReminderLetsItFireAgain(): void
    {
        // The case this exists for: a manager pushes a due date back a
        // month, and the warning they already got is about a deadline that
        // no longer exists.
        $booking = $this->createBooking();
        $today = new \DateTimeImmutable('2027-07-02');

        $this->reminderRepository->claim('booking', $booking->id, ReminderKind::DEPOSIT_MISSING, $today);
        $this->reminderRepository->forget('booking', $booking->id, ReminderKind::DEPOSIT_MISSING);

        $this->assertTrue($this->reminderRepository->claim('booking', $booking->id, ReminderKind::DEPOSIT_MISSING, $today));
    }

    public function testDifferentRemindersAboutOneBookingDoNotSuppressEachOther(): void
    {
        $booking = $this->createBooking();
        $today = new \DateTimeImmutable('2027-07-02');

        $this->assertTrue($this->reminderRepository->claim('booking', $booking->id, ReminderKind::DEPOSIT_MISSING, $today));
        $this->assertTrue($this->reminderRepository->claim('booking', $booking->id, ReminderKind::CONTRACT_MISSING, $today));
    }

    // ── The compliance register (§6.33) ─────────────────────────────────

    public function testAnExpiringRegisterEntryReachesTheAssetsManagers(): void
    {
        $accountId = $this->addManagerWithAccount('chef@unite.be');
        $this->complianceService->add($this->assetId, 'Attestation incendie', '2027-07-15', null);

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-01'));

        $compliance = array_values(array_filter(
            $this->dispatched,
            static fn(array $e) => $e['typeId'] === ReminderKind::COMPLIANCE_EXPIRING->notificationTypeId()
        ));

        $this->assertCount(1, $compliance);
        $this->assertSame($accountId, $compliance[0]['recipients'][0]['userAccountId']);
        $this->assertStringContainsString('Attestation incendie', (string) $compliance[0]['payload']['body']);
    }

    public function testAWarnedRegisterEntryIsStampedSoTomorrowStaysQuiet(): void
    {
        $this->addManagerWithAccount('chef@unite.be');
        $id = $this->complianceService->add($this->assetId, 'Attestation incendie', '2027-07-15', null);

        $service = $this->service($this->notificationService(), $this->mailService());
        $service->run(new \DateTimeImmutable('2027-07-01'));
        $service->run(new \DateTimeImmutable('2027-07-02'));

        $compliance = array_filter(
            $this->dispatched,
            static fn(array $e) => $e['typeId'] === ReminderKind::COMPLIANCE_EXPIRING->notificationTypeId()
        );

        $this->assertCount(1, $compliance);
        $this->assertSame(
            '2027-07-01',
            (new RentalComplianceRepository($this->pdo))->findById($id)?->remindedOn
        );
    }

    // ── Nothing personal reaches a notification (§6.29) ──────────────────

    public function testNoDispatchedPayloadEverNamesTheRenter(): void
    {
        $this->addManagerWithAccount('chef@unite.be');
        $this->createBooking(arrival: '2027-06-30', departure: '2027-07-04');

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-20'));

        $this->assertNotSame([], $this->dispatched);

        foreach ($this->dispatched as $entry) {
            $text = json_encode($entry['payload'], JSON_UNESCAPED_UNICODE);
            $this->assertIsString($text);
            $this->assertStringNotContainsString('Jeanne', $text);
            $this->assertStringNotContainsString('jeanne@example.be', $text);
        }
    }

    public function testTheJournalRecordsTheReminderWithoutAnyPersonalData(): void
    {
        $this->addManagerWithAccount('chef@unite.be');
        $this->createBooking(arrival: '2027-07-10', departure: '2027-07-13');

        $this->service($this->notificationService(), $this->mailService())
            ->run(new \DateTimeImmutable('2027-07-01'));

        $rows = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'rental_reminder_sent'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $text = (string) $row['description'] . (string) $row['context'];
            $this->assertStringNotContainsString('Jeanne', $text);
            $this->assertStringNotContainsString('jeanne@example.be', $text);
        }
    }
}
