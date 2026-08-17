<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\BackupRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BackupRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BackupRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new BackupRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateDefaultsToPendingStatus(): void
    {
        $id = $this->repository->create('database', $this->userId);

        $backup = $this->repository->findById($id);
        $this->assertSame('pending', $backup->status);
        $this->assertSame('database', $backup->type);
        $this->assertSame($this->userId, $backup->requestedBy);
    }

    public function testCreateAllowsNullRequestedBy(): void
    {
        $id = $this->repository->create('auto_update', null);

        $backup = $this->repository->findById($id);
        $this->assertNull($backup->requestedBy);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    public function testMarkInProgressUpdatesStatus(): void
    {
        $id = $this->repository->create('database', $this->userId);

        $this->repository->markInProgress($id);

        $this->assertSame('in_progress', $this->repository->findById($id)->status);
    }

    public function testMarkCompletedSetsFileIdsAndTimestamp(): void
    {
        $id = $this->repository->create('full_config', $this->userId);

        $this->repository->markCompleted($id, 10, 11);

        $backup = $this->repository->findById($id);
        $this->assertSame('completed', $backup->status);
        $this->assertSame(10, $backup->fileId);
        $this->assertSame(11, $backup->dbDumpFileId);
        $this->assertNotNull($backup->completedAt);
    }

    public function testMarkFailedSetsErrorMessage(): void
    {
        $id = $this->repository->create('database', $this->userId);

        $this->repository->markFailed($id, 'mysqldump not found');

        $backup = $this->repository->findById($id);
        $this->assertSame('failed', $backup->status);
        $this->assertSame('mysqldump not found', $backup->errorMessage);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create('database', $this->userId);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testFindRecentOrdersNewestFirstAndRespectsLimit(): void
    {
        $first = $this->repository->create('database', $this->userId);
        $second = $this->repository->create('database', $this->userId);
        $third = $this->repository->create('database', $this->userId);

        $recent = $this->repository->findRecent(2);

        $this->assertCount(2, $recent);
        $this->assertSame($third, $recent[0]->id);
        $this->assertSame($second, $recent[1]->id);
    }

    public function testFindBeyondReturnsEverythingPastTheKeepCount(): void
    {
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->repository->create('database', $this->userId);
        }

        $beyond = $this->repository->findBeyond(5);

        $this->assertCount(2, $beyond);
        // The two oldest (first created) are the ones beyond the 5 kept.
        $beyondIds = array_map(fn($b) => $b->id, $beyond);
        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], $beyondIds);
    }

    public function testFindBeyondReturnsEmptyWhenUnderTheLimit(): void
    {
        $this->repository->create('database', $this->userId);
        $this->repository->create('database', $this->userId);

        $this->assertSame([], $this->repository->findBeyond(5));
    }
}
