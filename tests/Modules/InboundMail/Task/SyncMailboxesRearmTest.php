<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Task\SyncMailboxesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What keeps the mailbox being read: the chain this task re-arms at the
 * end of every run.
 *
 * The interval and its clamping are pinned next door
 * (SyncMailboxesIntervalTest). What was covered nowhere is `handle()`
 * itself, and its own docblock records why that matters: the re-arm used
 * to be an unguarded `scheduleAfter()`, and on the reference installation
 * nine copies of this chain kept each other alive — the box was polled
 * nine times per pass, against a host that throttles clients which
 * reconnect too often.
 *
 * Three properties:
 *
 * - one run arms one successor, however many page loads run it at once;
 * - the successor is armed even when there is nothing to sync, because a
 *   chain that stops re-arming stops being a chain and the mailbox goes
 *   quiet with nothing to say so;
 * - the interval is re-read on every run, so a unit that shortens it does
 *   not wait out the old one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SyncMailboxesRearmTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            SyncMailboxesHandler::SETTING_INTERVAL_MINUTES,
            (string) SyncMailboxesHandler::DEFAULT_INTERVAL_MINUTES,
            'number',
            'Intervalle de relève',
            'Test.',
            'inbound_mail'
        );
    }

    public function testARunArmsTheNextOne(): void
    {
        (new SyncMailboxesHandler())->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns());
    }

    /**
     * The nine-copies case, said as a test: two page loads landing in the
     * same second must not leave two chains behind.
     */
    public function testTwoRunsInTheSameSecondLeaveOneChain(): void
    {
        (new SyncMailboxesHandler())->handle([], $this->context());
        (new SyncMailboxesHandler())->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns(), 'Two chains poll the mailbox twice per cycle, then four.');
    }

    public function testWithNoConsumerAtAllTheChainStillLives(): void
    {
        $registry = $this->createStub(MessageConsumerRegistry::class);
        $registry->method('hasConsumers')->willReturn(false);

        (new SyncMailboxesHandler($registry))->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns(), 'A module enabled later must find the chain still turning.');
    }

    /**
     * The chain is the only thing that ever re-arms it, so an interval
     * captured once would be the interval in force until the site
     * restarts.
     */
    public function testTheIntervalIsReadAgainOnEveryRun(): void
    {
        $this->settingService->set(SyncMailboxesHandler::SETTING_INTERVAL_MINUTES, '60', 'inbound_mail');
        (new SyncMailboxesHandler())->handle([], $this->context());
        $anHourOut = $this->nextRunAt();

        $this->pdo->exec('DELETE FROM scheduled_actions');
        $this->settingService->set(SyncMailboxesHandler::SETTING_INTERVAL_MINUTES, '5', 'inbound_mail');
        (new SyncMailboxesHandler())->handle([], $this->context());

        $this->assertLessThan(
            $anHourOut,
            $this->nextRunAt(),
            'A unit that shortens the interval should not wait out the old one.'
        );
    }

    /**
     * A number nobody meant — a stray keystroke turning 60 into 6000 —
     * must not become a mailbox that stops being read for four months.
     */
    public function testAnAbsurdIntervalIsClampedBeforeItReachesTheQueue(): void
    {
        $this->settingService->set(SyncMailboxesHandler::SETTING_INTERVAL_MINUTES, '600000', 'inbound_mail');

        (new SyncMailboxesHandler())->handle([], $this->context());

        $this->assertLessThanOrEqual(
            (new \DateTimeImmutable('+' . SyncMailboxesHandler::MAX_INTERVAL_MINUTES . ' minutes'))
                ->format('Y-m-d H:i:s'),
            $this->nextRunAt()
        );
    }

    // ── harness ───────────────────────────────────────────────────────

    private function nextRunAt(): string
    {
        return (string) $this->pdo
            ->query("SELECT run_at FROM scheduled_actions WHERE task_key = 'sync_mailboxes'")
            ->fetchColumn();
    }

    private function queuedRuns(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'sync_mailboxes'")
            ->fetchColumn();
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
