<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\AbandonedInstallSweeper;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AbandonedInstallSweeperTest extends TestCase
{
    private \PDO $pdo;
    private UpdateHistoryRepository $history;
    private SchedulerRepository $scheduler;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->history = new UpdateHistoryRepository($this->pdo);
        $this->scheduler = new SchedulerRepository($this->pdo);
    }

    private function agePending(int $id, int $minutes): void
    {
        $started = (new \DateTimeImmutable())->sub(new \DateInterval('PT' . $minutes . 'M'));
        $stmt = $this->pdo->prepare('UPDATE update_history SET started_at = ? WHERE id = ?');
        $stmt->execute([$started->format('Y-m-d H:i:s'), $id]);
    }

    private function queueInstallFor(int $historyId, string $reference): void
    {
        (new SchedulerService($this->scheduler))->scheduleAfter(
            'core',
            'install_update',
            0,
            ['history_id' => $historyId, 'download_url' => 'https://example.test/a.zip'],
            $reference
        );
    }

    public function testItClosesAPendingRowThatNoScheduledActionPointsAt(): void
    {
        $orphan = $this->history->create('1.0.0', 'dev-orphan', false, null);
        $this->agePending($orphan, 60);

        $this->assertSame(1, AbandonedInstallSweeper::sweep($this->history, $this->scheduler));

        $row = $this->history->findById($orphan);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('jamais démarré', (string) $row->errorMessage);
    }

    /**
     * The one case age alone would get catastrophically wrong: a release
     * install legitimately waiting for next Monday at 03:00 is pending for
     * days. Sweeping it would silently disable the weekly auto-update.
     */
    public function testItLeavesAPendingRowAScheduledActionIsStillWaitingFor(): void
    {
        $waiting = $this->history->create('1.0.0', '2.0.0', false, null);
        $this->queueInstallFor($waiting, 'scheduled_install');
        $this->agePending($waiting, 60 * 24 * 3);

        $this->assertSame(0, AbandonedInstallSweeper::sweep($this->history, $this->scheduler));
        $this->assertSame('pending', $this->history->findById($waiting)->status);
    }

    /**
     * create() and schedule() are two separate statements. A cron pass
     * landing between them must not sweep the row that is about to be
     * queued — hence the age floor, not just the "no task" predicate.
     */
    public function testItLeavesAJustCreatedRowAloneEvenWithNoTaskYet(): void
    {
        $fresh = $this->history->create('1.0.0', 'dev-fresh', false, null);

        $this->assertSame(0, AbandonedInstallSweeper::sweep($this->history, $this->scheduler));
        $this->assertSame('pending', $this->history->findById($fresh)->status);
    }

    /**
     * A task already claimed is about to move its own row out of 'pending'.
     */
    public function testItLeavesARowWhoseTaskIsAlreadyBeingProcessed(): void
    {
        $claimed = $this->history->create('1.0.0', 'dev-claimed', false, null);
        $this->queueInstallFor($claimed, 'push_install');
        $this->agePending($claimed, 60);
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'processing'");

        $this->assertSame(0, AbandonedInstallSweeper::sweep($this->history, $this->scheduler));
        $this->assertSame('pending', $this->history->findById($claimed)->status);
    }

    /**
     * A cancelled task is exactly the shape the superseded push left
     * behind on scoutmagic.be: the action is gone, the row is not.
     */
    public function testItClosesARowWhoseTaskWasCancelled(): void
    {
        $superseded = $this->history->create('1.0.0', 'dev-superseded', false, null);
        $this->queueInstallFor($superseded, 'push_install');
        $this->agePending($superseded, 60);
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'canceled'");

        $this->assertSame(1, AbandonedInstallSweeper::sweep($this->history, $this->scheduler));
        $this->assertSame('failed', $this->history->findById($superseded)->status);
    }

    public function testItNeverTouchesRowsThatAreNotPending(): void
    {
        $running = $this->history->create('1.0.0', 'dev-running', false, null);
        $this->history->setStatus($running, 'migrating');
        $this->agePending($running, 60);

        $this->assertSame(0, AbandonedInstallSweeper::sweep($this->history, $this->scheduler));
        $this->assertSame('migrating', $this->history->findById($running)->status);
    }
}
