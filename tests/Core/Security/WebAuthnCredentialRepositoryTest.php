<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\WebAuthnCredentialRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class WebAuthnCredentialRepositoryTest extends TestCase
{
    private WebAuthnCredentialRepository $repo;
    private \PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new WebAuthnCredentialRepository($this->pdo);

        // Create a user account
        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES ('enc', 'blind1', 0)");
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateStoresCredential(): void
    {
        $credentialId = random_bytes(32);
        $publicKey = random_bytes(64);

        $id = $this->repo->create($this->userId, $credentialId, $publicKey, 'Mon iPhone');

        $this->assertGreaterThan(0, $id);
    }

    public function testFindByCredentialIdRetrievesStored(): void
    {
        $credentialId = random_bytes(32);
        $publicKey = random_bytes(64);

        $this->repo->create($this->userId, $credentialId, $publicKey, 'Mon iPhone');

        $result = $this->repo->findByCredentialId($credentialId);

        $this->assertNotNull($result);
        $this->assertSame($this->userId, (int) $result['user_account_id']);
        $this->assertSame('Mon iPhone', $result['device_label']);
    }

    public function testFindByCredentialIdReturnsNullForUnknown(): void
    {
        $result = $this->repo->findByCredentialId('nonexistent');
        $this->assertNull($result);
    }

    public function testFindByUserAccountIdListsCredentials(): void
    {
        $this->repo->create($this->userId, random_bytes(32), random_bytes(64), 'Key 1');
        $this->repo->create($this->userId, random_bytes(32), random_bytes(64), 'Key 2');

        $results = $this->repo->findByUserAccountId($this->userId);

        $this->assertCount(2, $results);
    }

    public function testDeleteRemovesCredential(): void
    {
        $credentialId = random_bytes(32);
        $id = $this->repo->create($this->userId, $credentialId, random_bytes(64), 'To Delete');

        $this->repo->delete($id);

        $result = $this->repo->findByCredentialId($credentialId);
        $this->assertNull($result);
    }

    public function testUpdateSignCount(): void
    {
        $credentialId = random_bytes(32);
        $id = $this->repo->create($this->userId, $credentialId, random_bytes(64), 'Key');

        $this->repo->updateSignCount($id, 42);

        $result = $this->repo->findByCredentialId($credentialId);
        $this->assertSame(42, (int) $result['sign_count']);
    }

    public function testUpdateLastUsed(): void
    {
        $credentialId = random_bytes(32);
        $id = $this->repo->create($this->userId, $credentialId, random_bytes(64), 'Key');

        $this->repo->updateLastUsed($id);

        $result = $this->repo->findByCredentialId($credentialId);
        $this->assertNotNull($result['last_used_at']);
    }

    public function testCountByUserAccountId(): void
    {
        $this->repo->create($this->userId, random_bytes(32), random_bytes(64), 'Key 1');
        $this->repo->create($this->userId, random_bytes(32), random_bytes(64), 'Key 2');
        $this->repo->create($this->userId, random_bytes(32), random_bytes(64), 'Key 3');

        $count = $this->repo->countByUserAccountId($this->userId);
        $this->assertSame(3, $count);
    }
}
