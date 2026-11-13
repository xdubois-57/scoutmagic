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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

    public function testDeleteAllByEndpointRemovesEveryAccountsRowForThatEndpointOnly(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc2', 'idx2']);
        $otherUserId = (int) $this->pdo->lastInsertId();

        $this->repository->create($this->userId, 'https://push.example/shared', 'auth1', 'p256dh1');
        $this->repository->create($otherUserId, 'https://push.example/shared', 'auth2', 'p256dh2');
        $this->repository->create($otherUserId, 'https://push.example/kept', 'auth3', 'p256dh3');

        $removed = $this->repository->deleteAllByEndpoint('https://push.example/shared');

        $this->assertSame(2, $removed);
        $this->assertSame([], $this->repository->findByUserAccountId($this->userId));
        $kept = $this->repository->findByUserAccountId($otherUserId);
        $this->assertCount(1, $kept);
        $this->assertSame('https://push.example/kept', $kept[0]->endpoint);
    }

    public function testDeleteAllByEndpointOfAnUnknownEndpointRemovesNothing(): void
    {
        $this->repository->create($this->userId, 'https://push.example/a', 'auth1', 'p256dh1');

        $this->assertSame(0, $this->repository->deleteAllByEndpoint('https://push.example/unknown'));
        $this->assertCount(1, $this->repository->findByUserAccountId($this->userId));
    }

    /**
     * The tag a push payload carries (issue #1123): stable for one
     * account, different between accounts, and never the id itself.
     */
    public function testRecipientTagIsAStableOpaqueHmacPerAccount(): void
    {
        $tag = $this->repository->recipientTag(42);

        $this->assertSame($tag, $this->repository->recipientTag(42));
        $this->assertNotSame($tag, $this->repository->recipientTag(43));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tag);
        $this->assertNotSame(hash('sha256', '42'), $tag, 'A keyless hash of the id could be reversed by trying ids.');

        $otherKey = new PushSubscriptionRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('c', 32))
        );
        $this->assertNotSame($tag, $otherKey->recipientTag(42), 'The tag is keyed by the installation\'s secret.');

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->assertNotSame(
            $encryption->blindIndex('42', 'push_endpoint'),
            $tag,
            'The tag has its own blind-index domain.'
        );
    }
}
