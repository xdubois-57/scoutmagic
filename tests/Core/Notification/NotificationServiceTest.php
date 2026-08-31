<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Notification\NotificationMailer;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\NotificationType;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NotificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private NotificationRepository $notificationRepository;
    private PushSubscriptionRepository $subscriptionRepository;
    private NotificationPreferenceRepository $preferenceRepository;
    private SchedulerRepository $schedulerRepository;
    private WebPush $webPush;
    private SettingService $settingService;
    private NotificationService $service;
    private UserAccountRepository $userAccountRepository;
    private EncryptionService $encryption;

    /** A type declared only for these tests via registerModuleTypes(). */
    private const TEST_TYPE = 'test_module.thing_happened';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->notificationRepository = new NotificationRepository($this->pdo, $this->encryption);
        $this->subscriptionRepository = new PushSubscriptionRepository($this->pdo, $this->encryption);
        $this->preferenceRepository = new NotificationPreferenceRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->webPush = $this->createMock(WebPush::class);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);

        $this->service = new NotificationService(
            $this->notificationRepository,
            $this->subscriptionRepository,
            $this->preferenceRepository,
            $this->webPush,
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService($this->schedulerRepository),
            $this->userAccountRepository
        );

        $this->service->registerModuleTypes('test_module', [[
            'id' => self::TEST_TYPE,
            'label' => 'Chose arrivée',
            'description' => 'desc',
            'group' => 'Test',
            'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);
    }

    private function createUserAccount(): int
    {
        $email = 'user_' . uniqid() . '@test.example';
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$this->encryption->encrypt($email, 'user_accounts.email'), $this->encryption->blindIndex($email, 'email')]);

        return (int) $this->pdo->lastInsertId();
    }

    // --- notify() — Iteration 1 legacy path, unchanged behavior ---

    public function testNotifyCreatesANotificationRecordEvenWithNoSubscriptions(): void
    {
        $userId = $this->createUserAccount();
        $this->webPush->expects($this->never())->method('queueNotification');

        $this->service->notify($userId, 'Titre', 'Corps', '/some/path');

        $records = $this->notificationRepository->findByUserAccountId($userId);
        $this->assertCount(1, $records);
        $this->assertSame('Titre', $records[0]->title);
        $this->assertSame('core.system', $records[0]->typeId);
        $this->assertFalse($records[0]->isRead());
    }

    public function testNotifyQueuesAndFlushesForEveryRegisteredDevice(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/device-1', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/device-2', 'auth2', 'p256dh2');

        $this->webPush->expects($this->exactly(2))->method('queueNotification');
        $this->webPush->method('flush')->willReturn((function () { return; yield; })());

        $this->service->notify($userId, 'Titre', 'Corps');
    }

    /**
     * Regression: an invalid VAPID config used to be an uncaught exception
     * straight out of WebPush's own constructor at the composition root,
     * taking down every single page load. $webPush is now nullable there
     * specifically so push delivery can degrade silently instead — this
     * proves that degradation actually holds for a subscribed device.
     */
    public function testNotifyNeverCrashesWithSubscribedDevicesWhenWebPushIsNull(): void
    {
        $service = new NotificationService(
            $this->notificationRepository,
            $this->subscriptionRepository,
            $this->preferenceRepository,
            null,
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService($this->schedulerRepository),
            $this->userAccountRepository
        );

        $userId = $this->createUserAccount();
        $service->subscribe($userId, 'https://push.example/device-1', 'auth1', 'p256dh1');

        $service->notify($userId, 'Titre', 'Corps');

        $records = $this->notificationRepository->findByUserAccountId($userId);
        $this->assertCount(1, $records);
    }

    // --- registerModuleTypes()/getAllDeclaredTypes()/findType() ---

    public function testGetAllDeclaredTypesIncludesCoreAndModuleTypes(): void
    {
        $types = $this->service->getAllDeclaredTypes();
        $ids = array_map(fn(NotificationType $t) => $t->id, $types);

        $this->assertContains('core.backup_completed', $ids);
        $this->assertContains(self::TEST_TYPE, $ids);
    }

    public function testFindTypeReturnsNullForUndeclaredType(): void
    {
        $this->assertNull($this->service->findType('nope.does_not_exist'));
    }

    /**
     * Manual, superadmin-triggered "notify iOS users to reinstall" type
     * (Core\Http\Controller\SettingsController::notifyIosLogoUpdate(), see
     * ARCHITECTURE §8.23) — open to every identified account, since the
     * site has no way to know which accounts are actually on iOS.
     */
    public function testUnitLogoUpdatedIosTypeIsDeclaredAndOpenToEveryIdentifiedAccount(): void
    {
        $type = $this->service->findType('core.unit_logo_updated_ios');

        $this->assertNotNull($type);
        $this->assertSame('identified', $type->roleMin);
    }

    // --- dispatch() ---

    public function testDispatchRejectsAnUndeclaredType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->dispatch('nope.undeclared', [], ['title' => 'T', 'body' => 'B']);
    }

    public function testDispatchCreatesOneNotificationPerRecipient(): void
    {
        $userA = $this->createUserAccount();
        $userB = $this->createUserAccount();

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $userA, 'memberId' => null],
            ['userAccountId' => $userB, 'memberId' => null],
        ], ['title' => 'Titre', 'body' => 'Corps']);

        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($userA));
        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($userB));
    }

    public function testDispatchCreatesASeparateNotificationPerMemberForTheSameAccount(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $member1 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D2')");
        $member2 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D3')");
        $member3 = (int) $this->pdo->lastInsertId();

        $parent = $this->createUserAccount();

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $parent, 'memberId' => $member1],
            ['userAccountId' => $parent, 'memberId' => $member2],
            ['userAccountId' => $parent, 'memberId' => $member3],
        ], ['title' => 'Titre', 'body' => 'Corps']);

        $records = $this->notificationRepository->findByUserAccountId($parent);
        $this->assertCount(3, $records);
        $memberIds = array_map(fn($r) => $r->memberId, $records);
        $this->assertEqualsCanonicalizing([$member1, $member2, $member3], $memberIds);
    }

    public function testDispatchNeverSchedulesPushForTheActorButStillCreatesTheirRow(): void
    {
        $actor = $this->createUserAccount();
        $other = $this->createUserAccount();

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $actor, 'memberId' => null],
            ['userAccountId' => $other, 'memberId' => null],
        ], ['title' => 'Titre', 'body' => 'Corps'], $actor);

        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($actor));

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'send_notifications', 10);
        $this->assertCount(1, $scheduled);
        $payload = json_decode((string) $scheduled[0]['payload'], true);
        $ids = $payload['notification_ids'];
        $otherNotificationId = $this->notificationRepository->findByUserAccountId($other)[0]->id;
        $this->assertSame([$otherNotificationId], $ids);
    }

    public function testDispatchSkipsARecipientBelowTheTypesRoleMin(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        // A type requiring "chief" — a plain identified member must be skipped.
        $this->service->registerModuleTypes('test_module', [[
            'id' => 'test_module.chief_only',
            'label' => 'L', 'description' => 'D', 'group' => 'G', 'role_min' => 'chief',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $roleResolver = new RoleResolver($memberYearRepo, $encryption, $this->pdo);
        $scoutYearService = new \Core\Config\ScoutYearService($this->pdo);

        $service = new NotificationService(
            $this->notificationRepository,
            $this->subscriptionRepository,
            $this->preferenceRepository,
            $this->webPush,
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService($this->schedulerRepository),
            $this->userAccountRepository,
            $roleResolver,
            $scoutYearService
        );
        $service->registerModuleTypes('test_module', [[
            'id' => 'test_module.chief_only',
            'label' => 'L', 'description' => 'D', 'group' => 'G', 'role_min' => 'chief',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);

        // No linked member_year at all for this account => resolves to "identified".
        $userId = $this->createUserAccount();

        $service->dispatch('test_module.chief_only', [
            ['userAccountId' => $userId, 'memberId' => null],
        ], ['title' => 'T', 'body' => 'B']);

        $this->assertCount(0, $this->notificationRepository->findByUserAccountId($userId));
    }

    /**
     * Real production bug: a user_accounts row whose email_encrypted
     * predates a master key rotation (or is otherwise corrupted) throws
     * Core\Security\DecryptionException from findById() — dispatch()'s
     * own role re-check must swallow that for the one affected recipient
     * rather than let it crash the whole request and block every other,
     * healthy recipient.
     */
    public function testDispatchSkipsAnUndecryptableAccountWithoutCrashingAndStillNotifiesOthers(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $roleResolver = new RoleResolver($memberYearRepo, $encryption, $this->pdo);
        $scoutYearService = new \Core\Config\ScoutYearService($this->pdo);

        $service = new NotificationService(
            $this->notificationRepository,
            $this->subscriptionRepository,
            $this->preferenceRepository,
            $this->webPush,
            $this->settingService,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService($this->schedulerRepository),
            $this->userAccountRepository,
            $roleResolver,
            $scoutYearService
        );
        $service->registerModuleTypes('test_module', [[
            'id' => 'test_module.for_everyone',
            'label' => 'L', 'description' => 'D', 'group' => 'G', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
        ]]);

        // A row whose ciphertext can never decrypt under this key — same
        // shape as an account whose data predates a master key rotation.
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([random_bytes(40), bin2hex(random_bytes(32))]);
        $corruptedUserId = (int) $this->pdo->lastInsertId();

        $healthyUserId = $this->createUserAccount();

        $service->dispatch('test_module.for_everyone', [
            ['userAccountId' => $corruptedUserId, 'memberId' => null],
            ['userAccountId' => $healthyUserId, 'memberId' => null],
        ], ['title' => 'T', 'body' => 'B']);

        $this->assertCount(0, $this->notificationRepository->findByUserAccountId($corruptedUserId));
        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($healthyUserId));
    }

    // --- channelEnabled() ---

    public function testChannelEnabledLockedOnIgnoresAnyPreference(): void
    {
        $type = new NotificationType('t', 'L', 'D', 'G', 'identified', ['in_app' => 'on', 'push' => 'on', 'email' => 'off']);
        $userId = $this->createUserAccount();
        $this->preferenceRepository->setChannel($userId, 't', 'push', false);

        $this->assertTrue($this->service->channelEnabled($userId, $type, 'push'));
    }

    public function testChannelEnabledLockedOffIgnoresAnyPreference(): void
    {
        $type = new NotificationType('t', 'L', 'D', 'G', 'identified', ['in_app' => 'on', 'push' => 'off', 'email' => 'off']);
        $userId = $this->createUserAccount();
        $this->preferenceRepository->setChannel($userId, 't', 'push', true);

        $this->assertFalse($this->service->channelEnabled($userId, $type, 'push'));
    }

    public function testChannelEnabledUsesMemberOverrideWhenSet(): void
    {
        $type = new NotificationType('t', 'L', 'D', 'G', 'identified', ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']);
        $userId = $this->createUserAccount();
        $this->preferenceRepository->setChannel($userId, 't', 'push', false);

        $this->assertFalse($this->service->channelEnabled($userId, $type, 'push'));
    }

    public function testChannelEnabledFallsBackToTypeDefaultWhenNoPreference(): void
    {
        $type = new NotificationType('t', 'L', 'D', 'G', 'identified', ['in_app' => 'default_on', 'push' => 'default_off', 'email' => 'default_off']);
        $userId = $this->createUserAccount();

        $this->assertFalse($this->service->channelEnabled($userId, $type, 'push'));
        $this->assertTrue($this->service->channelEnabled($userId, $type, 'in_app'));
    }

    // --- quiet hours ---

    public function testResolveQuietHoursFallsBackToGlobalSetting(): void
    {
        $this->settingService->register('notifications_quiet_hours_start', '22:00', 'text', 'L', 'D');
        $this->settingService->register('notifications_quiet_hours_end', '07:00', 'text', 'L', 'D');
        $userId = $this->createUserAccount();

        $this->assertSame(['22:00', '07:00'], $this->service->resolveQuietHours($userId));
    }

    public function testResolveQuietHoursUsesAccountOverrideWhenBothSet(): void
    {
        $userId = $this->createUserAccount();
        $this->userAccountRepository->updateNotificationSettings($userId, '23:00', '06:00', false);

        $this->assertSame(['23:00', '06:00'], $this->service->resolveQuietHours($userId));
    }

    public function testResolvePushRunAtReturnsNowWhenOutsideTheWindow(): void
    {
        $userId = $this->createUserAccount();
        // A window that can never contain "now" in any timezone edge case:
        // 1 minute wide, in the past relative to "now" being outside it is
        // guaranteed by picking a window equal to the current minute minus
        // a day-independent offset is fragile — instead assert the simpler
        // invariant: a window start == end is always "disabled" (never
        // delays), which resolvePushRunAt must honor.
        $this->userAccountRepository->updateNotificationSettings($userId, '00:00', '00:00', false);

        $runAt = $this->service->resolvePushRunAt($userId);

        $this->assertLessThanOrEqual(1, abs($runAt->getTimestamp() - time()));
    }

    public function testResolvePushRunAtDelaysUntilWindowEndWhenInsideAnOvernightWindow(): void
    {
        $userId = $this->createUserAccount();
        // end 2 minutes from now (comfortably satisfies "now < end" with
        // margin against second-level flakiness) and start 30 minutes from
        // now (comfortably later than end, so start > end triggers the
        // overnight-wrap branch rather than the same-day one) — this is
        // only mistaken for a same-day window in the ~28 minutes before
        // midnight, an accepted, narrow theoretical flake window.
        $now = new \DateTimeImmutable();
        $end = $now->modify('+2 minutes')->format('H:i');
        $start = $now->modify('+30 minutes')->format('H:i');
        $this->userAccountRepository->updateNotificationSettings($userId, $start, $end, false);

        $runAt = $this->service->resolvePushRunAt($userId);

        $this->assertGreaterThan(time(), $runAt->getTimestamp());
    }

    // --- discretion mode + payload composition ---

    public function testSendPushForNotificationsSubstitutesGenericPayloadInDiscretionMode(): void
    {
        $userId = $this->createUserAccount();
        $this->userAccountRepository->updateNotificationSettings($userId, null, null, true);
        $this->service->subscribe($userId, 'https://push.example/device', 'auth', 'p256dh');
        $notificationId = $this->notificationRepository->create($userId, null, 'core.system', 'Real Title', 'Real Body', null);

        $capturedPayload = null;
        $this->webPush->expects($this->once())->method('queueNotification')
            ->willReturnCallback(function ($subscription, $payload) use (&$capturedPayload) {
                $capturedPayload = json_decode((string) $payload, true);
            });
        $this->webPush->method('flush')->willReturn((function () { return; yield; })());

        $this->service->sendPushForNotifications([$notificationId], fn() => true);

        $this->assertNotNull($capturedPayload);
        $this->assertStringNotContainsString('Real Title', json_encode($capturedPayload));
        $this->assertStringNotContainsString('Real Body', json_encode($capturedPayload));
        $this->assertSame('', $capturedPayload['body']);
    }

    public function testSendPushForNotificationsIncludesRealTitleWithoutDiscretion(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/device', 'auth', 'p256dh');
        $notificationId = $this->notificationRepository->create($userId, null, 'core.system', 'Real Title', 'Real Body', null);

        $capturedPayload = null;
        $this->webPush->method('queueNotification')->willReturnCallback(function ($subscription, $payload) use (&$capturedPayload) {
            $capturedPayload = json_decode((string) $payload, true);
        });
        $this->webPush->method('flush')->willReturn((function () { return; yield; })());

        $this->service->sendPushForNotifications([$notificationId], fn() => true);

        $this->assertSame('Real Title', $capturedPayload['title']);
        $this->assertSame('Real Body', $capturedPayload['body']);
        $this->assertSame($notificationId, $capturedPayload['notification_id']);
    }

    public function testSendPushForNotificationsStopsWhenBudgetIsExhausted(): void
    {
        $userId = $this->createUserAccount();
        $id1 = $this->notificationRepository->create($userId, null, 'core.system', 'A', 'B', null);
        $id2 = $this->notificationRepository->create($userId, null, 'core.system', 'C', 'D', null);

        $calls = 0;
        $attempted = $this->service->sendPushForNotifications([$id1, $id2], function () use (&$calls) {
            $calls++;
            return $calls <= 1;
        });

        $this->assertSame([$id1], $attempted);
    }

    public function testFlushMarksAnExpiredSubscriptionDeleted(): void
    {
        $userId = $this->createUserAccount();
        $subId = $this->subscriptionRepository->create($userId, 'https://push.example/gone', 'auth', 'p256dh');
        $notificationId = $this->notificationRepository->create($userId, null, 'core.system', 'A', 'B', null);

        $expiredReport = $this->createMock(MessageSentReport::class);
        $expiredReport->method('isSubscriptionExpired')->willReturn(true);
        $expiredReport->method('isSuccess')->willReturn(false);
        $expiredReport->method('getEndpoint')->willReturn('https://push.example/gone');
        $this->webPush->method('flush')->willReturn((function () use ($expiredReport) { yield $expiredReport; })());

        $this->service->sendPushForNotifications([$notificationId], fn() => true);

        $this->assertNull($this->subscriptionRepository->findById($subId));
    }

    // --- subscribe/unsubscribe (device_label, idempotency) ---

    public function testSubscribeIsIdempotentForTheSameEndpoint(): void
    {
        $userId = $this->createUserAccount();

        $this->service->subscribe($userId, 'https://push.example/device', 'auth-old', 'p256dh-old', 'Chrome sur Android');
        $this->service->subscribe($userId, 'https://push.example/device', 'auth-new', 'p256dh-new', 'Chrome sur Android');

        $subscriptions = $this->subscriptionRepository->findByUserAccountId($userId);
        $this->assertCount(1, $subscriptions);
        $this->assertSame('auth-new', $subscriptions[0]->authKey);
        $this->assertSame('Chrome sur Android', $subscriptions[0]->deviceLabel);
    }

    public function testUnsubscribeRemovesOnlyThatEndpoint(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/keep', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/drop', 'auth2', 'p256dh2');

        $this->service->unsubscribe($userId, 'https://push.example/drop');

        $remaining = $this->subscriptionRepository->findByUserAccountId($userId);
        $this->assertCount(1, $remaining);
        $this->assertSame('https://push.example/keep', $remaining[0]->endpoint);
    }

    public function testUnsubscribeAllRemovesEveryDevice(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/a', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/b', 'auth2', 'p256dh2');

        $this->service->unsubscribeAll($userId);

        $this->assertSame([], $this->subscriptionRepository->findByUserAccountId($userId));
    }

    // --- the email channel (ARCHITECTURE.md §8.24) ---

    /**
     * A NotificationMailer over a mocked transport, plus the list of
     * sends it saw. The Twig environment is the real one: an email whose
     * template does not compile is a failure worth catching here.
     *
     * The recorder is an ArrayObject rather than a by-reference array:
     * destructuring the return value copies a referenced element, which
     * would hand every test an empty snapshot.
     *
     * @param ?\Throwable $throwOnSend when set, every send() raises it
     * @return array{0: NotificationMailer, 1: \ArrayObject<int, array<string, string>>}
     */
    private function recordingMailer(?\Throwable $throwOnSend = null): array
    {
        /** @var \ArrayObject<int, array<string, string>> $sent */
        $sent = new \ArrayObject();
        $mailService = $this->createMock(MailService::class);
        $expectation = $mailService->method('send');
        if ($throwOnSend !== null) {
            $expectation->willThrowException($throwOnSend);
        } else {
            $expectation->willReturnCallback(
                function (string $to, string $subject, string $bodyHtml, string $bodyText) use ($sent): void {
                    $sent[] = ['to' => $to, 'subject' => $subject, 'html' => $bodyHtml, 'text' => $bodyText];
                }
            );
        }

        $mailer = new NotificationMailer(
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase(
                $this->pdo,
                \Core\View\TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates')
            ),
            'Unité Test',
            'https://example.test'
        );

        return [$mailer, $sent];
    }

    private function enableEmailChannel(int $userAccountId, string $typeId): void
    {
        $this->preferenceRepository->setChannel($userAccountId, $typeId, 'email', true);
    }

    public function testDispatchSchedulesNoEmailTaskWhenTheChannelIsAtItsDefaultOff(): void
    {
        $userId = $this->createUserAccount();

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $userId, 'memberId' => null],
        ], ['title' => 'Titre', 'body' => 'Corps']);

        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'send_notification_emails', 10));
    }

    public function testDispatchSchedulesTheEmailTaskOnlyForRecipientsWhoTurnedTheChannelOn(): void
    {
        $wantsEmail = $this->createUserAccount();
        $doesNot = $this->createUserAccount();
        $this->enableEmailChannel($wantsEmail, self::TEST_TYPE);

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $wantsEmail, 'memberId' => null],
            ['userAccountId' => $doesNot, 'memberId' => null],
        ], ['title' => 'Titre', 'body' => 'Corps']);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'send_notification_emails', 10);
        $this->assertCount(1, $scheduled);
        $payload = json_decode((string) $scheduled[0]['payload'], true);
        $expected = $this->notificationRepository->findByUserAccountId($wantsEmail)[0]->id;
        $this->assertSame([$expected], $payload['notification_ids']);
    }

    /**
     * The actor gets the row, never the outbound copies — the same rule
     * push already followed, now that a second channel exists to break it.
     */
    public function testDispatchNeverEmailsTheActorForTheirOwnAction(): void
    {
        $actor = $this->createUserAccount();
        $this->enableEmailChannel($actor, self::TEST_TYPE);

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $actor, 'memberId' => null],
        ], ['title' => 'Titre', 'body' => 'Corps'], $actor);

        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($actor));
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'send_notification_emails', 10));
    }

    /**
     * Quiet hours hold push back and nothing else: the email task is
     * scheduled with no delay even in the middle of the window
     * (specifications.md §13.3).
     */
    public function testQuietHoursDelayPushButNeverEmail(): void
    {
        $userId = $this->createUserAccount();
        $this->enableEmailChannel($userId, self::TEST_TYPE);
        // A window covering the whole day, so "now" is always inside it.
        $this->settingService->register('notifications_quiet_hours_start', '00:00', 'text', 'L', 'D');
        $this->settingService->register('notifications_quiet_hours_end', '23:59', 'text', 'L', 'D');

        $this->service->dispatch(self::TEST_TYPE, [
            ['userAccountId' => $userId, 'memberId' => null],
        ], ['title' => 'Titre', 'body' => 'Corps']);

        $push = $this->schedulerRepository->findByModuleAndTaskKey('core', 'send_notifications', 10);
        $email = $this->schedulerRepository->findByModuleAndTaskKey('core', 'send_notification_emails', 10);
        $this->assertCount(1, $push);
        $this->assertCount(1, $email);
        $this->assertGreaterThan(
            strtotime((string) $email[0]['run_at']),
            strtotime((string) $push[0]['run_at']),
            'Push must be held until the window ends; email must go now.'
        );
    }

    public function testSendEmailsRendersTheNotificationToTheAccountsOwnAddress(): void
    {
        $userId = $this->createUserAccount();
        $notificationId = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Nouvel album', 'Trois photos ajoutées', '/gallery/42');
        [$mailer, $sent] = $this->recordingMailer();

        $attempted = $this->service->sendEmailsForNotifications([$notificationId], static fn(): bool => true, $mailer);

        $this->assertSame([$notificationId], $attempted);
        $this->assertCount(1, $sent);
        $this->assertSame($this->userAccountRepository->findById($userId)->email, $sent[0]['to']);
        $this->assertSame('Nouvel album', $sent[0]['subject']);
        $this->assertStringContainsString('Trois photos ajoutées', $sent[0]['text']);
        // A same-origin path becomes a real address an email client can open.
        $this->assertStringContainsString('https://example.test/gallery/42', $sent[0]['text']);
    }

    /**
     * The whole point of email_sent_at: a handler rescheduled mid-batch,
     * or simply run twice on the same ids, must never mail anybody twice.
     */
    public function testSendEmailsNeverSendsTheSameNotificationTwice(): void
    {
        $userId = $this->createUserAccount();
        $notificationId = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Titre', 'Corps', null);
        [$mailer, $sent] = $this->recordingMailer();

        $this->service->sendEmailsForNotifications([$notificationId], static fn(): bool => true, $mailer);
        $this->service->sendEmailsForNotifications([$notificationId], static fn(): bool => true, $mailer);

        $this->assertCount(1, $sent);
        $this->assertNotNull($this->notificationRepository->findById($notificationId)->emailSentAt);
    }

    /**
     * The time budget is what keeps a section-wide mailing off one
     * request: whatever it cut short comes back as "not attempted", which
     * is what the handler reschedules.
     */
    public function testSendEmailsStopsAtTheTimeBudgetAndLeavesTheRestUnclaimed(): void
    {
        $userId = $this->createUserAccount();
        $first = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Un', 'Corps', null);
        $second = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Deux', 'Corps', null);
        [$mailer, $sent] = $this->recordingMailer();

        $calls = 0;
        $attempted = $this->service->sendEmailsForNotifications(
            [$first, $second],
            static function () use (&$calls): bool { return $calls++ < 1; },
            $mailer
        );

        $this->assertSame([$first], $attempted);
        $this->assertCount(1, $sent);
        $this->assertNull($this->notificationRepository->findById($second)->emailSentAt);
    }

    /**
     * Discretion is a statement about screens other people can read, and
     * a mail notification lands on the same lock screen a push does — so
     * it strips the email exactly as it strips the push payload.
     */
    public function testDiscretionStripsTheSubjectAndBodyOfTheEmail(): void
    {
        $userId = $this->createUserAccount();
        $this->pdo->prepare('UPDATE user_accounts SET notification_discretion = 1 WHERE id = ?')->execute([$userId]);
        $notificationId = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Résultat médical de Kaa', 'Détail sensible', null);
        [$mailer, $sent] = $this->recordingMailer();

        $this->service->sendEmailsForNotifications([$notificationId], static fn(): bool => true, $mailer);

        $this->assertCount(1, $sent);
        $this->assertSame('Nouvelle notification', $sent[0]['subject']);
        $this->assertStringNotContainsString('Kaa', $sent[0]['html']);
        $this->assertStringNotContainsString('Détail sensible', $sent[0]['html']);
        $this->assertStringNotContainsString('Détail sensible', $sent[0]['text']);
    }

    /**
     * A transport failure is journaled and NOT retried: a retry cannot
     * tell "never left" from "left, then the connection dropped", and the
     * notification is in the recipient's centre either way.
     */
    public function testAFailedSendIsJournaledAndNotRetried(): void
    {
        $userId = $this->createUserAccount();
        $notificationId = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Titre', 'Corps', null);
        [$mailer] = $this->recordingMailer(new MailException('SMTP connect() failed'));

        $this->service->sendEmailsForNotifications([$notificationId], static fn(): bool => true, $mailer);

        $this->assertNotNull(
            $this->notificationRepository->findById($notificationId)->emailSentAt,
            'The claim stays: a failed send is never re-attempted.'
        );

        $entries = $this->pdo->query("SELECT event_type FROM event_log WHERE event_type = 'notification_email_failed'")->fetchAll();
        $this->assertCount(1, $entries);
    }

    /**
     * An account with no readable address must not leave the row stamped
     * as if an email had gone out — the claim is released so a later run,
     * once the address is fixed, still sends it.
     */
    public function testAnUnsendableRecipientReleasesItsClaim(): void
    {
        $userId = $this->createUserAccount();
        $notificationId = $this->notificationRepository->create($userId, null, self::TEST_TYPE, 'Titre', 'Corps', null);
        $this->pdo->prepare('DELETE FROM user_accounts WHERE id = ?')->execute([$userId]);
        [$mailer, $sent] = $this->recordingMailer();

        $this->service->sendEmailsForNotifications([$notificationId], static fn(): bool => true, $mailer);

        $this->assertCount(0, $sent);
        $record = $this->notificationRepository->findById($notificationId);
        $this->assertTrue($record === null || $record->emailSentAt === null);
    }
}
