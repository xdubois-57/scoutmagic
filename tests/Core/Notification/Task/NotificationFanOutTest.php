<?php

declare(strict_types=1);

namespace Tests\Core\Notification\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationService;
use Core\Notification\Task\SendNotificationEmailsHandler;
use Core\Notification\Task\SendNotificationsHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two handlers every notification in the application goes through.
 *
 * `NotificationService::dispatch()` never sends anything itself — a
 * mailing to a section is hundreds of SMTP round trips, and 200 members
 * × 2 devices is 400 HTTPS calls, none of which belong in the request
 * that triggered the notification. It queues, and these two drain the
 * queue: one for e-mail, one for push.
 *
 * Forty-four lines between them, and both were measured at 0 %. They are
 * the funnel: a defect here is a defect in every module's notifications
 * at once, and its shape is always the same one — **notifications that
 * quietly stop arriving**, which nobody reports because nobody knows
 * they were owed.
 *
 * Three properties, and the third is the one that bites:
 *
 * - a context without a notification service degrades to "not now" and
 *   leaves the rows unsent, so a later run still delivers them;
 * - what the time budget did not reach is rescheduled IMMEDIATELY, and
 *   exactly it — never dropped, never re-attempted whole;
 * - the e-mail budget is deliberately SHORTER than push's, because each
 *   message there is its own connection to a possibly remote SMTP server
 *   while push flushes one batched round trip at the end.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NotificationFanOutTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('site_name', 'Unité test', 'text', 'Nom', 'Test.');
        $this->settingService->register('base_url', 'https://exemple.be', 'text', 'URL', 'Test.');
    }

    // ── nothing to do ─────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function bothHalves(): array
    {
        return [
            'e-mail' => ['email'],
            'push' => ['push'],
        ];
    }

    /**
     * Cron running before the site has ever been reached over HTTP has no
     * notification service at all. The rows keep their unsent mark, so a
     * later run still delivers them — dropping them here would lose the
     * notification silently and for ever.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothHalves')]
    public function testWithoutANotificationServiceNothingIsSentAndNothingIsLost(string $half): void
    {
        $this->handle($half, ['notification_ids' => [1, 2, 3]], null);

        $this->assertSame([], $this->rescheduled($half), 'A dropped batch is a notification nobody ever gets.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function emptyBatches(): array
    {
        return [
            'no ids at all' => [[]],
            'an empty list' => [['notification_ids' => []]],
            'a list of nothing' => [['notification_ids' => null]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyBatches')]
    public function testAnEmptyBatchSchedulesNoFollowUp(array $payload): void
    {
        $service = $this->serviceAttempting([]);

        $this->handle('email', $payload, $service);

        $this->assertSame([], $this->rescheduled('email'));
    }

    // ── the budget ────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('bothHalves')]
    public function testABatchThatFinishesInsideTheBudgetSchedulesNoFollowUp(string $half): void
    {
        $service = $this->serviceAttempting([1, 2, 3]);

        $this->handle($half, ['notification_ids' => [1, 2, 3]], $service);

        $this->assertSame([], $this->rescheduled($half));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothHalves')]
    public function testWhatTheBudgetDidNotReachIsRescheduledAndOnlyThat(string $half): void
    {
        $service = $this->serviceAttempting([1, 2]);

        $this->handle($half, ['notification_ids' => [1, 2, 3, 4, 5]], $service);

        $followUps = $this->rescheduled($half);
        $this->assertCount(1, $followUps);
        $this->assertSame(
            [3, 4, 5],
            $followUps[0],
            'Re-attempting what was already attempted would send those notifications twice.'
        );
    }

    /**
     * `array_diff` preserves keys; a payload carrying `{2:3,3:4}` instead
     * of `[3,4]` survives one JSON round trip as an object and comes back
     * as something `array_map('intval', ...)` reads differently.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothHalves')]
    public function testTheFollowUpCarriesAPlainListTheNextRunCanRead(string $half): void
    {
        $service = $this->serviceAttempting([1, 2]);

        $this->handle($half, ['notification_ids' => [1, 2, 3, 4]], $service);

        $payload = $this->rescheduledPayload($half);
        $this->assertSame(
            '{"notification_ids":[3,4]}',
            $payload,
            'A follow-up the next run cannot read is a batch that never goes out.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothHalves')]
    public function testTheFollowUpRunsAtTheNextTickRatherThanLater(string $half): void
    {
        $service = $this->serviceAttempting([1]);

        $this->handle($half, ['notification_ids' => [1, 2]], $service);

        $runAt = (string) $this->pdo
            ->query("SELECT run_at FROM scheduled_actions WHERE task_key = '" . $this->taskKey($half) . "'")
            ->fetchColumn();

        $this->assertLessThanOrEqual(
            (new \DateTimeImmutable('+5 seconds'))->format('Y-m-d H:i:s'),
            $runAt,
            'A notification the budget cut short is late already; it does not wait.'
        );
    }

    /**
     * The asymmetry is deliberate and easy to "harmonise" away: push
     * flushes one batched curl_multi at the end, so its budget covers
     * mostly queueing, while every e-mail is its own connection to a
     * transport that may be a remote SMTP server.
     */
    public function testTheEmailBudgetIsShorterThanPushs(): void
    {
        $email = new \ReflectionClassConstant(SendNotificationEmailsHandler::class, 'TIME_BUDGET_SECONDS');
        $push = new \ReflectionClassConstant(SendNotificationsHandler::class, 'TIME_BUDGET_SECONDS');

        $this->assertLessThan($push->getValue(), $email->getValue());
    }

    // ── harness ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(string $half, array $payload, ?NotificationService $service): void
    {
        $handler = $half === 'email' ? new SendNotificationEmailsHandler() : new SendNotificationsHandler();
        $handler->handle($payload, $this->context($service));
    }

    private function taskKey(string $half): string
    {
        return $half === 'email' ? 'send_notification_emails' : 'send_notifications';
    }

    /**
     * The ids each follow-up carries, one entry per follow-up queued.
     *
     * @return array<int, array<int, int>>
     */
    private function rescheduled(string $half): array
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM scheduled_actions WHERE task_key = ?');
        $stmt->execute([$this->taskKey($half)]);

        return array_map(
            static function (string $payload): array {
                $decoded = json_decode($payload, true);

                return is_array($decoded) ? array_map('intval', (array) $decoded['notification_ids']) : [];
            },
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function rescheduledPayload(string $half): string
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM scheduled_actions WHERE task_key = ?');
        $stmt->execute([$this->taskKey($half)]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * A notification service that reports having attempted exactly these
     * ids — which is how a handler learns its budget ran out.
     *
     * @param array<int, int> $attempted
     */
    private function serviceAttempting(array $attempted): NotificationService
    {
        $service = $this->createStub(NotificationService::class);
        $service->method('sendEmailsForNotifications')->willReturn($attempted);
        $service->method('sendPushForNotifications')->willReturn($attempted);

        return $service;
    }

    private function context(?NotificationService $service): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settingService,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir(),
            $service
        );
    }
}
