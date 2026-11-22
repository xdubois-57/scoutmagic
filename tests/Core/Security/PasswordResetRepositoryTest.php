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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $this->assertTrue($this->repo->markUsed($id));

        $token = $this->repo->findById($id);
        $this->assertNotNull($token);
        $this->assertTrue($token->used);
    }

    /**
     * Issue #1257: of two requests holding the same link, one consumes it
     * and the other is told it came too late — the claim is the statement,
     * not a check that precedes it.
     */
    public function testMarkUsedClaimsATokenOnlyOnce(): void
    {
        $id = $this->repo->create(str_repeat('c', 64), 'hash', new \DateTimeImmutable('+60 minutes'));

        $this->assertTrue($this->repo->markUsed($id));
        $this->assertFalse($this->repo->markUsed($id));
        $this->assertFalse($this->repo->markUsed($id + 1000), 'An unknown token is never claimed.');
    }

    public function testMarkUsedRefusesAnExpiredToken(): void
    {
        $id = $this->repo->create(str_repeat('c', 64), 'hash', new \DateTimeImmutable('-1 minute'));

        $this->assertFalse($this->repo->markUsed($id));
        $this->assertFalse($this->repo->findById($id)?->used);
    }

    /**
     * Review of #1374: the time the claim is judged against is taken once the
     * row is locked — in the transaction that holds the lock — not before the
     * statement that may have to wait for it.
     */
    public function testMarkUsedReadsTheClockOnceTheRowIsLocked(): void
    {
        $inTransactionWhenRead = [];
        $repo = new PasswordResetRepository($this->pdo, function () use (&$inTransactionWhenRead): \DateTimeImmutable {
            $inTransactionWhenRead[] = $this->pdo->inTransaction();

            return new \DateTimeImmutable();
        });
        $id = $repo->create(str_repeat('c', 64), 'hash', new \DateTimeImmutable('+60 minutes'));

        $this->assertTrue($repo->markUsed($id));
        $this->assertSame([true], $inTransactionWhenRead);
        $this->assertFalse($this->pdo->inTransaction(), 'and the transaction it opened is closed');
    }

    /** The expiry is compared with the time read at the write, however long ago the request began. */
    public function testMarkUsedJudgesTheExpiryAtTheTimeOfTheWrite(): void
    {
        $at = new \DateTimeImmutable('2030-01-01 12:00:00');
        $repo = new PasswordResetRepository($this->pdo, static fn(): \DateTimeImmutable => $at->modify('+2 minutes'));
        $stillLive = $repo->create(str_repeat('c', 64), 'a', $at->modify('+3 minutes'));
        $lapsed = $repo->create(str_repeat('d', 64), 'b', $at->modify('+1 minute'));

        $this->assertTrue($repo->markUsed($stillLive));
        $this->assertFalse($repo->markUsed($lapsed), 'lapsed before the write: not consumed');
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

    /**
     * Issue #1257: a token that was used is as dead as an expired one, but
     * countRecentByEmail() counts the last hour's rows whatever became of
     * them — purging a spent token at once would hand its address a fresh
     * request.
     */
    public function testDeleteExpiredAlsoRemovesUsedTokensOnceTheRateLimitHourIsOver(): void
    {
        $oldUsed = $this->insert('-3 hours', '-2 hours', used: true);
        $oldExpired = $this->insert('-3 hours', '-2 hours', used: false);
        $recentUsed = $this->insert('-10 minutes', '+50 minutes', used: true);
        $expiredPastItsHour = $this->insert('-61 minutes', '-1 minute', used: false);
        $live = $this->insert('-5 minutes', '+55 minutes', used: false);

        $deleted = $this->repo->deleteExpired();

        $remaining = array_map('intval', $this->pdo
            ->query('SELECT id FROM password_reset_tokens ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(3, $deleted);
        $this->assertNotContains($oldUsed, $remaining);
        $this->assertNotContains($oldExpired, $remaining);
        $this->assertContains($recentUsed, $remaining, 'A used token still counts against the hourly limit.');
        $this->assertContains($live, $remaining);
        $this->assertNotContains($expiredPastItsHour, $remaining, 'Expired and past its hour: gone.');
    }

    private function insert(string $createdAt, string $expiresAt, bool $used): int
    {
        $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at, used, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            str_repeat('i', 64),
            'hash',
            (new \DateTimeImmutable($expiresAt))->format('Y-m-d H:i:s'),
            $used ? 1 : 0,
            (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
