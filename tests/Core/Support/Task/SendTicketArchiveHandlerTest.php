<?php

declare(strict_types=1);

namespace Tests\Core\Support\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Support\Task\SendTicketArchiveHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The task that waits for a ticket's diagnostic archive.
 *
 * Opening a support ticket transmits the archive without asking a second
 * time, so « on est sûr de tout recevoir ». Generating one takes seconds
 * to minutes and belongs nowhere near a form submission: the controller
 * queues the generation, and this handler waits for its result.
 *
 * **Waiting is the whole behaviour, and waiting is what goes wrong.** A
 * task that re-queues itself while a condition is unmet has exactly two
 * failure modes, and both are silent:
 *
 * - it gives up too early, and the ticket arrives with nothing attached;
 * - it never gives up, and becomes a task nobody notices has been failing
 *   since March.
 *
 * Measured at 0 %: nothing in the repository ran a line of it. The bound
 * below — ten attempts a minute apart, then one journal line saying the
 * archive is still to send by hand — is pinned here so that neither end
 * of it can be tuned away by accident.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SendTicketArchiveHandlerTest extends TestCase
{
    private const REFERENCE = 'SUP-2026-0042';

    private \PDO $pdo;
    private SettingService $settingService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
    }

    // ── nothing to wait for ───────────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function payloadsWithoutAReference(): array
    {
        return [
            'no reference at all' => [[]],
            'an empty one' => [['reference' => '']],
            'not a string' => [['reference' => 42]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payloadsWithoutAReference')]
    public function testWithoutATicketToAttachToTheTaskEndsQuietly(array $payload): void
    {
        (new SendTicketArchiveHandler())->handle($payload, $this->context());

        $this->assertSame([], $this->retries(), 'There is nothing to come back for.');
        $this->assertSame([], $this->journal());
    }

    // ── waiting ───────────────────────────────────────────────────────

    public function testWhileNoPackageExistsTheTaskComesBackForIt(): void
    {
        (new SendTicketArchiveHandler())->handle(['reference' => self::REFERENCE], $this->context());

        $retries = $this->retries();
        $this->assertCount(1, $retries);
        $this->assertSame(self::REFERENCE, $retries[0]['reference']);
        $this->assertSame(2, $retries[0]['attempt'], 'Each look forward is one attempt further.');
    }

    public function testItComesBackAMinuteLaterRatherThanImmediately(): void
    {
        // Derived from the constant, not from the number in it: a delay
        // raised to an hour is as much a regression as one lowered to
        // zero, and only an upper bound catches the first.
        $notAfter = (new \DateTimeImmutable(
            '+' . (SendTicketArchiveHandler::RETRY_SECONDS + 30) . ' seconds'
        ))->format('Y-m-d H:i:s');

        (new SendTicketArchiveHandler())->handle(['reference' => self::REFERENCE], $this->context());

        $runAt = (string) $this->pdo
            ->query("SELECT run_at FROM scheduled_actions WHERE task_key = 'send_ticket_archive'")
            ->fetchColumn();

        $this->assertGreaterThan(
            (new \DateTimeImmutable('+30 seconds'))->format('Y-m-d H:i:s'),
            $runAt,
            'A generation takes minutes; looking again at once only spends the queue.'
        );
        $this->assertLessThanOrEqual($notAfter, $runAt);
    }

    public function testAnAttemptItCannotReadCountsAsTheFirst(): void
    {
        (new SendTicketArchiveHandler())->handle(
            ['reference' => self::REFERENCE, 'attempt' => 'beaucoup'],
            $this->context()
        );

        $this->assertSame(2, $this->retries()[0]['attempt'], 'An unreadable count must not become an endless one.');
    }

    public function testTheAttemptCountCarriesForward(): void
    {
        (new SendTicketArchiveHandler())->handle(
            ['reference' => self::REFERENCE, 'attempt' => 7],
            $this->context()
        );

        $this->assertSame(8, $this->retries()[0]['attempt']);
    }

    // ── giving up, out loud ───────────────────────────────────────────

    public function testAfterTheLastAttemptItStopsComingBack(): void
    {
        (new SendTicketArchiveHandler())->handle(
            ['reference' => self::REFERENCE, 'attempt' => SendTicketArchiveHandler::MAX_ATTEMPTS],
            $this->context()
        );

        $this->assertSame([], $this->retries(), 'A task that retries for ever is one nobody notices has failed.');
    }

    public function testGivingUpSaysSoLoudlyEnoughToBeActedOn(): void
    {
        (new SendTicketArchiveHandler())->handle(
            ['reference' => self::REFERENCE, 'attempt' => SendTicketArchiveHandler::MAX_ATTEMPTS],
            $this->context()
        );

        $entries = $this->journal();
        $this->assertCount(1, $entries);
        $this->assertSame('support_ticket_archive_not_sent', $entries[0]['event_type']);
        $this->assertSame('warning', $entries[0]['level'], 'An archive nobody sent is not routine information.');
        $this->assertStringContainsString(
            self::REFERENCE,
            (string) $entries[0]['context'],
            'The reference is what lets somebody finish the job by hand.'
        );
    }

    /**
     * Ten attempts a minute apart is roughly ten minutes of waiting — long
     * enough for a slow generation, short enough that the ticket is still
     * being read when the answer comes.
     */
    public function testTheWaitIsBoundedToAboutTenMinutes(): void
    {
        $total = SendTicketArchiveHandler::MAX_ATTEMPTS * SendTicketArchiveHandler::RETRY_SECONDS;

        $this->assertGreaterThanOrEqual(5 * 60, $total);
        $this->assertLessThanOrEqual(20 * 60, $total);
    }

    public function testEveryAttemptBeforeTheLastStaysSilent(): void
    {
        for ($attempt = 1; $attempt < SendTicketArchiveHandler::MAX_ATTEMPTS; $attempt++) {
            $this->pdo->exec('DELETE FROM scheduled_actions');
            (new SendTicketArchiveHandler())->handle(
                ['reference' => self::REFERENCE, 'attempt' => $attempt],
                $this->context()
            );
        }

        $this->assertSame([], $this->journal(), 'Still waiting is not yet news.');
    }

    // ── harness ───────────────────────────────────────────────────────

    /**
     * @return array<int, array{reference: string, attempt: int}>
     */
    private function retries(): array
    {
        $rows = $this->pdo
            ->query("SELECT payload FROM scheduled_actions WHERE task_key = 'send_ticket_archive'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        return array_map(
            static function (string $payload): array {
                $decoded = json_decode($payload, true);

                return [
                    'reference' => (string) ($decoded['reference'] ?? ''),
                    'attempt' => (int) ($decoded['attempt'] ?? 0),
                ];
            },
            $rows
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journal(): array
    {
        return (new JournalRepository($this->pdo))->search();
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settingService,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }
}
