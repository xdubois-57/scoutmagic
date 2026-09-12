<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Alert\OperationalAlertService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Notification\NotificationMailer;
use Core\Notification\NotificationMailerFactory;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The alert about the engine must not travel by the engine (issue #296).
 *
 * `Core\Alert\Check\CronSilenceCheck` fires when the real cron has been
 * silent for 48 hours. Its notification went through
 * `NotificationService::dispatch()`, which queued the e-mail as
 * `core/send_notification_emails` — a task drained by `public/cron.php`,
 * the very cron being reported dead. So the one channel built for an
 * administrator who is NOT looking at the site was the one channel the
 * failure had already closed, and the mail arrived, when it arrived, as
 * old news about a cron somebody had repaired by hand.
 *
 * What closes it is a property of the TYPE rather than a flag at the call
 * site, and these tests pin both halves: the alert type sends during the
 * dispatch, and everything else still queues. A type that opts out of the
 * queue is asking to pay its SMTP round trips inside the request that
 * raised it, which is right for a handful of superadmins on the rarest
 * event the site has and wrong for a mailing to a section.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ImmediateDeliveryTest extends TestCase
{
    /** A type declared by a module, which can never be immediate. */
    private const MODULE_TYPE = 'test_module.thing_happened';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private NotificationRepository $notifications;
    private NotificationPreferenceRepository $preferences;
    private SchedulerRepository $scheduler;
    private SettingService $settings;
    private JournalRepository $journalRepository;

    /** @var \ArrayObject<int, string> every address a message actually left for */
    private \ArrayObject $sent;

    /**
     * The recording transport, built once per test.
     *
     * One instance, deliberately: a second mock would record into nothing,
     * and a test that sends through it would pass whatever the code did.
     */
    private ?MailService $mailService = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->notifications = new NotificationRepository($this->pdo, $this->encryption);
        $this->preferences = new NotificationPreferenceRepository($this->pdo);
        $this->scheduler = new SchedulerRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->journalRepository = new JournalRepository($this->pdo);
        $this->sent = new \ArrayObject();
    }

    /**
     * The service as a composition root builds it, with a real
     * {@see NotificationMailerFactory} over a mocked transport.
     *
     * The factory is the real one on purpose: it builds the Twig
     * environment and the renderer itself, so a template that stopped
     * compiling would fail here rather than in production. Only the last
     * step — handing the message to a mail server — is a mock, and what
     * it records is the list of addresses a message genuinely left for.
     */
    private function service(
        ?\Throwable $transportFails = null,
        bool $withMailerFactory = true,
        ?NotificationMailerFactory $mailerFactory = null,
        ?PushSubscriptionRepository $subscriptions = null
    ): NotificationService {
        $mailService = $this->recordingTransport($transportFails);
        $journal = new JournalService($this->journalRepository);

        $service = new NotificationService(
            $this->notifications,
            $subscriptions ?? new PushSubscriptionRepository($this->pdo, $this->encryption),
            $this->preferences,
            $this->createMock(WebPush::class),
            $this->settings,
            $journal,
            new SchedulerService($this->scheduler),
            new UserAccountRepository($this->pdo, $this->encryption),
            null,
            null,
            null,
            $withMailerFactory
                ? ($mailerFactory ?? new NotificationMailerFactory($mailService, $this->pdo, $this->settings, $journal))
                : null
        );

        $service->registerModuleTypes('test_module', [[
            'id' => self::MODULE_TYPE,
            'label' => 'Chose arrivée',
            'description' => 'desc',
            'group' => 'Test',
            'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_on'],
        ]]);

        return $service;
    }

    /**
     * The one mail transport of this test, appending every address it is
     * handed to {@see $sent} — or throwing, when the test is about what
     * happens then.
     *
     * Memoised, so a second mailer built later in the same test records
     * into the same list. It did not use to be, and the test that watched
     * a claimed row not being sent twice was handing the second pass a
     * fresh, unconfigured mock: its `send()` returned null into nothing,
     * so the assertion held whether or not the claim existed at all.
     */
    private function recordingTransport(?\Throwable $fails = null): MailService
    {
        if ($this->mailService !== null) {
            return $this->mailService;
        }

        $mailService = $this->createMock(MailService::class);
        $expectation = $mailService->method('send');
        if ($fails !== null) {
            $expectation->willThrowException($fails);
        } else {
            $sent = $this->sent;
            $expectation->willReturnCallback(static function (string $to) use ($sent): void {
                $sent[] = $to;
            });
        }

        return $this->mailService = $mailService;
    }

    private function createUserAccount(): int
    {
        $email = 'user_' . uniqid() . '@test.example';
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $this->encryption->encrypt($email, 'user_accounts.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queuedEmailTasks(): array
    {
        return $this->scheduler->findByModuleAndTaskKey('core', 'send_notification_emails', 10);
    }

    private function dispatchAlert(NotificationService $service, int $userAccountId): void
    {
        $service->dispatch(
            OperationalAlertService::TYPE_DEFAULT,
            [['userAccountId' => $userAccountId, 'memberId' => null]],
            [
                'title' => 'La tâche planifiée ne s\'est plus exécutée depuis 49 heures.',
                'body' => 'C\'est le moteur du site.',
                'url' => '/config/maintenance',
            ]
        );
    }

    /** The defect, as a test: the mail leaves, and nothing waits on the cron. */
    public function testAnOperationalAlertMailsDuringTheDispatchInsteadOfQueueing(): void
    {
        $admin = $this->createUserAccount();

        $this->dispatchAlert($this->service(), $admin);

        $this->assertCount(1, $this->sent, 'The alert e-mail never reached a transport.');
        $this->assertSame(
            [],
            $this->queuedEmailTasks(),
            'The alert queued its e-mail behind core/send_notification_emails — which only the cron it is '
            . 'reporting dead ever drains.'
        );
    }

    /**
     * And the row is stamped, so the queue could not send it twice if
     * something else scheduled it: the immediate path claims each
     * notification exactly as the scheduled handler does.
     */
    public function testTheImmediateSendClaimsTheRowLikeTheScheduledOneDoes(): void
    {
        $admin = $this->createUserAccount();
        $service = $this->service();

        $this->dispatchAlert($service, $admin);
        $notificationId = $this->notifications->findByUserAccountId($admin)[0]->id;

        // The SAME transport the dispatch above sent through — a second
        // mock would record into nothing and this would pass with the
        // claim deleted.
        $service->sendEmailsForNotifications(
            [$notificationId],
            static fn (): bool => true,
            (new NotificationMailerFactory(
                $this->recordingTransport(),
                $this->pdo,
                $this->settings,
                new JournalService($this->journalRepository)
            ))->create()
        );

        $this->assertCount(1, $this->sent, 'A second pass over the same row sent the alert twice.');
    }

    /**
     * The exception is the type's, not every dispatch's. A module type —
     * a mailing to a section — keeps its queue, which is the whole reason
     * the queue exists.
     */
    public function testAnOrdinaryTypeStillQueuesItsEmail(): void
    {
        $member = $this->createUserAccount();

        $this->service()->dispatch(
            self::MODULE_TYPE,
            [['userAccountId' => $member, 'memberId' => null]],
            ['title' => 'Titre', 'body' => 'Corps']
        );

        $this->assertCount(0, $this->sent, 'An ordinary notification paid an SMTP round trip inside the request.');
        $this->assertCount(1, $this->queuedEmailTasks());
    }

    /**
     * A module cannot declare itself immediate — the `module.json` shape
     * `registerModuleTypes()` reads has no such key, so there is nothing
     * for a manifest to set.
     */
    public function testAModuleTypeIsNeverImmediate(): void
    {
        $type = $this->service()->findType(self::MODULE_TYPE);

        $this->assertNotNull($type);
        $this->assertFalse($type->deliversImmediately);
    }

    /**
     * Quiet hours still win, and that is a decision rather than an
     * oversight: urgency is not a reason to overrule a setting whose
     * whole subject is what may wake somebody at three in the morning.
     * The push waits for the scheduler; the e-mail does not, because
     * quiet hours were never about e-mail (specifications.md §13.3).
     */
    public function testQuietHoursStillHoldThePushBackWhileTheMailGoesNow(): void
    {
        $admin = $this->createUserAccount();
        $this->settings->register('notifications_quiet_hours_start', '00:00', 'text', 'L', 'D');
        $this->settings->register('notifications_quiet_hours_end', '23:59', 'text', 'L', 'D');

        $this->dispatchAlert($this->service(), $admin);

        $this->assertCount(
            1,
            $this->scheduler->findByModuleAndTaskKey('core', 'send_notifications', 10),
            'A push held back by quiet hours must still be scheduled.'
        );
        $this->assertCount(1, $this->sent, 'Quiet hours are a push setting; the mail still goes.');
    }

    /**
     * The degradation, which has to be the safe direction: a service
     * built without a mailer factory queues the alert rather than losing
     * it. Late is bad; gone is worse.
     */
    public function testWithoutAMailerFactoryTheAlertFallsBackToTheQueue(): void
    {
        $admin = $this->createUserAccount();

        $this->dispatchAlert($this->service(withMailerFactory: false), $admin);

        $this->assertCount(1, $this->queuedEmailTasks());
    }

    /**
     * And the same fallback when the mailer cannot be BUILT — Twig with
     * a cache directory it cannot write, on the full disk one of these
     * alerts is about. Nothing has been claimed at that point, so the
     * queue still has the message.
     *
     * The distinction this pins is between the two try/catch blocks: a
     * failure here falls back, a failure once a row is claimed does not,
     * and collapsing them would silently turn the first into the second.
     */
    public function testAMailerThatCannotBeBuiltFallsBackToTheQueueToo(): void
    {
        $admin = $this->createUserAccount();

        $refuses = new class (
            $this->createMock(MailService::class),
            $this->pdo,
            $this->settings,
            new JournalService($this->journalRepository)
        ) extends NotificationMailerFactory {
            public function create(): NotificationMailer
            {
                throw new \RuntimeException('Twig cache directory is not writable');
            }
        };

        $this->dispatchAlert($this->service(mailerFactory: $refuses), $admin);

        $this->assertCount(1, $this->queuedEmailTasks(), 'A mailer that would not build lost the alert entirely.');
        $this->assertCount(0, $this->sent);
    }

    /**
     * An immediate send runs inside whatever raised the alert — a
     * visitor's request, or a scheduler pass with other checks still to
     * evaluate. A mail server that refuses the connection must not become
     * a 500 on somebody's page, and must not stop the rest of the
     * dispatch: it is journaled, and the notification is in the
     * recipient's centre regardless.
     */
    public function testATransportFailureIsJournaledRatherThanThrown(): void
    {
        $admin = $this->createUserAccount();

        $this->dispatchAlert($this->service(new MailException('SMTP connect() failed')), $admin);

        $this->assertCount(
            1,
            $this->notifications->findByUserAccountId($admin),
            'The in-app copy must survive a transport that refused the e-mail.'
        );
        $this->assertGreaterThan(
            0,
            $this->journalRepository->countEventsSince('core', 'notification_email_failed', '1970-01-01 00:00:00'),
            'A send that failed left no trace at all.'
        );
    }

    /**
     * A throw is not the same as a failed send, and the difference is a
     * whole batch.
     *
     * `NotificationMailer::send()` answers false for a transport that
     * refused — the test above — and the loop moves to the next
     * recipient. Anything it does NOT catch (a template that will not
     * render on a full disk, a repository that cannot reach the database)
     * aborts the loop where it stands, so every recipient after that one
     * was never even looked at. Dropping them would make an alert with
     * three superadmins reach one and lose two, silently and for good.
     *
     * So the remainder goes back to the queue, exactly as
     * `Task\SendNotificationEmailsHandler` has always done with whatever
     * its time budget cut short. Late is bad; gone is worse.
     */
    public function testAThrowingSendHandsTheRestOfTheBatchBackToTheQueue(): void
    {
        $admins = [$this->createUserAccount(), $this->createUserAccount(), $this->createUserAccount()];

        $this->service(new \RuntimeException('Twig could not write the compiled template'))->dispatch(
            OperationalAlertService::TYPE_DEFAULT,
            array_map(static fn (int $id): array => ['userAccountId' => $id, 'memberId' => null], $admins),
            ['title' => 'Disque presque plein', 'body' => 'Il reste 4 %.', 'url' => '/config/maintenance']
        );

        $queued = $this->queuedEmailTasks();
        $this->assertCount(1, $queued, 'The recipients the send never reached were dropped rather than requeued.');

        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertCount(
            3,
            $payload['notification_ids'],
            'The queue must carry every id, claimed or not: claimForEmail() is what skips the ones already stamped.'
        );
    }

    /**
     * The same for push, where the trade is the other way round and taken
     * deliberately.
     *
     * A throw leaves no record of how far the send got — PHP does not
     * partially apply the assignment — so the whole bucket goes back to
     * `core/send_notifications`. Push has no claim (ARCHITECTURE.md
     * §8.24: a duplicate push replaces its predecessor in the tray, which
     * is why it never needed one), so a device that had already received
     * the alert may see it again. That is the accepted cost; an alert
     * about the site's own health that nobody resends is simply gone.
     */
    public function testAThrowingPushSendsTheBucketBackToTheQueueRatherThanDroppingIt(): void
    {
        $admin = $this->createUserAccount();

        // The database going away mid-request, which is one of the ways
        // the push half can throw: a malformed subscription is already
        // isolated per device inside queuePushForAccount().
        $failing = new class ($this->pdo, $this->encryption) extends PushSubscriptionRepository {
            public function findByUserAccountId(int $userAccountId): array
            {
                throw new \RuntimeException('SQLSTATE[HY000]: server has gone away');
            }
        };

        $this->dispatchAlert($this->service(subscriptions: $failing), $admin);

        $queued = $this->scheduler->findByModuleAndTaskKey('core', 'send_notifications', 10);
        $this->assertCount(1, $queued, 'A push that threw was dropped instead of being handed back to the queue.');

        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertSame(
            [$this->notifications->findByUserAccountId($admin)[0]->id],
            $payload['notification_ids']
        );
    }

    /**
     * These failures come in pairs, so the note about one must not cost
     * the recovery from the other.
     *
     * `JournalRepository::insert()` rethrows a storage failure when the
     * entry carries no user id, and an operational alert's entries never
     * do — nobody asked for it. A journal that throws inside the catch
     * block would therefore escape the one method promising nothing
     * escapes, and skip the reschedule on its way past: the recipients
     * the send never reached would be lost because the site could not
     * write down that it had failed.
     */
    public function testAJournalThatAlsoFailsDoesNotCostTheReschedule(): void
    {
        $admin = $this->createUserAccount();

        $journal = new class (new JournalRepository($this->pdo)) extends JournalService {
            public function log(
                string $category,
                string $type,
                string $level,
                string $description,
                array $context = [],
                ?int $userId = null
            ): void {
                // Only the entry written from inside a catch block: the
                // ordinary `notification_sent` line is not this test's
                // subject, and a journal that refuses every write has
                // broken dispatch() since long before immediate delivery.
                if ($type === 'notification_immediate_delivery_failed') {
                    throw new \RuntimeException('SQLSTATE[HY000]: the event log is unreachable too');
                }
            }
        };

        $service = new NotificationService(
            $this->notifications,
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            $this->preferences,
            $this->createMock(WebPush::class),
            $this->settings,
            $journal,
            new SchedulerService($this->scheduler),
            new UserAccountRepository($this->pdo, $this->encryption),
            null,
            null,
            null,
            new NotificationMailerFactory(
                $this->recordingTransport(new \RuntimeException('the message would not render')),
                $this->pdo,
                $this->settings,
                $journal
            )
        );

        $this->dispatchAlert($service, $admin);

        $this->assertCount(
            1,
            $this->queuedEmailTasks(),
            'A journal that threw took the reschedule with it, so the alert was lost twice over.'
        );
    }
}
