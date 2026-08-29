<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
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
                sessions_valid_from DATETIME,
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
        $blindIndex = $this->encryption->blindIndex('find@test.com', 'email');

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

    public function testFindAllIdsReturnsEveryAccountIdInOrder(): void
    {
        $a = $this->repo->create('a@test.com');
        $b = $this->repo->create('b@test.com');
        $c = $this->repo->create('c@test.com');

        $this->assertSame([$a->id, $b->id, $c->id], $this->repo->findAllIds());
    }

    public function testFindAllIdsReturnsEmptyArrayWhenNoAccounts(): void
    {
        $this->assertSame([], $this->repo->findAllIds());
    }

    /**
     * The bug this replaced: `DELETE FROM user_accounts WHERE
     * is_super_admin = TRUE` followed by a fresh create(). The repair
     * fires when the lookup misses — and a blind-index key problem makes
     * it miss for every row at once, so a single unreadable admin used to
     * take every other super admin down with it, silently.
     */
    public function testRepairSuperAdminLeavesEveryOtherSuperAdminAlone(): void
    {
        $first = $this->repo->create('first-admin@test.com', true);
        $second = $this->repo->create('second-admin@test.com', true);
        $third = $this->repo->create('third-admin@test.com', true);

        // The address secrets['admin_email'] names is no longer findable:
        // its blind index was written under a key that no longer applies.
        $this->pdo->exec(
            "UPDATE user_accounts SET email_blind_index = 'stale-index' WHERE id = " . $third->id
        );
        $this->assertNull($this->repo->findByEmail('third-admin@test.com'));

        $this->repo->repairSuperAdmin('third-admin@test.com');

        $survivors = $this->repo->findAllIds();
        $this->assertContains($first->id, $survivors);
        $this->assertContains($second->id, $survivors);
        $this->assertSame('first-admin@test.com', $this->repo->findById($first->id)?->email);
        $this->assertSame('second-admin@test.com', $this->repo->findById($second->id)?->email);
    }

    public function testRepairSuperAdminRekeysTheExistingRowRatherThanAddingOne(): void
    {
        $admin = $this->repo->create('admin@test.com', true);
        $this->pdo->exec(
            "UPDATE user_accounts SET email_blind_index = 'stale-index' WHERE id = " . $admin->id
        );

        $repaired = $this->repo->repairSuperAdmin('admin@test.com');

        $this->assertSame($admin->id, $repaired->id);
        $this->assertCount(1, $this->repo->findAllIds());

        // Findable again, which is what the repair exists to restore.
        $found = $this->repo->findByEmail('admin@test.com');
        $this->assertNotNull($found);
        $this->assertSame($admin->id, $found->id);
        $this->assertTrue($found->isSuperAdmin);
    }

    public function testRepairSuperAdminCreatesTheAccountWhenNoRowMatches(): void
    {
        $other = $this->repo->create('other-admin@test.com', true);

        $created = $this->repo->repairSuperAdmin('missing-admin@test.com');

        $this->assertNotSame($other->id, $created->id);
        $this->assertSame('missing-admin@test.com', $created->email);
        $this->assertTrue($created->isSuperAdmin);

        // The existing super admin is still there, untouched.
        $this->assertSame('other-admin@test.com', $this->repo->findById($other->id)?->email);
    }

    public function testRepairSuperAdminSkipsRowsItCannotDecrypt(): void
    {
        $unreadable = $this->repo->create('unreadable-admin@test.com', true);
        $this->pdo->exec(
            "UPDATE user_accounts SET email_encrypted = 'not-decryptable' WHERE id = " . $unreadable->id
        );

        $created = $this->repo->repairSuperAdmin('admin@test.com');

        // The row nobody can read is neither claimed nor deleted: it may
        // belong to somebody else, and this code cannot tell.
        $this->assertNotSame($unreadable->id, $created->id);
        $this->assertContains($unreadable->id, $this->repo->findAllIds());
    }

    public function testRepairSuperAdminNormalizesTheAddressLikeCreateDoes(): void
    {
        $admin = $this->repo->create('Mixed@Test.com', true);
        $this->pdo->exec(
            "UPDATE user_accounts SET email_blind_index = 'stale-index' WHERE id = " . $admin->id
        );

        $repaired = $this->repo->repairSuperAdmin('MIXED@TEST.COM');

        $this->assertSame($admin->id, $repaired->id);
        $this->assertNotNull($this->repo->findByEmail('mixed@test.com'));
    }
}
