<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\UpdateHistoryRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class UpdateHistoryRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private UpdateHistoryRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new UpdateHistoryRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateDefaultsToPendingStatus(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $history = $this->repository->findById($id);
        $this->assertSame('pending', $history->status);
        $this->assertSame('1.0.0', $history->versionFrom);
        $this->assertSame('1.1.0', $history->versionTo);
        $this->assertFalse($history->dependenciesChanged);
        $this->assertSame($this->userId, $history->requestedBy);
    }

    public function testCreateStoresDependenciesChangedFlag(): void
    {
        $id = $this->repository->create('1.0.0', '2.0.0', true, $this->userId);

        $this->assertTrue($this->repository->findById($id)->dependenciesChanged);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    public function testSetStatusUpdatesStatus(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->repository->setStatus($id, 'downloading');

        $this->assertSame('downloading', $this->repository->findById($id)->status);
    }

    public function testSetBackupIdLinksTheSafetyBackup(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->repository->setBackupId($id, 42);

        $this->assertSame(42, $this->repository->findById($id)->backupId);
    }

    public function testMarkCompletedSetsStatusAndTimestamp(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->repository->markCompleted($id);

        $history = $this->repository->findById($id);
        $this->assertSame('completed', $history->status);
        $this->assertNotNull($history->completedAt);
    }

    public function testMarkFailedSetsErrorMessage(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->repository->markFailed($id, 'Le téléchargement a échoué.');

        $history = $this->repository->findById($id);
        $this->assertSame('failed', $history->status);
        $this->assertSame('Le téléchargement a échoué.', $history->errorMessage);
        $this->assertNotNull($history->completedAt);
    }

    public function testMarkRolledBackSetsStatusAndError(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->repository->markRolledBack($id, 'La migration a échoué.');

        $history = $this->repository->findById($id);
        $this->assertSame('rolled_back', $history->status);
        $this->assertSame('La migration a échoué.', $history->errorMessage);
    }

    public function testFindRecentOrdersNewestFirstAndRespectsLimit(): void
    {
        $first = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $second = $this->repository->create('1.1.0', '1.2.0', false, $this->userId);
        $third = $this->repository->create('1.2.0', '1.3.0', false, $this->userId);

        $recent = $this->repository->findRecent(2);

        $this->assertCount(2, $recent);
        $this->assertSame($third, $recent[0]->id);
        $this->assertSame($second, $recent[1]->id);
    }

    // --- findInProgress() ---

    public function testFindInProgressReturnsNullWhenNothingIsRunning(): void
    {
        $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        // Stays 'pending' — never considered "in progress".

        $this->assertNull($this->repository->findInProgress());
    }

    public function testFindInProgressReturnsARunningUpdate(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($id, 'downloading');

        $inProgress = $this->repository->findInProgress();

        $this->assertNotNull($inProgress);
        $this->assertSame($id, $inProgress->id);
    }

    public function testFindInProgressIgnoresTerminalStatuses(): void
    {
        $completed = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->markCompleted($completed);

        $failed = $this->repository->create('1.1.0', '1.2.0', false, $this->userId);
        $this->repository->markFailed($failed, 'oops');

        $this->assertNull($this->repository->findInProgress());
    }

    public function testFindInProgressAutoFailsAStaleRowAndReturnsNull(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($id, 'downloading');
        $this->ageProgress($id, 20);

        $result = $this->repository->findInProgress();

        $this->assertNull($result);
        $history = $this->repository->findById($id);
        $this->assertSame('failed', $history->status);
        $this->assertNotNull($history->errorMessage);
        $this->assertNotNull($history->completedAt);
    }

    public function testFindInProgressDoesNotFailARowThatIsWithinTheStaleThreshold(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($id, 'downloading');
        $this->ageProgress($id, 5);

        $result = $this->repository->findInProgress();

        $this->assertNotNull($result);
        $this->assertSame($id, $result->id);
    }

    /**
     * The watchdog measures silence, not duration.
     *
     * An update is several steps in several processes by design, and a
     * schema change big enough to need many migration slices is a normal,
     * healthy update that simply outlasts one threshold. Counting from
     * started_at declared six of those abandoned on scoutmagic.be in
     * forty-eight hours, every one of them mid-flight.
     */
    public function testAnUpdateStillMakingProgressSurvivesPastTheThreshold(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($id, 'migrating');
        $this->ageStartedAt($id, 40);
        $this->ageProgress($id, 1);

        $result = $this->repository->findInProgress();

        $this->assertNotNull($result, 'an update that moved a minute ago is not abandoned');
        $this->assertSame($id, $result->id);
        $this->assertSame('migrating', $this->repository->findById($id)->status);
    }

    public function testAStatusChangeCountsAsProgress(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->ageProgress($id, 40);
        // The next step of the same update, arriving late but arriving.
        $this->repository->setStatus($id, 'migrating');

        $this->assertNotNull($this->repository->findInProgress());
    }

    public function testTouchKeepsALongMigrationAlive(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($id, 'migrating');
        $this->ageProgress($id, 40);

        $this->repository->touch($id);

        $this->assertNotNull($this->repository->findInProgress());
    }

    public function testTouchInProgressStampsEveryRunningUpdateAndLeavesQueuedOnesAlone(): void
    {
        $running = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($running, 'migrating');
        $this->ageProgress($running, 40);

        // A weekly automatic install waiting days for its slot: 'pending'
        // is not "in progress", and an unrelated migration must not
        // refresh it.
        $queued = $this->repository->create('1.1.0', '1.2.0', false, $this->userId);
        $this->ageProgress($queued, 40);

        $this->repository->touchInProgress();

        $this->assertNotNull($this->repository->findInProgress());
        $this->assertSame(
            $this->progressAt($queued),
            (new \DateTimeImmutable('-40 minutes'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * A row written before the column existed has no heartbeat at all, and
     * the moment it began is the only thing left to measure from —
     * anything else would leave MaintenanceGate holding every visitor
     * behind an install that died during the upgrade that added it.
     */
    public function testARowWithNoHeartbeatFallsBackToStartedAt(): void
    {
        $id = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($id, 'installing');
        $this->ageStartedAt($id, 40);
        $this->clearProgressAt($id);

        $this->assertNull($this->repository->findInProgress());
        $this->assertSame('failed', $this->repository->findById($id)->status);
    }

    // --- markOtherInProgressAsFailed() ---

    public function testMarkOtherInProgressAsFailedLeavesTheGivenRowAlone(): void
    {
        $keep = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->setStatus($keep, 'installing');

        $this->repository->markOtherInProgressAsFailed($keep);

        $this->assertSame('installing', $this->repository->findById($keep)->status);
    }

    public function testMarkOtherInProgressAsFailedFailsEveryOtherNonTerminalRow(): void
    {
        $stuck1 = $this->repository->create('1.0.0', 'dev-aaa', false, null);
        $this->repository->setStatus($stuck1, 'downloading');
        $stuck2 = $this->repository->create('1.0.0', 'dev-bbb', false, null);
        $this->repository->setStatus($stuck2, 'migrating');
        $newest = $this->repository->create('1.0.0', 'dev-ccc', false, null);
        $this->repository->setStatus($newest, 'installing');

        $this->repository->markOtherInProgressAsFailed($newest);

        $this->assertSame('failed', $this->repository->findById($stuck1)->status);
        $this->assertSame('failed', $this->repository->findById($stuck2)->status);
        $this->assertSame('installing', $this->repository->findById($newest)->status);
    }

    public function testMarkOtherInProgressAsFailedLeavesTerminalRowsAlone(): void
    {
        $completed = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->markCompleted($completed);
        $current = $this->repository->create('1.1.0', '1.2.0', false, $this->userId);
        $this->repository->setStatus($current, 'backing_up');

        $this->repository->markOtherInProgressAsFailed($current);

        $this->assertSame('completed', $this->repository->findById($completed)->status);
    }

    public function testFindLastCompletedIsNullWhenNothingEverSucceeded(): void
    {
        $this->repository->create('1.0.0', '1.1.0', false, $this->userId);

        $this->assertNull($this->repository->findLastCompleted());
    }

    /**
     * The point of the health signal: a run that failed leaves the site on
     * the version it already had, so counting it as "last updated" would
     * report the opposite of the truth.
     */
    public function testFindLastCompletedIgnoresFailedAndRolledBackRuns(): void
    {
        $failed = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->markFailed($failed, 'boom');
        $rolledBack = $this->repository->create('1.0.0', '1.2.0', false, $this->userId);
        $this->repository->markRolledBack($rolledBack, 'reverted');

        $this->assertNull($this->repository->findLastCompleted());
    }

    public function testFindLastCompletedReturnsTheMostRecentlyFinishedRun(): void
    {
        $older = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $this->repository->markCompleted($older);
        $this->ageCompletedAt($older, 5000);

        $newer = $this->repository->create('1.1.0', '1.2.0', false, $this->userId);
        $this->repository->markCompleted($newer);

        $last = $this->repository->findLastCompleted();
        $this->assertNotNull($last);
        $this->assertSame('1.2.0', $last->versionTo);
    }

    /**
     * Ordered by completed_at, not started_at — a long update that began
     * before a short later one still finished after it, and it is the
     * finishing that put the site on that version.
     */
    public function testFindLastCompletedOrdersByCompletionNotByStart(): void
    {
        $startedFirst = $this->repository->create('1.0.0', '1.1.0', false, $this->userId);
        $startedSecond = $this->repository->create('1.0.0', '1.2.0', false, $this->userId);

        // The one that started second finishes first.
        $this->repository->markCompleted($startedSecond);
        $this->ageCompletedAt($startedSecond, 60);
        $this->repository->markCompleted($startedFirst);

        $last = $this->repository->findLastCompleted();
        $this->assertNotNull($last);
        $this->assertSame('1.1.0', $last->versionTo);
    }

    private function ageStartedAt(int $id, int $minutesAgo): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET started_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable("-{$minutesAgo} minutes"))->format('Y-m-d H:i:s'), $id]);
    }

    private function ageProgress(int $id, int $minutesAgo): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET progress_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable("-{$minutesAgo} minutes"))->format('Y-m-d H:i:s'), $id]);
    }

    private function clearProgressAt(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET progress_at = NULL WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function progressAt(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT progress_at FROM update_history WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function ageCompletedAt(int $id, int $minutesAgo): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET completed_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable("-{$minutesAgo} minutes"))->format('Y-m-d H:i:s'), $id]);
    }
}
