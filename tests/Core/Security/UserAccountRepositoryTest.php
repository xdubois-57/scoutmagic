<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
class UserAccountRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private UserAccountRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_encrypted BLOB NOT NULL,
                email_blind_index CHAR(64) NOT NULL,
                first_name_encrypted BLOB,
                last_name_encrypted BLOB,
                password_hash VARCHAR(255),
                is_super_admin BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at DATETIME
            )
        ');

        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );

        $this->repo = new UserAccountRepository($this->pdo, $this->encryption);
    }

    public function testCreateStoresEncryptedEmailAndBlindIndex(): void
    {
        $account = $this->repo->create('Test@Example.com', true);

        $this->assertSame(1, $account->id);
        $this->assertSame('test@example.com', $account->email);
        $this->assertTrue($account->isSuperAdmin);

        // Verify that raw DB content is encrypted
        $stmt = $this->pdo->query('SELECT email_encrypted, email_blind_index FROM user_accounts WHERE id = 1');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // email_encrypted should NOT be readable as plaintext
        $this->assertNotSame('test@example.com', $row['email_encrypted']);
        // blind index should be a hex string (64 chars)
        $this->assertSame(64, strlen($row['email_blind_index']));
    }

    public function testFindByEmailFindsAccountViaBlindIndex(): void
    {
        $this->repo->create('user@test.com');

        $found = $this->repo->findByEmail('user@test.com');

        $this->assertNotNull($found);
        $this->assertSame('user@test.com', $found->email);
    }

    public function testFindByEmailIsCaseInsensitive(): void
    {
        $this->repo->create('User@Test.Com');

        $found = $this->repo->findByEmail('user@test.com');
        $this->assertNotNull($found);
    }

    public function testFindByEmailReturnsNullForUnknownEmail(): void
    {
        $found = $this->repo->findByEmail('unknown@example.com');
        $this->assertNull($found);
    }

    public function testFindByIdReturnsAccountWithDecryptedFields(): void
    {
        $created = $this->repo->create('admin@test.com', true);

        $found = $this->repo->findById($created->id);

        $this->assertNotNull($found);
        $this->assertSame($created->id, $found->id);
        $this->assertSame('admin@test.com', $found->email);
        $this->assertTrue($found->isSuperAdmin);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $found = $this->repo->findById(999);
        $this->assertNull($found);
    }

    public function testUpdateLastLogin(): void
    {
        $account = $this->repo->create('user@test.com');
        $this->assertNull($account->lastLoginAt);

        $this->repo->updateLastLogin($account->id);

        $found = $this->repo->findById($account->id);
        // SQLite may not support NOW() the same way, just verify it was updated
        $stmt = $this->pdo->query('SELECT last_login_at FROM user_accounts WHERE id = ' . $account->id);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($row['last_login_at']);
    }

    public function testFindByBlindIndex(): void
    {
        $this->repo->create('find@test.com');
        $blindIndex = $this->encryption->blindIndex('find@test.com');

        $found = $this->repo->findByBlindIndex($blindIndex);

        $this->assertNotNull($found);
        $this->assertSame('find@test.com', $found->email);
    }

    public function testFindFirstSuperAdminReturnsEarliestSuperAdmin(): void
    {
        $this->repo->create('regular@test.com', false);
        $firstAdmin = $this->repo->create('first-admin@test.com', true);
        $this->repo->create('second-admin@test.com', true);

        $found = $this->repo->findFirstSuperAdmin();

        $this->assertNotNull($found);
        $this->assertSame($firstAdmin->id, $found->id);
        $this->assertSame('first-admin@test.com', $found->email);
    }

    public function testFindFirstSuperAdminReturnsNullWhenNoneExists(): void
    {
        $this->repo->create('regular@test.com', false);

        $found = $this->repo->findFirstSuperAdmin();

        $this->assertNull($found);
    }
}
