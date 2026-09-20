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
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\DeferredMessage;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\Task\DrainDeferredMailHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
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
     * The same, when the send throws: the `finally` is the point.
     *
     * The neighbouring branch — a temporary file that cannot be WRITTEN,
     * which now stops the replay instead of silently sending a message
     * without its attachment — has no test here, and deliberately no fake
     * one: `sys_get_temp_dir()` is resolved once per process, so a test
     * cannot point it at an unwritable place after any other test has
     * touched it. What is pinned is the half that is honest to pin.
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

    // ── helpers ───────────────────────────────────────────────────────

    private function drain(): void
    {
        (new DrainDeferredMailHandler())->handle([], $this->context());
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->recordingMailService(),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    /**
     * A MailService that records the call instead of making it — and
     * reads the attachment back off disk, which is what lets the two
     * tests above assert that the file was really there and really went.
     */
    private function recordingMailService(): MailService
    {
        $mock = $this->createMock(MailService::class);
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
                bool $vouchesForRecipient = false
            ): bool {
                foreach ($attachments as $attachment) {
                    $this->temporaryPathsSeen[] = $attachment['path'];
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
                    'attachments' => $attachments,
                ];

                return true;
            }
        );

        return $mock;
    }

    /**
     * @param array<int, array{name: string, content: string}> $attachments
     */
    private function queue(
        MailLane $lane,
        string $nextAttemptAt,
        ?string $expiresAt = null,
        array $attachments = [],
        ?bool $vouchesForRecipient = null
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
                'attachments' => $attachments,
            ],
            'quota épuisé',
            $nextAttemptAt,
            $expiresAt ?? date('Y-m-d H:i:s', time() + 3600)
        );
    }

    private function minutesAgo(int $minutes): string
    {
        return date('Y-m-d H:i:s', time() - $minutes * 60);
    }
}
