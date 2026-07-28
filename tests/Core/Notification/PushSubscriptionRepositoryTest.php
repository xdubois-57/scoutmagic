<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Notification\PushSubscriptionRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class PushSubscriptionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PushSubscriptionRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new PushSubscriptionRepository($this->pdo, $encryption);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindByUserAccountIdRoundTripsEncryptedKeys(): void
    {
        $this->repository->create($this->userId, 'https://push.example/device', 'secret-auth-key', 'secret-p256dh-key');

        $subscriptions = $this->repository->findByUserAccountId($this->userId);

        $this->assertCount(1, $subscriptions);
        $this->assertSame('https://push.example/device', $subscriptions[0]->endpoint);
        $this->assertSame('secret-auth-key', $subscriptions[0]->authKey);
        $this->assertSame('secret-p256dh-key', $subscriptions[0]->p256dhKey);
    }

    public function testKeysAreStoredEncryptedNotInPlaintext(): void
    {
        $this->repository->create($this->userId, 'https://push.example/device', 'super-secret-auth', 'super-secret-p256dh');

        $stmt = $this->pdo->prepare('SELECT auth_key, p256dh_key FROM push_subscriptions WHERE user_account_id = ?');
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertStringNotContainsString('super-secret-auth', (string) $row['auth_key']);
        $this->assertStringNotContainsString('super-secret-p256dh', (string) $row['p256dh_key']);
    }

    public function testFindByEndpointReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repository->findByEndpoint($this->userId, 'https://push.example/missing'));
    }

    public function testFindByEndpointReturnsMatchingSubscription(): void
    {
        $this->repository->create($this->userId, 'https://push.example/device', 'auth', 'p256dh');

        $found = $this->repository->findByEndpoint($this->userId, 'https://push.example/device');

        $this->assertNotNull($found);
        $this->assertSame($this->userId, $found->userAccountId);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create($this->userId, 'https://push.example/device', 'auth', 'p256dh');

        $this->repository->delete($id);

        $this->assertSame([], $this->repository->findByUserAccountId($this->userId));
    }

    public function testDeleteByEndpointOnlyRemovesThatDevice(): void
    {
        $this->repository->create($this->userId, 'https://push.example/keep', 'auth1', 'p256dh1');
        $this->repository->create($this->userId, 'https://push.example/drop', 'auth2', 'p256dh2');

        $this->repository->deleteByEndpoint($this->userId, 'https://push.example/drop');

        $remaining = $this->repository->findByUserAccountId($this->userId);
        $this->assertCount(1, $remaining);
        $this->assertSame('https://push.example/keep', $remaining[0]->endpoint);
    }

    public function testDeleteAllForUserRemovesEveryDevice(): void
    {
        $this->repository->create($this->userId, 'https://push.example/a', 'auth1', 'p256dh1');
        $this->repository->create($this->userId, 'https://push.example/b', 'auth2', 'p256dh2');

        $this->repository->deleteAllForUser($this->userId);

        $this->assertSame([], $this->repository->findByUserAccountId($this->userId));
    }

    public function testFindByUserAccountIdOnlyReturnsThatUsersDevices(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc2', 'idx2']);
        $otherUserId = (int) $this->pdo->lastInsertId();

        $this->repository->create($this->userId, 'https://push.example/mine', 'auth1', 'p256dh1');
        $this->repository->create($otherUserId, 'https://push.example/theirs', 'auth2', 'p256dh2');

        $mine = $this->repository->findByUserAccountId($this->userId);
        $this->assertCount(1, $mine);
        $this->assertSame('https://push.example/mine', $mine[0]->endpoint);
    }
}
