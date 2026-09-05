<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\UsageStats\Retention;
use Modules\UsageStats\Task\PurgePageViewsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\UsageStats\UsageStatsTestHelper;

/**
 * The nightly purge of the page-view counters.
 *
 * A retention rule that silently stops running is a data-protection
 * promise the site keeps making and no longer honours — and there is
 * nothing on any screen that would say so. This task was measured at 0 %.
 *
 * Two properties, and the second is the one a `finally` exists for: the
 * purge deletes only what is past the retention horizon, and it re-arms
 * itself whatever happened, because a purge that threw once must not be
 * a purge that stops.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgePageViewsHandlerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        UsageStatsTestHelper::createTables($this->pdo);
    }

    public function testCountersPastTheHorizonGoAndTheRestStay(): void
    {
        $cutoff = Retention::cutoffMonth(new \DateTimeImmutable('now'));
        $kept = $cutoff;
        $purged = $this->monthBefore($cutoff);

        $this->countFor($purged);
        $this->countFor($kept);

        (new PurgePageViewsHandler())->handle([], $this->context());

        $this->assertSame([$kept], $this->months());
    }

    public function testTheCutoffMonthItselfIsKept(): void
    {
        $cutoff = Retention::cutoffMonth(new \DateTimeImmutable('now'));
        $this->countFor($cutoff);

        (new PurgePageViewsHandler())->handle([], $this->context());

        $this->assertSame([$cutoff], $this->months(), 'The horizon is where the kept period starts.');
    }

    public function testWhatWasDeletedIsCountedInTheJournalWithNoRouteNamed(): void
    {
        $this->countFor($this->monthBefore(Retention::cutoffMonth(new \DateTimeImmutable('now'))));

        (new PurgePageViewsHandler())->handle([], $this->context());

        $entries = (new JournalRepository($this->pdo))->search();
        $this->assertCount(1, $entries);
        $this->assertSame('usage_page_views_purged', $entries[0]['event_type']);
        $this->assertStringContainsString('"count":1', (string) $entries[0]['context']);
    }

    public function testAPurgeWithNothingToDeleteWritesNoJournalLine(): void
    {
        (new PurgePageViewsHandler())->handle([], $this->context());

        $this->assertSame(
            [],
            (new JournalRepository($this->pdo))->search(),
            'A line every night about an empty purge is the flood, not the answer.'
        );
    }

    public function testItArmsTomorrowsRun(): void
    {
        // A queued successor is not yet a daily rhythm: a run armed for
        // ten seconds from now satisfies "there is one", and turns a
        // nightly purge into a loop. The window is what says "tomorrow".
        $notBefore = (new \DateTimeImmutable('+23 hours'))->format('Y-m-d H:i:s');
        $notAfter = (new \DateTimeImmutable('+25 hours'))->format('Y-m-d H:i:s');

        (new PurgePageViewsHandler())->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns());
        $runAt = $this->nextRunAt();
        $this->assertGreaterThanOrEqual($notBefore, $runAt);
        $this->assertLessThanOrEqual($notAfter, $runAt);
    }

    /**
     * The re-arm lives in a `finally` for this case: the day the delete
     * throws is precisely the day the task must still come back.
     */
    public function testItArmsTomorrowsRunEvenWhenThePurgeItselfFails(): void
    {
        $this->pdo->exec('DROP TABLE usage_page_views');

        try {
            (new PurgePageViewsHandler())->handle([], $this->context());
        } catch (\Throwable) {
            // The failure is the scenario; what it must not cost is the
            // next run.
        }

        $this->assertSame(1, $this->queuedRuns(), 'A purge that threw must not be a purge that stops.');
    }

    public function testTwoRunsInTheSameSecondArmOneSuccessor(): void
    {
        (new PurgePageViewsHandler())->handle([], $this->context());
        (new PurgePageViewsHandler())->handle([], $this->context());

        $this->assertSame(1, $this->queuedRuns());
    }

    // ── harness ───────────────────────────────────────────────────────

    private function monthBefore(string $month): string
    {
        return (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
    }

    private function countFor(string $month): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usage_page_views (month, route_pattern, module_id, audience, view_count)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$month, '/calendar', 'calendar', 'identified', 12]);
    }

    /**
     * @return array<int, string>
     */
    private function months(): array
    {
        return $this->pdo
            ->query('SELECT month FROM usage_page_views ORDER BY month')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function nextRunAt(): string
    {
        return (string) $this->pdo
            ->query("SELECT run_at FROM scheduled_actions WHERE task_key = 'purge_page_views'")
            ->fetchColumn();
    }

    private function queuedRuns(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_page_views'")
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
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }
}
