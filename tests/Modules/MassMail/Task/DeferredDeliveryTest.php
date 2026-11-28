<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\BulkCadence;
use Core\Mail\Transport\DeferredMailAbandonment;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\LaneExhaustedException;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\Task\DrainDeferredMailHandler;
use Core\Module\DeferredMailOutcomeListener;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskCapabilities;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Task\DeferredDeliveryListener;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Task\SendBatchHandler;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * A mailing's message the provider could not take (issue #1454), from the
 * batch to the core's deferred queue and back to the recipient's line.
 *
 * **Through the real `MailService` and the real queue**, which is the whole
 * point: the batch's other tests use a `MailService` double with neither,
 * and that is how a message that only waited came to be recorded « Envoyé »
 * with nothing in the suite to say otherwise. The one thing stood in for is
 * the relay: a transport that answers like a lane with nothing left, or
 * delivers, as the test needs.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class DeferredDeliveryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private DeliveryRepository $deliveries;
    private UserAccountRepository $accounts;
    private DeferredMailRepository $queued;
    private SettingService $settings;
    private string $tempDir;
    private int $emailId;
    private int $memberId;
    private int $scoutYearId;

    /** Whether the relay stand-in answers « lane exhausted » (true) or delivers. */
    private bool $laneExhausted = true;

    /** @var list<string> who the relay stand-in delivered to */
    private array $delivered = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->deliveries = new DeliveryRepository($this->pdo, $this->encryption);
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->queued = new DeferredMailRepository($this->pdo, $this->encryption);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            DeferredMailQueue::SETTING_LIFETIME_HOURS => DeferredMailQueue::DEFAULT_LIFETIME_HOURS,
            DeferredMailQueue::SETTING_ABANDONED_RETENTION_DAYS => DeferredMailQueue::DEFAULT_ABANDONED_RETENTION_DAYS,
        ] as $key => $default) {
            $this->settings->register($key, (string) $default, 'number', 'Test', 'Test', null, '^[1-9][0-9]*$');
        }
        $this->tempDir = sys_get_temp_dir() . '/deferred_delivery_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet', '<p>Corps</p>', {$sectionId}, 'default_active_members', '" . Email::STATUS_SENDING . "')"
        );
        $this->emailId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempDir);
    }

    /**
     * **The ticket's first case.** The quota is spent: the message waits in
     * the core's queue, the recipient's line says « en attente chez le
     * fournisseur » rather than « Envoyé », nothing is journaled as sent,
     * nobody is notified, and the mailing does not reach « Envoyé ».
     */
    public function testADeferredMessageIsWaitingNotSentAndTheMailingIsNotFinished(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test', withAccount: true);

        (new SendBatchHandler())->handle([], $this->batchContext());

        $delivery = $this->deliveries->findById($deliveryId);
        $this->assertSame(Delivery::STATUS_DEFERRED, $delivery?->status);
        $this->assertNull($delivery->sentAt);
        $counts = $this->deliveries->countGroupedByStatus($this->emailId);
        $this->assertSame(0, $counts['sent'], 'a message only kept for later is not counted as sent');
        $this->assertSame(1, $counts['deferred']);
        $this->assertSame(Email::STATUS_SENDING, $this->emailStatus());

        $this->assertSame(0, $this->journalCount('delivery_sent'));
        $deferred = $this->journalContexts('delivery_deferred');
        $this->assertCount(1, $deferred);
        $this->assertSame(
            ['email_id' => $this->emailId, 'delivery_id' => $deliveryId, 'lane' => 'bulk'],
            $deferred[0]
        );
        $this->assertSame(0, $this->notificationCount(), 'nobody is told about an e-mail that is not in their mailbox');

        $waiting = $this->queued->due(10, '2099-01-01 00:00:00');
        $this->assertCount(1, $waiting);
        $this->assertSame(DeferredDeliveryListener::originFor($deliveryId), $waiting[0]->origin);
    }

    /**
     * **The rest of the lot waits for the lane** instead of following the
     * first message into the queue: one deferral, the others still pending,
     * the chain re-armed, and the suspension written once.
     */
    public function testTheLotStopsAtTheFirstDeferral(): void
    {
        $first = $this->messageTo('a@exemple.test');
        $second = $this->messageTo('b@exemple.test');

        (new SendBatchHandler())->handle([], $this->batchContext());

        $this->assertSame(Delivery::STATUS_DEFERRED, $this->deliveries->findById($first)?->status);
        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($second)?->status);
        $this->assertCount(1, $this->queued->due(10, '2099-01-01 00:00:00'));
        $this->assertSame(1, $this->journalCount('batch_suspended'));
        $this->assertSame([['lane_suspended' => true]], $this->queuedBatchPayloads());
    }

    /**
     * **A lane with no room left suspends the mailing before anything is
     * tried** — eight hundred messages on a quota of three hundred no longer
     * pour five hundred into the queue. The chain asks again at the lane's
     * cadence, and the suspension is journaled when it starts, not at every
     * link.
     */
    public function testALaneWithoutRoomSuspendsTheMailingBeforeSendingAnything(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test');
        $cadence = $this->spentCadence();

        (new SendBatchHandler())->handle([], $this->batchContext($cadence));

        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($deliveryId)?->status);
        $this->assertSame([], $this->queued->due(10, '2099-01-01 00:00:00'), 'nothing was handed to the chain');
        $this->assertSame(1, $this->journalCount('batch_suspended'));
        $this->assertSame([['lane_suspended' => true]], $this->queuedBatchPayloads());

        $this->pdo->exec("DELETE FROM scheduled_actions WHERE module_id = 'mass_mail'");
        (new SendBatchHandler())->handle(['lane_suspended' => true], $this->batchContext($cadence));

        $this->assertSame(1, $this->journalCount('batch_suspended'), 'written when it starts, not at every link');
        $this->assertSame([['lane_suspended' => true]], $this->queuedBatchPayloads(), 'and the chain still re-arms');
    }

    /**
     * **The ticket's second case, first half.** The drain sends the message
     * a day later: the recipient's line becomes « Envoyé », the line the
     * batch did not write is written now, the account is told now, and the
     * mailing reaches « Envoyé » with nothing left waiting.
     */
    public function testADeferredMessageTheDrainSendsBecomesSentAndCompletesTheMailing(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test', withAccount: true);
        (new SendBatchHandler())->handle([], $this->batchContext());

        $this->laneExhausted = false;
        $this->drainNow();

        $this->assertSame(['parent@exemple.test'], $this->delivered);
        $delivery = $this->deliveries->findById($deliveryId);
        $this->assertSame(Delivery::STATUS_SENT, $delivery?->status);
        $this->assertNotNull($delivery->sentAt);
        $this->assertSame(Email::STATUS_SENT, $this->emailStatus());
        $this->assertSame(1, $this->journalCount('delivery_sent'));
        $this->assertSame(1, $this->notificationCount());
        $this->assertSame([], $this->queued->due(10, '2099-01-01 00:00:00'));
    }

    /**
     * **Second half.** The drain gives up: the recipient's line becomes an
     * error with a reason a chief can read, a `warning` names the message
     * and the reason — and no address anywhere — and the mailing is finished,
     * its count of sent messages honest.
     */
    public function testAnAbandonedDeferredMessageLandsOnTheRecipientsLineAsAnError(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test', withAccount: true);
        (new SendBatchHandler())->handle([], $this->batchContext());

        $this->pdo->exec("UPDATE mail_deferred_messages SET expires_at = '2000-01-01 00:00:00'");
        $this->drainNow();

        $this->assertSame([], $this->delivered, 'an expired message is never tried again');
        $delivery = $this->deliveries->findById($deliveryId);
        $this->assertSame(Delivery::STATUS_ERROR, $delivery?->status);
        $this->assertSame(DeferredMailAbandonment::Expired->label(), $delivery->errorMessage);
        $this->assertSame(Email::STATUS_SENT, $this->emailStatus(), 'nothing of it is left waiting');
        $this->assertSame(0, $this->deliveries->countGroupedByStatus($this->emailId)['sent']);

        $abandoned = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE category = 'mass_mail' AND event_type = 'delivery_abandoned'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $abandoned);
        $this->assertSame('warning', $abandoned[0]['level']);
        $this->assertSame(
            [
                'email_id' => $this->emailId,
                'delivery_id' => $deliveryId,
                'reason' => DeferredMailAbandonment::Expired->label(),
            ],
            json_decode((string) $abandoned[0]['context'], true)
        );
        $this->assertSame(0, $this->journalCount('delivery_sent'));
        $this->assertSame(0, $this->notificationCount());

        $everything = implode(' ', $this->pdo->query('SELECT description || context FROM event_log')
            ->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertStringNotContainsString('parent@exemple.test', $everything);
    }

    /**
     * **Unsubscribed while the message waited** (review of #1561). The
     * core's replay knows the bounce state, not this module's group-mail
     * unsubscribe; asked before the replay, the module withdraws the
     * message: nothing reaches the relay, the line reads the batch's own
     * « désinscrite » reason, the batch's own journal line says so, and
     * the mailing is finished.
     */
    public function testAMessageWhoseRecipientUnsubscribedWhileItWaitedIsNeverSent(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test', withAccount: true);
        (new SendBatchHandler())->handle([], $this->batchContext());
        (new SuppressedAddressRepository($this->pdo))->suppress('parent@exemple.test');

        $this->laneExhausted = false;
        $this->drainNow();

        $this->assertSame([], $this->delivered, 'nothing in the transport');
        $delivery = $this->deliveries->findById($deliveryId);
        $this->assertSame(Delivery::STATUS_ERROR, $delivery?->status);
        $this->assertSame(MassMailService::REASON_UNSUBSCRIBED, $delivery->errorMessage);
        $this->assertSame(Email::STATUS_SENT, $this->emailStatus());
        $this->assertSame(1, $this->journalCount('delivery_skipped_unsubscribed'));
        $this->assertSame(0, $this->journalCount('delivery_sent'));
        $this->assertSame(0, $this->notificationCount());
        $this->assertSame(
            ['abandoned'],
            $this->pdo->query('SELECT status FROM mail_deferred_messages')->fetchAll(\PDO::FETCH_COLUMN),
            'kept for the Relance screen, unsent'
        );
    }

    /**
     * A message the queue still holds but that is no longer the queue's —
     * put back in the batch meanwhile — is not replayed as a second copy.
     */
    public function testAMessageNoLongerDeferredHereIsNotReplayed(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test');
        (new SendBatchHandler())->handle([], $this->batchContext());
        $this->pdo->exec("UPDATE mass_mail_deliveries SET status = 'pending' WHERE id = {$deliveryId}");

        $this->laneExhausted = false;
        $this->drainNow();

        $this->assertSame([], $this->delivered);
        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($deliveryId)?->status);
    }

    /**
     * **A lookup that fails after the message left costs nothing of what
     * follows** (review of #1561). The message is out and settled; its
     * targets can no longer be read here, yet the mailing reaches
     * « Envoyé » and the sent line is still written — the follow-up falls
     * back rather than giving up, so nothing is left for the drain to
     * report as lost.
     */
    public function testALookupThatFailsAfterTheMessageLeftStillRecordsIt(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test', withAccount: true);
        (new SendBatchHandler())->handle([], $this->batchContext());
        $this->pdo->exec('DROP TABLE mass_mail_delivery_targets');

        $this->laneExhausted = false;
        $this->drainNow();

        $this->assertSame(['parent@exemple.test'], $this->delivered);
        $this->assertSame(Delivery::STATUS_SENT, $this->deliveries->findById($deliveryId)?->status);
        $this->assertSame(Email::STATUS_SENT, $this->emailStatus());
        $this->assertSame(1, $this->journalCount('delivery_sent'));
        $this->assertSame([], $this->journalContexts('mail_deferred_outcome_unreported'));
    }

    /**
     * **A follow-up that throws does not leave the mailing « Envoi en
     * cours »** (review of #1561): the abandoned message's own journal line
     * is refused, and the mailing still completes; the drain journals the
     * failure, naming the message by its origin.
     */
    public function testAFollowUpThatThrowsStillLetsTheMailingComplete(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test');
        (new SendBatchHandler())->handle([], $this->batchContext());
        $listener = new DeferredDeliveryListener();
        $this->pdo->exec(
            "CREATE TRIGGER refuse_abandoned BEFORE INSERT ON event_log WHEN NEW.event_type = 'delivery_abandoned' "
            . "BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        try {
            $listener->deferredMailAbandoned(
                DeferredDeliveryListener::originFor($deliveryId),
                \Core\Mail\Transport\DeferredMailAbandonment::Expired,
                $this->batchContext()
            );
            $this->fail('the follow-up failure must reach the drain');
        } catch (\PDOException) {
        }

        $this->assertSame(Delivery::STATUS_ERROR, $this->deliveries->findById($deliveryId)?->status);
        $this->assertSame(Email::STATUS_SENT, $this->emailStatus());
    }

    /**
     * **The way out of an outcome lost on the way back** (review of #1561).
     * The drain settled the queue's row but could not tell this module, so
     * the line still reads « en attente » with nothing waiting for it. The
     * tracking page says so and offers « Renvoyer », which is accepted —
     * while one the queue really holds stays refused.
     */
    public function testADeferredMessageTheQueueNoLongerHoldsCanBeResent(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test');
        (new SendBatchHandler())->handle([], $this->batchContext());
        $service = SendBatchHandler::buildMassMailService($this->batchContext());
        $this->assertFalse($service->getTrackingData($this->emailId)['messages'][0]['outcome_lost']);

        $this->pdo->exec('DELETE FROM mail_deferred_messages');

        $this->assertTrue($service->getTrackingData($this->emailId)['messages'][0]['outcome_lost']);
        $service->resendDelivery($deliveryId, null);
        $this->assertSame(Delivery::STATUS_PENDING, $this->deliveries->findById($deliveryId)?->status);
        $this->assertSame(1, $this->journalCount('delivery_resent'));
    }

    /** « Renvoyer » would queue a second copy of a message the core still holds. */
    public function testADeferredMessageCannotBeResentByHand(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test');
        (new SendBatchHandler())->handle([], $this->batchContext());

        $this->expectException(MassMailException::class);
        SendBatchHandler::buildMassMailService($this->batchContext())->resendDelivery($deliveryId, null);
    }

    /** An origin another sender wrote, or none at all, touches no message here. */
    public function testTheListenerIgnoresOriginsItDidNotWrite(): void
    {
        $deliveryId = $this->messageTo('parent@exemple.test');
        $this->deliveries->recordDeferred($deliveryId);
        $listener = new DeferredDeliveryListener();

        $listener->deferredMailSent('registration.request:' . $deliveryId, $this->batchContext());
        $listener->deferredMailAbandoned('mass_mail.delivery:0', DeferredMailAbandonment::Expired, $this->batchContext());

        $this->assertSame(Delivery::STATUS_DEFERRED, $this->deliveries->findById($deliveryId)?->status);
        $this->assertSame($deliveryId, DeferredDeliveryListener::deliveryIdOf(DeferredDeliveryListener::originFor($deliveryId)));
        $this->assertNull(DeferredDeliveryListener::deliveryIdOf('mass_mail.delivery:12abc'));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function messageTo(string $address, bool $withAccount = false): int
    {
        $targets = new TargetRepository($this->pdo);
        $targetId = $targets->create($this->emailId, $this->memberId, $this->scoutYearId);
        $deliveryId = $this->deliveries->create($this->emailId, $address);
        $this->deliveries->linkTargets($deliveryId, [$targetId]);

        if ($withAccount) {
            $this->accounts->create($address);
            $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                           email_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $this->memberId,
                $this->scoutYearId,
                $this->encryption->encrypt('Jean', 'member_years.first_name'),
                $this->encryption->encrypt('Dupont', 'member_years.last_name'),
                $this->encryption->encrypt($address, 'member_years.email'),
                $this->encryption->blindIndex(strtolower($address), 'email'),
            ]);
        }

        return $deliveryId;
    }

    /** The real service, the real queue, and a relay stand-in. */
    private function mailService(): MailService
    {
        $test = $this;
        $transport = new class ($test) implements MailTransportInterface {
            public function __construct(private DeferredDeliveryTest $test)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose = MailPurpose::Ordinary): void
            {
                $this->test->relay($mail);
            }
        };

        return new MailService(
            mode: 'local',
            fromAddress: 'unite@test.be',
            fromName: 'Test Unité',
            shortName: 'TU',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport,
            journal: new JournalService(new JournalRepository($this->pdo)),
            deferred: new DeferredMailQueue($this->queued, $this->settings)
        );
    }

    /** @internal called by the relay stand-in */
    public function relay(PHPMailer $mail): void
    {
        if ($this->laneExhausted) {
            throw new LaneExhaustedException(MailLane::Bulk, 'quota journalier atteint');
        }

        foreach ($mail->getToAddresses() as $to) {
            $this->delivered[] = (string) $to[0];
        }
    }

    private function batchContext(?BulkCadence $cadence = null): TaskContext
    {
        return $this->context($cadence, null);
    }

    /**
     * The drain's context, wired the way public/scheduler-bootstrap.php
     * wires it: the module's listener under its live enabled state.
     */
    private function drainNow(): void
    {
        $modules = $this->createStub(\Core\Module\ModuleManager::class);
        $modules->method('getEnabledModuleIds')->willReturn(['mass_mail']);
        $capabilities = new TaskCapabilities($modules);
        $capabilities->register(
            DeferredMailOutcomeListener::class,
            'mass_mail',
            static fn (): object => new DeferredDeliveryListener()
        );
        $this->pdo->exec("UPDATE mail_deferred_messages SET next_attempt_at = '2000-01-01 00:00:00'");

        (new DrainDeferredMailHandler($this->tempDir))->handle([], $this->context(null, $capabilities));
    }

    private function context(?BulkCadence $cadence, ?TaskCapabilities $capabilities): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->mailService(),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            $this->accounts,
            sys_get_temp_dir(),
            $this->notificationService(),
            $capabilities,
            $cadence
        );
    }

    /** A mailing lane whose one provider has spent its daily quota. */
    private function spentCadence(): BulkCadence
    {
        $providers = new MailProviderRepository($this->pdo);
        $chains = new LaneChainRepository($this->pdo);
        $counters = new SendCounterRepository($this->pdo);
        $providerId = $providers->create('Relais de test', 1, 2, 5);
        $chains->append(MailLane::Bulk, $providerId, true);
        $counters->increment($providerId, MailLane::Bulk);

        return new BulkCadence(
            new MailProviderDirectory(
                $providers,
                new ProviderConnections([ProviderConnections::prefixFor($providerId) . '_host' => 'smtp.test']),
                $this->settings
            ),
            $chains,
            $counters
        );
    }

    private function notificationService(): NotificationService
    {
        $notifications = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createStub(WebPush::class),
            $this->settings,
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->accounts
        );
        $notifications->registerModuleTypes('mass_mail', [[
            'id' => 'mass_mail.email_received', 'label' => 'Nouvel email reçu', 'description' => 'd',
            'group' => 'Emails groupés', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'off'],
        ]]);

        return $notifications;
    }

    private function emailStatus(): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM mass_mail_emails WHERE id = ?');
        $statement->execute([$this->emailId]);

        return (string) $statement->fetchColumn();
    }

    private function journalCount(string $type): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $statement->execute([$type]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function journalContexts(string $type): array
    {
        $statement = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id');
        $statement->execute([$type]);

        return array_map(
            static fn(mixed $json): array => (array) json_decode((string) $json, true),
            $statement->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function notificationCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM notifications WHERE type_id = 'mass_mail.email_received'"
        )->fetchColumn();
    }

    /** @return list<array<string, mixed>> the payloads of the batch runs still queued */
    private function queuedBatchPayloads(): array
    {
        return array_map(
            static fn(mixed $json): array => (array) json_decode((string) $json, true),
            $this->pdo->query(
                "SELECT payload FROM scheduled_actions
                 WHERE module_id = 'mass_mail' AND task_key = 'send_batch' AND status = 'pending'"
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
    }
}
