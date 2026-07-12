<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Security\EncryptionService;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class PasswordAuthMethodTest extends TestCase
{
    private PasswordAuthMethod $authMethod;
    private UserAccountRepository $userRepo;
    private EncryptionService $encryption;
    private LoginThrottler $throttler;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $this->encryption = new EncryptionService(random_bytes(32), random_bytes(32));

        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->throttler = new LoginThrottler($connection);
        $this->authMethod = new PasswordAuthMethod($this->userRepo, $this->encryption, $this->throttler);
    }

    public function testSuccessfulLoginWithCorrectEmailAndPassword(): void
    {
        $account = $this->userRepo->create('user@example.com');
        $hash = password_hash('SecurePass123', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        $result = $this->authMethod->attempt([
            'email' => 'user@example.com',
            'password' => 'SecurePass123',
        ]);

        $this->assertNotNull($result['account']);
        $this->assertSame($account->id, $result['account']->id);
        $this->assertSame(0, $result['locked_seconds']);
    }

    public function testFailedLoginWithWrongPasswordReturnsNull(): void
    {
        $account = $this->userRepo->create('user2@example.com');
        $hash = password_hash('CorrectPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        $result = $this->authMethod->attempt([
            'email' => 'user2@example.com',
            'password' => 'WrongPassword',
        ]);

        $this->assertNull($result['account']);
        $this->assertSame(0, $result['locked_seconds']);
    }

    public function testFailedLoginWithUnknownEmailReturnsNull(): void
    {
        $result = $this->authMethod->attempt([
            'email' => 'nonexistent@example.com',
            'password' => 'SomePassword',
        ]);

        $this->assertNull($result['account']);
        $this->assertSame(0, $result['locked_seconds']);
    }

    public function testLoginFailsWhenAccountHasNoPasswordSet(): void
    {
        $this->userRepo->create('nopwd@example.com');

        $result = $this->authMethod->attempt([
            'email' => 'nopwd@example.com',
            'password' => 'AnyPassword',
        ]);

        $this->assertNull($result['account']);
        $this->assertSame(0, $result['locked_seconds']);
    }

    public function testLockoutAfterMultipleFailures(): void
    {
        $account = $this->userRepo->create('locked@example.com');
        $hash = password_hash('RealPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        $blindIndex = $this->encryption->blindIndex('locked@example.com');

        // Insert 5 failures directly
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email_blind_index, attempted_at) VALUES (?, ?)');
            $stmt->execute([$blindIndex, $now]);
        }

        $result = $this->authMethod->attempt([
            'email' => 'locked@example.com',
            'password' => 'RealPassword',
        ]);

        $this->assertNull($result['account']);
        $this->assertGreaterThan(0, $result['locked_seconds']);
    }

    public function testLockoutClearsAfterSuccessfulLogin(): void
    {
        $account = $this->userRepo->create('recover@example.com');
        $hash = password_hash('MyPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($account->id, $hash);

        $blindIndex = $this->encryption->blindIndex('recover@example.com');

        // Insert 3 failures (under threshold)
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        for ($i = 0; $i < 3; $i++) {
            $stmt = $this->pdo->prepare('INSERT INTO login_attempts (email_blind_index, attempted_at) VALUES (?, ?)');
            $stmt->execute([$blindIndex, $now]);
        }

        // Successful login should clear failures
        $result = $this->authMethod->attempt([
            'email' => 'recover@example.com',
            'password' => 'MyPassword',
        ]);

        $this->assertNotNull($result['account']);

        // Verify failures are cleared
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
