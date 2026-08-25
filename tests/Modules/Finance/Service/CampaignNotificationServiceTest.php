<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\CampaignNotificationService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignNotificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private NotificationRepository $notificationRepository;
    private NotificationService $notifications;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];
    /** @var array<string, int> */
    private array $accountIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);

        $accounts = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accounts->create(
            'Compte Unité',
            Account::TYPE_BANK,
            null,
            'BE71096123456769',
            'Unité SV025',
            'intendant'
        );

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        $this->memberIds['Lucie'] = $this->createMember('Lucie', 'famille@test.be');
        $this->memberIds['Antoine'] = $this->createMember('Antoine', 'famille@test.be');
        $this->memberIds['Timeo'] = $this->createMember('Timéo', 'roskam@test.be');
        $this->memberIds['Orphelin'] = $this->createMember('Orphelin', 'sans-compte@test.be');

        $userAccounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->accountIds['famille'] = $userAccounts->create('famille@test.be')->id;
        $this->accountIds['roskam'] = $userAccounts->create('roskam@test.be')->id;

        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->notificationRepository = new NotificationRepository($this->pdo, $this->encryption);
        $this->notifications = new NotificationService(
            $this->notificationRepository,
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createMock(WebPush::class),
            $settings,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $userAccounts
        );
        $this->notifications->registerModuleTypes('finance', [
            [
                'id' => CampaignNotificationService::TYPE_PAYMENT_DUE,
                'label' => 'Paiement à effectuer',
                'description' => 'd',
                'group' => 'Finances',
                'role_min' => 'public',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
        ]);
    }

    /**
     * A parent of two would otherwise get two notifications in a row,
     * which is how a family learns to swipe this kind of message away
     * without reading it.
     */
    public function testAParentOfTwoGetsOneNotificationForBoth(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Antoine', 3825], ['Timeo', 1000]]);

        $notified = $this->service()->notifyFamilies($campaign, null);

        $this->assertSame(2, $notified, 'two addresses reached, not three demands');
        $rows = $this->notificationRepository->findByUserAccountId($this->accountIds['famille']);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('83,25 €', $rows[0]->title);
        $this->assertStringContainsString('Lucie', $rows[0]->body);
        $this->assertStringContainsString('Antoine', $rows[0]->body);
    }

    /**
     * The amount is in the title on purpose — a notification saying
     * "quelque chose vous attend" makes people open the site to find out
     * — and Core\Notification's discretion mode is what keeps that safe.
     */
    public function testTheTitleCarriesTheAmountSoItSaysWhatItIsAbout(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);

        $this->service()->notifyFamilies($campaign, null);

        $rows = $this->notificationRepository->findByUserAccountId($this->accountIds['roskam']);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('38,25 €', $rows[0]->title);
        $this->assertStringContainsString('Cotisations 2025-2026', $rows[0]->title);
    }

    /** Telling somebody who has paid that they owe money is worse than telling them nothing. */
    public function testAFamilyThatHasPaidIsNotTold(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $this->pay($this->memberIds['Timeo'], 38.25);

        $this->assertSame(0, $this->service()->notifyFamilies($campaign, null));
        $this->assertSame([], $this->notificationRepository->findByUserAccountId($this->accountIds['roskam']));
    }

    public function testAWaivedDemandIsNotWorthANotificationEither(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $receivable = $this->receivables->findByMemberIds([$this->memberIds['Timeo']])[0];
        $this->receivables->setWaived($receivable->id, date('Y-m-d H:i:s'), 7);

        $this->assertSame(0, $this->service()->notifyFamilies($campaign, null));
    }

    /** A partly-paid demand asks for the balance, never the original amount. */
    public function testAPartlyPaidDemandIsAnnouncedAtItsRemainingAmount(): void
    {
        $campaign = $this->campaignWith([['Timeo', 4500]]);
        $this->pay($this->memberIds['Timeo'], 20.00);

        $this->service()->notifyFamilies($campaign, null);

        $rows = $this->notificationRepository->findByUserAccountId($this->accountIds['roskam']);
        $this->assertStringContainsString('25,00 €', $rows[0]->title);
        $this->assertStringNotContainsString('45,00 €', $rows[0]->title);
    }

    /**
     * A member with no account at all is the common case for a young
     * member, not an error: nothing to notify, nothing to crash on.
     */
    public function testAMemberWithNoAccountCostsNobodyANotification(): void
    {
        $campaign = $this->campaignWith([['Orphelin', 4500]]);

        $this->assertSame(0, $this->service()->notifyFamilies($campaign, null));
    }

    /**
     * A teenager who added their own confirmed address hears about it
     * TOO, not instead of the parent whose address Desk holds.
     */
    public function testASecondaryAddressIsNotifiedAlongsideTheDeskOne(): void
    {
        $userAccounts = new UserAccountRepository($this->pdo, $this->encryption);
        $ownAccountId = $userAccounts->create('timeo.perso@test.be')->id;
        $this->addConfirmedSecondaryEmail($this->memberIds['Timeo'], 'timeo.perso@test.be');

        $campaign = $this->campaignWith([['Timeo', 3825]]);

        $this->assertSame(2, $this->service()->notifyFamilies($campaign, null));
        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($this->accountIds['roskam']));
        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($ownAccountId));
    }

    /**
     * An ado whose lock screen is read over their shoulder in the school
     * corridor must not broadcast "45 € impayés". Discretion mode is
     * core's, and this test pins that finance actually benefits from it:
     * the amount lives in the title, which is exactly what discretion
     * replaces — and it stays intact in the notification centre.
     */
    public function testDiscretionModeKeepsTheAmountOffTheLockScreen(): void
    {
        $userAccounts = new UserAccountRepository($this->pdo, $this->encryption);
        $userAccounts->updateNotificationSettings($this->accountIds['roskam'], null, null, true);
        $this->notifications->subscribe($this->accountIds['roskam'], 'https://push.test/endpoint', 'auth-key', 'p256dh-key');

        $pushed = [];
        $webPush = $this->createMock(WebPush::class);
        $webPush->method('queueNotification')->willReturnCallback(
            static function (mixed $subscription, ?string $payload) use (&$pushed): void {
                $pushed[] = (string) $payload;
            }
        );
        $webPush->method('flush')->willReturnCallback(static function (): \Generator {
            yield from [];
        });
        $notifications = $this->notificationServiceWith($webPush);

        $campaign = $this->campaignWith([['Timeo', 4500]]);
        $this->service(true, $notifications)->notifyFamilies($campaign, null);

        $rows = $this->notificationRepository->findByUserAccountId($this->accountIds['roskam']);
        $this->assertCount(1, $rows);
        $notifications->sendPushForNotifications([$rows[0]->id], static fn(): bool => true);

        $this->assertCount(1, $pushed);
        $this->assertStringNotContainsString('45,00', $pushed[0], 'the amount must never reach the pushed payload');
        $this->assertStringContainsString('Nouvelle notification', $pushed[0]);
        $this->assertStringContainsString('45,00 €', $rows[0]->title, 'but the notification centre still says what it is about');
    }

    /** With the notification centre absent the gesture still succeeds, quietly. */
    public function testWithoutTheNotificationServiceNothingIsSentAndNothingBreaks(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);

        $this->assertSame(0, $this->service(false)->notifyFamilies($campaign, null));
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function service(bool $withNotifications = true, ?NotificationService $notifications = null): CampaignNotificationService
    {
        return new CampaignNotificationService(
            $this->rows,
            $this->receivables,
            FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables),
            new MemberAccountResolver(
                new MemberYearRepository($this->pdo),
                new MemberEmailRepository($this->pdo, $this->encryption),
                new UserAccountRepository($this->pdo, $this->encryption),
                $this->encryption
            ),
            new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo)),
            new MemberYearRepository($this->pdo),
            $withNotifications ? ($notifications ?? $this->notifications) : null
        );
    }

    private function notificationServiceWith(WebPush $webPush): NotificationService
    {
        $service = new NotificationService(
            $this->notificationRepository,
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            $webPush,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption)
        );
        $service->registerModuleTypes('finance', [
            [
                'id' => CampaignNotificationService::TYPE_PAYMENT_DUE,
                'label' => 'Paiement à effectuer',
                'description' => 'd',
                'group' => 'Finances',
                'role_min' => 'public',
                'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            ],
        ]);

        return $service;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $lines first name, amount in cents
     */
    private function campaignWith(array $lines): Campaign
    {
        $campaignId = $this->campaigns->create(
            'Cotisations 2025-2026',
            $this->scoutYearId,
            $this->accountId,
            null,
            'cotisations.xlsx',
            [],
            7
        );

        $sequence = 0;
        foreach ($lines as [$firstName, $amountCents]) {
            $sequence++;
            $rowId = $this->rows->create($campaignId, $this->memberIds[$firstName], $amountCents, $sequence, []);
            $this->receivables->create(
                CampaignService::SOURCE_MODULE,
                $rowId,
                $this->accountId,
                $amountCents,
                StructuredCommunicationService::format(str_pad((string) (1000000000 + $sequence), 10, '0', STR_PAD_LEFT)),
                null,
                $this->memberIds[$firstName]
            );
        }

        $campaign = $this->campaigns->findById($campaignId);
        self::assertNotNull($campaign);

        return $campaign;
    }

    private function pay(int $memberId, float $amount): void
    {
        $receivable = $this->receivables->findByMemberIds([$memberId])[0];
        $this->transactions->create(
            $this->accountId,
            $this->scoutYearId,
            'REF-' . $receivable->id,
            '2026-02-18',
            'Virement ' . $receivable->communication,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    private function addConfirmedSecondaryEmail(int $memberId, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, status, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
            'valid',
            date('Y-m-d H:i:s'),
        ]);
    }

    private function createMember(string $firstName, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['D-' . $firstName]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Vandenbrande', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);

        return $memberId;
    }
}
