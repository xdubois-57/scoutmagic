<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\UpdateHistoryRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
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
}
