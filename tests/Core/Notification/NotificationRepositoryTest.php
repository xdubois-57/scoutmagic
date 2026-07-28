<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Notification\NotificationRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class NotificationRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private NotificationRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new NotificationRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindByUserAccountId(): void
    {
        $this->repository->create($this->userId, 'Titre', 'Corps', '/path');

        $records = $this->repository->findByUserAccountId($this->userId);

        $this->assertCount(1, $records);
        $this->assertSame('Titre', $records[0]->title);
        $this->assertSame('Corps', $records[0]->body);
        $this->assertSame('/path', $records[0]->url);
        $this->assertFalse($records[0]->isRead);
    }

    public function testCreateAllowsNullUrl(): void
    {
        $this->repository->create($this->userId, 'Titre', 'Corps', null);

        $records = $this->repository->findByUserAccountId($this->userId);
        $this->assertNull($records[0]->url);
    }

    public function testFindByUserAccountIdOrdersNewestFirst(): void
    {
        $this->repository->create($this->userId, 'First', 'Body', null);
        $this->repository->create($this->userId, 'Second', 'Body', null);

        $records = $this->repository->findByUserAccountId($this->userId);

        $this->assertSame('Second', $records[0]->title);
        $this->assertSame('First', $records[1]->title);
    }

    public function testFindByUserAccountIdRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->create($this->userId, "Notif {$i}", 'Body', null);
        }

        $records = $this->repository->findByUserAccountId($this->userId, 2);

        $this->assertCount(2, $records);
    }

    public function testMarkReadUpdatesTheFlag(): void
    {
        $id = $this->repository->create($this->userId, 'Titre', 'Corps', null);

        $this->repository->markRead($id);

        $records = $this->repository->findByUserAccountId($this->userId);
        $this->assertTrue($records[0]->isRead);
    }
}
