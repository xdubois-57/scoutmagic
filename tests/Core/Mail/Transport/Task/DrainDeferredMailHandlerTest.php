<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\Transport\DeferredMailAbandonment;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\DeferredMessage;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\Task\DrainDeferredMailHandler;
use Core\Module\DeferredMailOutcomeListener;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskCapabilities;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The pass that actually sends what was put aside (D9, D16, D18).
 *
 * Everything else in the queue is bookkeeping; this is the half that
 * makes the bargain honest. A queue that fills and never drains is worse
 * than no queue at all — every sender was told their message left.
 *
 * What is pinned here is that draining goes through the ordinary send
 * path, that a message which leaves takes its body with it, and that an
 * attachment put back on disk does not stay there.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DrainDeferredMailHandlerTest extends TestCase
{
    private \PDO $pdo;
    private DeferredMailRepository $repository;
    private SettingService $settings;

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    /** @var array<int, string> */
    private array $refuse = [];

    /**
     * Addresses the site itself declines to write to on replay — the
     * suspended ones. Apart from `$refuse`, because the two settle
     * differently and that difference is what the tests below measure.
     *
     * @var list<string>
     */
    private array $suppress = [];

    private string $refusalReason = 'SMTP connect() failed.';

    /** @var array<int, string> */
    private array $temporaryPathsSeen = [];

    /** @var array<int, string> */
    private array $temporaryContentsSeen = [];

    /** @var array<int, int> the permission bits each attachment had when send() read it */
    private array $temporaryModesSeen = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new DeferredMailRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            DeferredMailQueue::SETTING_LIFETIME_HOURS,
            (string) DeferredMailQueue::DEFAULT_LIFETIME_HOURS,
            'number',
            'Durée de vie',
            'Test',
            null,
            '^[1-9][0-9]*$'
        );
        $this->settings->register(
            DeferredMailQueue::SETTING_ABANDONED_RETENTION_DAYS,
            (string) DeferredMailQueue::DEFAULT_ABANDONED_RETENTION_DAYS,
            'number',
            'Rétention',
            'Test',
            null,
            '^[1-9][0-9]*$'
        );
    }

    /**
     * **It replays `MailService::send()`.** That is the whole reason the
     * queue stores arguments rather than an assembled message: a message
     * that drains is signed, routed, counted and — on an installation
     * whose sandbox is armed — captured exactly like one that left the
     * first time. There is one way to send mail on this site.
     */
    public function testADueMessageGoesOutThroughTheOrdinarySendPath(): void
    {
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertSame('parent@exemple.test', $this->sent[0]['to']);
        $this->assertSame('Reçu de paiement', $this->sent[0]['subject']);
        $this->assertSame(MailPurpose::Ordinary, $this->sent[0]['purpose']);
    }

    /**
     * Nothing about a delivered message is worth keeping, and its body is
     * personal data with no further reason to exist (D18).
     */
    public function testAMessageThatLeavesIsDeletedRatherThanMarked(): void
    {
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $this->assertSame(
            '0',
            (string) $this->pdo->query('SELECT COUNT(*) FROM mail_deferred_messages')->fetchColumn()
        );
    }

    /** A message not yet due is left alone — that is what the delay is. */
    public function testAMessageThatIsNotDueYetIsNotTried(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: date('Y-m-d H:i:s', time() + 3600));

        $this->drain();

        $this->assertSame([], $this->sent);
        $this->assertSame(['bulk' => 1], $this->repository->pendingCountByLane());
    }

    /** It failed again, so it goes further out rather than away (D16). */
    public function testAFailureIsRescheduledFurtherOut(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5));
        $this->refuse = ['parent@exemple.test'];

        $this->drain();

        $waiting = $this->repository->due(10, '2099-01-01 00:00:00');
        $this->assertCount(1, $waiting);
        $this->assertSame(1, $waiting[0]->attempts);
        $this->assertGreaterThan(time(), (int) strtotime($waiting[0]->nextAttemptAt));
    }

    /**
     * Past its own deadline it is given up on, with its reason, and kept
     * for the Relance screen rather than sent (D9).
     */
    public function testAMessagePastItsDeadlineIsAbandonedRatherThanSent(): void
    {
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            expiresAt: $this->minutesAgo(1)
        );

        $this->drain();

        $this->assertSame([], $this->sent, 'An expired message is never given one more try.');
        $this->assertSame(1, $this->repository->countAbandoned());
    }

    /**
     * **A refusal on replay is heard the first time too.**
     *
     * The guard in `MailService` runs when a message is queued, and the
     * two failures need not be the same one: a message put aside during
     * an outage is replayed once the relay comes back, and if the address
     * was also wrong, THAT is when the 550 is first heard. Walking the
     * ladder from there would spend eight more attempts over a day
     * learning what the relay already said plainly.
     */
    public function testARecipientRefusalOnReplayIsAbandonedAtOnce(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5));
        $this->refuse = ['parent@exemple.test'];
        $this->refusalReason = 'SMTP Error: 550 5.1.1 Recipient address rejected';

        $this->drain();

        $this->assertSame([], $this->repository->due(10, '2099-01-01 00:00:00'), 'Nothing is waiting any more.');
        $this->assertSame(1, $this->repository->countAbandoned());
    }

    /**
     * **The flag has to survive the queue**, or the suppression gate reads
     * the wrong answer on every replay.
     *
     * `send()` decides whether to suppress from `$vouchesForRecipient`,
     * and the drain rebuilt its call from the stored payload — which did
     * not carry the flag. Every replay therefore read « false »: « the
     * site never chose this recipient », for a notification the site very
     * much chose. A message deferred while the address was still fine and
     * drained after two permanent bounces had blocked it went out anyway,
     * to somebody who had just been told the site had stopped writing to
     * them.
     */
    public function testAVouchedMessageIsStillVouchedForWhenItIsReplayed(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), vouchesForRecipient: true);

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertTrue($this->sent[0]['vouches'], 'the replay has to ask the same question the first send asked.');
    }

    /**
     * **And a row queued before the key existed reads false**, which is
     * the safe reading of the two: an authentication mail wrongly
     * suppressed locks somebody out of the site, where a message wrongly
     * delivered to a suspended address costs reputation (D9). The queue
     * drains in minutes, so this window is short.
     */
    public function testARowQueuedBeforeTheFlagExistedDoesNotVouch(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertFalse($this->sent[0]['vouches']);
    }

    /**
     * **The copy survives the queue too** (chantier #843, IT-06). A
     * message deferred with a `Cc:` must leave with it: replayed without,
     * it would reach the family alone, and the unit that asked to be told
     * would believe it had been.
     */
    public function testACopiedMessageIsReplayedWithItsCopy(): void
    {
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5), cc: ['unite@exemple.test']);

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertSame(['unite@exemple.test'], $this->sent[0]['cc']);
    }

    /** And a row queued before the key existed replays with no copy at all. */
    public function testARowQueuedBeforeCopiesWereCarriedHasNone(): void
    {
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertSame([], $this->sent[0]['cc']);
    }

    /**
     * A replay the site declines is abandoned, not retried: every later
     * pass would decline identically until somebody lifts the suspension,
     * so walking the ladder would spend a day's attempts learning what is
     * already known. Abandoned rather than deleted, so the Relance screen
     * can say why it never left.
     */
    public function testAReplayToASuspendedAddressIsAbandonedRatherThanRetried(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), vouchesForRecipient: true);
        $this->suppress = ['parent@exemple.test'];

        $this->drain();

        $this->assertSame([], $this->sent, 'nothing left.');
        $this->assertSame([], $this->repository->due(10, '2099-01-01 00:00:00'), 'and nothing is waiting either.');
        $this->assertSame(1, $this->repository->countAbandoned());
    }

    /** A provider failure on the same replay still walks the ladder. */
    public function testAProviderFailureOnReplayIsStillRescheduled(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5));
        $this->refuse = ['parent@exemple.test'];
        $this->refusalReason = 'SMTP connect() failed.';

        $this->drain();

        $this->assertCount(1, $this->repository->due(10, '2099-01-01 00:00:00'));
        $this->assertSame(0, $this->repository->countAbandoned());
    }

    /**
     * D18: the attachment is written out because `send()` takes paths,
     * and removed whatever the send did. A decrypted copy of somebody's
     * receipt left in the system temporary directory would undo the point
     * of encrypting the queue at all.
     */
    public function testACarriedAttachmentDoesNotStayOnDisk(): void
    {
        $this->queue(
            MailLane::Transactional,
            nextAttemptAt: $this->minutesAgo(5),
            attachments: [['name' => 'recu.pdf', 'content' => "%PDF-1.4\x00binaire"]]
        );

        $this->drain();

        $this->assertCount(1, $this->temporaryPathsSeen, 'The attachment must reach send() as a real file.');
        foreach ($this->temporaryPathsSeen as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    /**
     * The same, when the send throws: the `finally` is the point. The
     * neighbouring branch — a temporary file that cannot be written — is
     * the test after this one.
     */
    public function testAnAttachmentIsRemovedEvenWhenTheSendFails(): void
    {
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            attachments: [['name' => 'camp.pdf', 'content' => 'octets']]
        );
        $this->refuse = ['parent@exemple.test'];

        $this->drain();

        $this->assertCount(1, $this->temporaryPathsSeen);
        foreach ($this->temporaryPathsSeen as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    /**
     * **A full or refusing temporary directory stops the replay, and what
     * was already written goes with it.** `trySend()`'s `finally` cleans
     * up what `materialise()` RETURNED, and here it throws instead: a
     * first attachment left behind would be a decrypted receipt on disk
     * that nothing will ever remove. The directory is a stream wrapper
     * that takes the first file and refuses the second, because a suite
     * running as root cannot make a real directory refuse.
     */
    public function testATemporaryFileThatCannotBeWrittenRemovesTheOnesAlreadyWrittenAndSettlesTheMessage(): void
    {
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            attachments: [
                ['name' => 'un.pdf', 'content' => 'premier'],
                ['name' => 'deux.pdf', 'content' => 'second'],
            ]
        );
        RefusingSecondFileWrapper::reset();
        stream_wrapper_register('deferredtest', RefusingSecondFileWrapper::class);
        try {
            (new DrainDeferredMailHandler('deferredtest://dir'))->handle([], $this->context());
        } finally {
            stream_wrapper_unregister('deferredtest');
        }

        $this->assertSame(2, RefusingSecondFileWrapper::$opens, 'the second file was attempted and refused');
        $this->assertSame([], RefusingSecondFileWrapper::$files, 'the first one was removed, not left behind');
        $this->assertSame([], $this->sent, 'a message is never sent without the attachment it carries');
        $waiting = $this->repository->due(10, '2099-01-01 00:00:00');
        $this->assertCount(1, $waiting, 'it is settled like any failure: rescheduled, not lost');
        $this->assertSame(1, $waiting[0]->attempts);
    }

    /**
     * The file is created private, not narrowed afterwards: a chmod cannot
     * revoke a descriptor opened between the create and the chmod. With the
     * umask a hosting account usually has (022) a plain create is 0644, so
     * the mode seen at send time alone does not prove it — the creation does,
     * and the umask the process had must be handed back.
     */
    public function testTheTemporaryFileIsCreatedPrivateAndTheCallersUmaskIsRestored(): void
    {
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            attachments: [['name' => 'un.pdf', 'content' => 'premier']]
        );
        $directory = sys_get_temp_dir() . '/drain-deferred-umask-' . uniqid();
        mkdir($directory);
        $before = umask(0022);
        try {
            (new DrainDeferredMailHandler($directory))->handle([], $this->context());
            $after = umask($before);
        } finally {
            umask($before);
            rmdir($directory);
        }

        $this->assertSame(0022, $after, 'the process umask is what it was');
        $this->assertSame([0600], $this->temporaryModesSeen);
    }

    public function testTheAttachmentIsWrittenWithTheContentItCarriesIntoTheDirectoryItWasGiven(): void
    {
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            attachments: [['name' => 'un.pdf', 'content' => 'premier']]
        );
        $directory = sys_get_temp_dir() . '/drain-deferred-test-' . uniqid();
        mkdir($directory);
        try {
            (new DrainDeferredMailHandler($directory))->handle([], $this->context());

            $this->assertCount(1, $this->temporaryPathsSeen);
            $this->assertStringStartsWith($directory . '/', $this->temporaryPathsSeen[0]);
            $this->assertSame(['premier'], $this->temporaryContentsSeen);
            $this->assertSame([0600], $this->temporaryModesSeen, 'a decrypted receipt is private to the site');
            $this->assertSame([], glob($directory . '/*'), 'and removed once sent');
        } finally {
            rmdir($directory);
        }
    }

    /** Past the retention an abandoned message goes, body included (D18). */
    public function testAnAbandonedMessagePastItsRetentionIsPurged(): void
    {
        $id = $this->queue(MailLane::Bulk, nextAttemptAt: date('Y-m-d H:i:s', time() + 3600));
        $this->repository->abandon(
            $id,
            3,
            'expiré',
            date('Y-m-d H:i:s', time() - (DeferredMailQueue::DEFAULT_ABANDONED_RETENTION_DAYS + 1) * 86400)
        );

        $this->drain();

        $this->assertSame(0, $this->repository->countAbandoned());
    }

    /** The pass rearms itself, or the queue drains exactly once. */
    public function testThePassRearmsItself(): void
    {
        $this->drain();

        $next = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('core', DrainDeferredMailHandler::TASK_KEY, DrainDeferredMailHandler::REFERENCE);

        $this->assertIsArray($next);
        $this->assertSame('pending', $next['status']);
    }

    /** One line for the pass, never one per message (SECURITY.md §11). */
    public function testTheJournalCountsRatherThanNames(): void
    {
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $statement = $this->pdo->prepare(
            'SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['mail_deferred_drained']);
        $context = (string) $statement->fetchColumn();

        $this->assertStringNotContainsString('parent@exemple.test', $context);
        $this->assertStringContainsString('"sent":1', $context);
    }

    // ── Telling the sender (issue #1454) ──────────────────────────────

    /**
     * **A message that leaves says so to whoever sent it.** Before this the
     * drain deleted the row and counted it, and a mailing whose message it
     * was went on reading whatever it had written at deferral time.
     */
    public function testAMessageThatLeavesIsReportedToItsSender(): void
    {
        $this->listener = $this->recordingListener();
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:7');

        $this->drain();

        $this->assertSame(['sent mass_mail.delivery:7'], $this->listener->told);
    }

    /**
     * Each way of giving up reaches the sender with its own reason — the
     * deadline, the recipient's refusal, the suspended address — and a
     * message that is only rescheduled reaches nobody, since nothing has
     * been decided about it yet.
     */
    public function testEachAbandonmentReachesTheSenderWithItsReason(): void
    {
        $this->listener = $this->recordingListener();
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            expiresAt: $this->minutesAgo(1),
            origin: 'mass_mail.delivery:1'
        );
        $this->drain();

        $this->refuse = ['parent@exemple.test'];
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:2');
        $this->drain();

        $this->refusalReason = 'SMTP Error: 550 5.1.1 Recipient address rejected';
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:3');
        $this->drain();

        $this->refuse = [];
        $this->suppress = ['parent@exemple.test'];
        $this->queue(
            MailLane::Bulk,
            nextAttemptAt: $this->minutesAgo(5),
            vouchesForRecipient: true,
            origin: 'mass_mail.delivery:4'
        );
        $this->drain();

        $this->assertSame([
            'abandoned mass_mail.delivery:1 expired',
            'abandoned mass_mail.delivery:3 refused',
            'abandoned mass_mail.delivery:4 suppressed',
        ], $this->listener->told, 'delivery 2 was only rescheduled');
    }

    /**
     * A row that can no longer be read is abandoned by the repository, and
     * its origin — a plain column — still reaches the sender.
     */
    public function testAnUnreadableMessageIsReportedAsAbandoned(): void
    {
        $this->listener = $this->recordingListener();
        $id = $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:9');
        $this->pdo->prepare('UPDATE mail_deferred_messages SET payload_encrypted = ? WHERE id = ?')
            ->execute(['not a ciphertext', $id]);

        $this->drain();

        $this->assertSame(['abandoned mass_mail.delivery:9 unreadable'], $this->listener->told);
    }

    /** Nobody is told about a message whose sender did not name it. */
    public function testAMessageWithoutAnOriginReachesNoListener(): void
    {
        $this->listener = $this->recordingListener();
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertSame([], $this->listener->told);
    }

    /**
     * With the listening module disabled, a message that names its sender
     * is kept unsent (#1561 review): nobody can say its recipient has not
     * unsubscribed since. A message that names nobody still leaves.
     */
    public function testAMessageWhoseSenderIsDisabledIsKeptUnsent(): void
    {
        $this->listener = $this->recordingListener();
        $this->listenerModuleEnabled = false;
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:7');
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(4));

        $this->drain();

        $this->assertCount(1, $this->sent, 'only the message that names no sender leaves');
        $this->assertSame([], $this->listener->told);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mail_deferred_messages')->fetchColumn(),
            'the one its sender cannot vouch for waits in the queue'
        );
    }

    /**
     * **A listener in trouble cannot unsend a message.** It has left and
     * its row is gone; the failure to report it is journaled and the pass
     * goes on to the next message.
     */
    public function testAListenerThatThrowsDoesNotStopThePass(): void
    {
        $listener = $this->recordingListener();
        $listener->fails = true;
        $this->listener = $listener;
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(6), origin: 'mass_mail.delivery:7');
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:8');

        $this->drain();

        $this->assertCount(2, $this->sent);
        $this->assertSame(['sent mass_mail.delivery:7', 'sent mass_mail.delivery:8'], $listener->told);
        $this->assertSame(
            '0',
            (string) $this->pdo->query('SELECT COUNT(*) FROM mail_deferred_messages')->fetchColumn()
        );
        // The origin is in the line — an id, never an address — so the
        // message its sender still believes is waiting can be found.
        $this->assertSame(
            [
                ['origin' => 'mass_mail.delivery:7', 'error_class' => \RuntimeException::class],
                ['origin' => 'mass_mail.delivery:8', 'error_class' => \RuntimeException::class],
            ],
            array_map(
                static fn(mixed $json): mixed => json_decode((string) $json, true),
                $this->pdo->query(
                    "SELECT context FROM event_log WHERE event_type = 'mail_deferred_outcome_unreported' ORDER BY id"
                )->fetchAll(\PDO::FETCH_COLUMN)
            )
        );
    }

    /**
     * **The sender is asked before the replay** (issue #1454): a message it
     * withdraws — its recipient unsubscribed from it while it waited — is
     * never handed to the transport, is abandoned with the reason, and the
     * sender is told so; the message it still wants goes out as before.
     */
    public function testAMessageItsSenderWithdrawsIsAbandonedUnsent(): void
    {
        $listener = $this->recordingListener();
        $listener->withdraws = ['mass_mail.delivery:7' => DeferredMailAbandonment::Unsubscribed];
        $this->listener = $listener;
        $withdrawn = $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(6), origin: 'mass_mail.delivery:7');
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:8');

        $this->drain();

        $this->assertCount(1, $this->sent, 'only the one still wanted');
        $this->assertSame(['mass_mail.delivery:7', 'mass_mail.delivery:8'], $listener->asked);
        $this->assertSame(
            ['abandoned mass_mail.delivery:7 unsubscribed', 'sent mass_mail.delivery:8'],
            $listener->told
        );
        $row = $this->pdo->query(
            "SELECT status, attempts, last_reason FROM mail_deferred_messages WHERE id = {$withdrawn}"
        )
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('abandoned', $row['status']);
        $this->assertSame(0, (int) $row['attempts'], 'nothing was attempted');
        $this->assertSame(DeferredMailAbandonment::Unsubscribed->label(), $row['last_reason']);
    }

    /**
     * **A sender that cannot answer is not a yes.** The message is kept,
     * unsent, one pass further out and without spending an attempt; the
     * line says which message, never to whom; and the next pass, with the
     * sender back, sends it.
     */
    public function testAMessageWhoseSenderCannotBeAskedIsKeptUnsentForALaterPass(): void
    {
        $listener = $this->recordingListener();
        $listener->cannotAnswer = true;
        $this->listener = $listener;
        $id = $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:7');

        $this->drain();

        $this->assertSame([], $this->sent);
        $this->assertSame([], $listener->told);
        $row = $this->pdo->query(
            "SELECT status, attempts, next_attempt_at FROM mail_deferred_messages WHERE id = {$id}"
        )
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertGreaterThan(time(), strtotime((string) $row['next_attempt_at']), 'not due again in this pass');
        $line = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'mail_deferred_sender_unreachable'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('warning', $line['level']);
        $this->assertSame(
            ['origin' => 'mass_mail.delivery:7', 'error_class' => \RuntimeException::class],
            json_decode((string) $line['context'], true)
        );

        $listener->cannotAnswer = false;
        $this->pdo->exec("UPDATE mail_deferred_messages SET next_attempt_at = '2000-01-01 00:00:00'");
        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertSame(['sent mass_mail.delivery:7'], $listener->told);
    }

    /** A message without an origin is nobody's to withdraw, and nobody is asked. */
    public function testAMessageWithoutAnOriginIsNotAsked(): void
    {
        $listener = $this->recordingListener();
        $listener->cannotAnswer = true;
        $this->listener = $listener;
        $this->queue(MailLane::Transactional, nextAttemptAt: $this->minutesAgo(5));

        $this->drain();

        $this->assertCount(1, $this->sent);
        $this->assertSame([], $listener->asked);
    }

    /**
     * Which origins the queue will still try (issue #1454): pending ones
     * only — not an abandoned message, not one that left and was deleted,
     * not one it never held.
     */
    public function testTheQueueSaysWhichOriginsAreStillWaiting(): void
    {
        $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:1');
        $abandoned = $this->queue(MailLane::Bulk, nextAttemptAt: $this->minutesAgo(5), origin: 'mass_mail.delivery:2');
        $this->repository->abandon($abandoned, 1, 'délai');

        $this->assertSame(
            ['mass_mail.delivery:1'],
            $this->repository->waitingOrigins(['mass_mail.delivery:1', 'mass_mail.delivery:2', 'mass_mail.delivery:3'])
        );
        $this->assertSame([], $this->repository->waitingOrigins([]));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function drain(): void
    {
        (new DrainDeferredMailHandler())->handle([], $this->context());
    }

    /**
     * The listener a module registers to hear what became of its deferred
     * messages (issue #1454), wired the way public/scheduler-bootstrap.php
     * wires it — under the providing module's live enabled state — and
     * null when a test does not install one.
     */
    private ?DeferredMailOutcomeListener $listener = null;

    private bool $listenerModuleEnabled = true;

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $capabilities = null;
        if ($this->listener !== null) {
            $modules = $this->createStub(\Core\Module\ModuleManager::class);
            $modules->method('getEnabledModuleIds')->willReturn($this->listenerModuleEnabled ? ['mass_mail'] : []);
            $capabilities = new TaskCapabilities($modules);
            $listener = $this->listener;
            $capabilities->register(DeferredMailOutcomeListener::class, 'mass_mail', static fn (): object => $listener);
        }

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->recordingMailService(),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir(),
            null,
            $capabilities
        );
    }

    /**
     * A listener that writes down what it was told, and can be made to
     * throw.
     *
     * @return DeferredMailOutcomeListener&object{told: list<string>, asked: list<string>, fails: bool, withdraws: array<string, DeferredMailAbandonment>, cannotAnswer: bool}
     */
    private function recordingListener(): DeferredMailOutcomeListener
    {
        return new class implements DeferredMailOutcomeListener {
            /** @var list<string> */
            public array $told = [];
            public bool $fails = false;
            /** @var array<string, DeferredMailAbandonment> origin => why it is withdrawn */
            public array $withdraws = [];
            public bool $cannotAnswer = false;
            /** @var list<string> */
            public array $asked = [];

            public function deferredMailWithdrawn(string $origin, TaskContext $context): ?DeferredMailAbandonment
            {
                $this->asked[] = $origin;
                if ($this->cannotAnswer) {
                    throw new \RuntimeException('listener down');
                }

                return $this->withdraws[$origin] ?? null;
            }

            public function deferredMailSent(string $origin, TaskContext $context): void
            {
                $this->told[] = 'sent ' . $origin;
                if ($this->fails) {
                    throw new \RuntimeException('listener down');
                }
            }

            public function deferredMailAbandoned(
                string $origin,
                DeferredMailAbandonment $reason,
                TaskContext $context
            ): void {
                $this->told[] = 'abandoned ' . $origin . ' ' . $reason->value;
            }
        };
    }

    /**
     * A MailService that records the call instead of making it — and
     * reads the attachment back off disk, which is what lets the two
     * tests above assert that the file was really there and really went.
     */
    private function recordingMailService(): MailService
    {
        $mock = $this->createStub(MailService::class);
        // The drain sends through withoutDeferral(); on the real service
        // that is a queue-less clone, and what this double has to answer
        // is « the same service ». That the clone really drops the queue
        // is asserted where it belongs, on the real class
        // (Tests\Core\Mail\MailServiceDeferralTest).
        $mock->method('withoutDeferral')->willReturnSelf();
        $mock->method('send')->willReturnCallback(
            function (
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText = '',
                ?string $replyTo = null,
                array $attachments = [],
                ?string $fromAddressOverride = null,
                ?string $fromNameOverride = null,
                array $extraHeaders = [],
                MailPurpose $purpose = MailPurpose::Ordinary,
                bool $vouchesForRecipient = false,
                ?string $bulkRunReference = null,
                ?\Core\Mail\Feedback\Seed\SeedCopyContent $bulkCopy = null,
                string|array|null $cc = null
            ): bool {
                foreach ($attachments as $attachment) {
                    $this->temporaryPathsSeen[] = $attachment['path'];
                    $this->temporaryModesSeen[] = fileperms($attachment['path']) & 0777;
                    $this->temporaryContentsSeen[] = (string) file_get_contents($attachment['path']);
                    $this->assertFileExists($attachment['path']);
                }

                // The real `send()` decides this from the flag; the double
                // is told directly, so that what is under test here is the
                // flag SURVIVING the queue rather than the gate itself
                // (which `MailTransportSeamTest` already pins).
                if ($vouchesForRecipient && in_array($to, $this->suppress, true)) {
                    throw \Core\Mail\SuppressedRecipientException::blocked();
                }

                if (in_array($to, $this->refuse, true)) {
                    throw new \RuntimeException($this->refusalReason);
                }

                $this->sent[] = [
                    'to' => $to,
                    'subject' => $subject,
                    'purpose' => $purpose,
                    'vouches' => $vouchesForRecipient,
                    'cc' => $cc,
                    'attachments' => $attachments,
                ];

                return true;
            }
        );

        return $mock;
    }

    /**
     * @param array<int, array{name: string, content: string}> $attachments
     * @param list<string>|null $cc
     */
    private function queue(
        MailLane $lane,
        string $nextAttemptAt,
        ?string $expiresAt = null,
        array $attachments = [],
        ?bool $vouchesForRecipient = null,
        ?array $cc = null,
        ?string $origin = null
    ): int {
        return $this->repository->add(
            $lane,
            MailPurpose::Ordinary,
            [
                'to' => 'parent@exemple.test',
                'subject' => 'Reçu de paiement',
                'bodyHtml' => '<p>Bonjour</p>',
                'bodyText' => 'Bonjour',
                'replyTo' => null,
                'fromAddressOverride' => null,
                'fromNameOverride' => null,
                'extraHeaders' => [],
                // Null leaves the key out entirely, which is what a row
                // queued before this key existed looks like.
                ...($vouchesForRecipient === null ? [] : ['vouchesForRecipient' => $vouchesForRecipient]),
                // Same: null is a row queued before copies were carried.
                ...($cc === null ? [] : ['cc' => $cc]),
                'attachments' => $attachments,
            ],
            'quota épuisé',
            $nextAttemptAt,
            $expiresAt ?? date('Y-m-d H:i:s', time() + 3600),
            $origin
        );
    }

    private function minutesAgo(int $minutes): string
    {
        return date('Y-m-d H:i:s', time() - $minutes * 60);
    }
}

/**
 * A directory that takes the first file and refuses the second, standing in
 * for a disk that fills up part-way through a message's attachments.
 */
final class RefusingSecondFileWrapper
{
    /** @var array<string, string> */
    public static array $files = [];
    public static int $opens = 0;

    /** @var resource|null */
    public $context;
    private string $path = '';

    public static function reset(): void
    {
        self::$files = [];
        self::$opens = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (++self::$opens >= 2) {
            return false;
        }

        $this->path = $path;
        self::$files[$path] = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$files[$this->path] .= $data;

        return strlen($data);
    }

    public function stream_close(): void
    {
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }
}
