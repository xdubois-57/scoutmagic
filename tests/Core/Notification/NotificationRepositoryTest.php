<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Notification\NotificationRepository;
use Core\Security\EncryptionService;
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
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new NotificationRepository($this->pdo, $encryption);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindByUserAccountId(): void
    {
        $this->repository->create($this->userId, null, 'core.system', 'Titre', 'Corps', '/path');

        $records = $this->repository->findByUserAccountId($this->userId);

        $this->assertCount(1, $records);
        $this->assertSame('Titre', $records[0]->title);
        $this->assertSame('Corps', $records[0]->body);
        $this->assertSame('/path', $records[0]->url);
        $this->assertSame('core.system', $records[0]->typeId);
        $this->assertNull($records[0]->memberId);
        $this->assertFalse($records[0]->isRead());
    }

    public function testCreateStoresTheAboutMemberId(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->repository->create($this->userId, $memberId, 'core.system', 'Titre', 'Corps', null);

        $records = $this->repository->findByUserAccountId($this->userId);
        $this->assertSame($memberId, $records[0]->memberId);
    }

    public function testTitleAndBodyAreStoredEncryptedNotInPlaintext(): void
    {
        $this->repository->create($this->userId, null, 'core.system', 'Secret Title', 'Secret Body', null);

        $stmt = $this->pdo->prepare('SELECT title, body FROM notifications WHERE user_account_id = ?');
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertStringNotContainsString('Secret Title', (string) $row['title']);
        $this->assertStringNotContainsString('Secret Body', (string) $row['body']);
    }

    public function testCreateAllowsNullUrl(): void
    {
        $this->repository->create($this->userId, null, 'core.system', 'Titre', 'Corps', null);

        $records = $this->repository->findByUserAccountId($this->userId);
        $this->assertNull($records[0]->url);
    }

    public function testFindByUserAccountIdOrdersNewestFirst(): void
    {
        $this->repository->create($this->userId, null, 'core.system', 'First', 'Body', null);
        $this->repository->create($this->userId, null, 'core.system', 'Second', 'Body', null);

        $records = $this->repository->findByUserAccountId($this->userId);

        $this->assertSame('Second', $records[0]->title);
        $this->assertSame('First', $records[1]->title);
    }

    public function testFindByUserAccountIdRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->create($this->userId, null, 'core.system', "Notif {$i}", 'Body', null);
        }

        $records = $this->repository->findByUserAccountId($this->userId, 2);

        $this->assertCount(2, $records);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository->findById(999999));
    }

    public function testMarkReadSetsReadAt(): void
    {
        $id = $this->repository->create($this->userId, null, 'core.system', 'Titre', 'Corps', null);

        $this->repository->markRead($id);

        $records = $this->repository->findByUserAccountId($this->userId);
        $this->assertTrue($records[0]->isRead());
        $this->assertNotNull($records[0]->readAt);
    }

    public function testMarkAllReadForUserOnlyTouchesThatUser(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc2', 'idx2']);
        $otherUserId = (int) $this->pdo->lastInsertId();

        $this->repository->create($this->userId, null, 'core.system', 'Mine', 'Corps', null);
        $this->repository->create($otherUserId, null, 'core.system', 'Theirs', 'Corps', null);

        $this->repository->markAllReadForUser($this->userId);

        $this->assertTrue($this->repository->findByUserAccountId($this->userId)[0]->isRead());
        $this->assertFalse($this->repository->findByUserAccountId($otherUserId)[0]->isRead());
    }

    public function testCountUnread(): void
    {
        $id1 = $this->repository->create($this->userId, null, 'core.system', 'A', 'Corps', null);
        $this->repository->create($this->userId, null, 'core.system', 'B', 'Corps', null);
        $this->repository->markRead($id1);

        $this->assertSame(1, $this->repository->countUnread($this->userId));
    }

    public function testDeleteReadOlderThanOnlyDeletesReadRowsPastTheCutoff(): void
    {
        $oldRead = $this->repository->create($this->userId, null, 'core.system', 'Old read', 'Corps', null);
        $recentRead = $this->repository->create($this->userId, null, 'core.system', 'Recent read', 'Corps', null);
        $oldUnread = $this->repository->create($this->userId, null, 'core.system', 'Old unread', 'Corps', null);

        $this->pdo->prepare('UPDATE notifications SET read_at = ?, created_at = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', '2020-01-01 00:00:00', $oldRead]);
        $this->repository->markRead($recentRead);
        $this->pdo->prepare('UPDATE notifications SET created_at = ? WHERE id = ?')
            ->execute(['2020-01-01 00:00:00', $oldUnread]);

        $deleted = $this->repository->deleteReadOlderThan(new \DateTimeImmutable('2021-01-01'));

        $this->assertSame(1, $deleted);
        $remainingIds = array_map(fn($r) => $r->id, $this->repository->findByUserAccountId($this->userId));
        $this->assertContains($recentRead, $remainingIds);
        $this->assertContains($oldUnread, $remainingIds);
        $this->assertNotContains($oldRead, $remainingIds);
    }
}
