<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\PasswordResetRepository;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors Tests\Core\Security\MagicLinkRepositoryTest's structure. Unlike
 * that suite, every case here passes against the SQLite in-memory database
 * used directly (no MySQL-only NOW()/DATE_SUB() — see
 * Core\Security\PasswordResetRepository's own doc comment).
 *
 * @group database
 */
class PasswordResetRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PasswordResetRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_blind_index CHAR(64) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used BOOLEAN NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->repo = new PasswordResetRepository($this->pdo);
    }

    public function testCreateReturnsId(): void
    {
        $id = $this->repo->create(str_repeat('a', 64), '$2y$10$hash', new \DateTimeImmutable('+60 minutes'));
        $this->assertSame(1, $id);
    }

    public function testFindByIdReturnsToken(): void
    {
        $blindIndex = str_repeat('b', 64);
        $hash = password_hash('test_token', PASSWORD_DEFAULT);
        $id = $this->repo->create($blindIndex, $hash, new \DateTimeImmutable('+60 minutes'));

        $token = $this->repo->findById($id);

        $this->assertNotNull($token);
        $this->assertSame($id, $token->id);
        $this->assertSame($blindIndex, $token->emailBlindIndex);
        $this->assertSame($hash, $token->tokenHash);
        $this->assertFalse($token->used);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testMarkUsedFlipsUsedFlag(): void
    {
        $id = $this->repo->create(str_repeat('c', 64), 'hash', new \DateTimeImmutable('+60 minutes'));

        $this->repo->markUsed($id);

        $token = $this->repo->findById($id);
        $this->assertNotNull($token);
        $this->assertTrue($token->used);
    }

    public function testCountRecentByEmail(): void
    {
        $blindIndex = str_repeat('d', 64);
        for ($i = 0; $i < 3; $i++) {
            $this->repo->create($blindIndex, 'hash' . $i, new \DateTimeImmutable('+60 minutes'));
        }

        $this->assertSame(3, $this->repo->countRecentByEmail($blindIndex, 3600));
        $this->assertSame(0, $this->repo->countRecentByEmail(str_repeat('e', 64), 3600));
    }

    public function testCountRecentByEmailExcludesOlderThanWindow(): void
    {
        $blindIndex = str_repeat('f', 64);
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$blindIndex, 'hash', '2020-01-01 01:00:00', '2020-01-01 00:00:00']);

        $this->assertSame(0, $this->repo->countRecentByEmail($blindIndex, 3600));
    }

    public function testDeleteExpiredRemovesOnlyExpiredRecords(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([str_repeat('g', 64), 'hash', '2020-01-01 00:00:00', '2020-01-01 00:00:00']);

        $this->repo->create(str_repeat('h', 64), 'hash2', new \DateTimeImmutable('+1 hour'));

        $deleted = $this->repo->deleteExpired();

        $this->assertSame(1, $deleted);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }
}
