<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\MagicLinkRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MagicLinkRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MagicLinkRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE magic_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email_blind_index CHAR(64) NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                used BOOLEAN NOT NULL DEFAULT 0,
                confirmed_at DATETIME,
                match_code_hash VARCHAR(255),
                match_attempts INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->repo = new MagicLinkRepository($this->pdo);
    }

    public function testCreateReturnsId(): void
    {
        $id = $this->repo->create(
            str_repeat('a', 64),
            '$2y$10$hash',
            new \DateTimeImmutable('+15 minutes')
        );

        $this->assertSame(1, $id);
    }

    public function testFindByIdReturnsRecord(): void
    {
        $blindIndex = str_repeat('b', 64);
        $hash = password_hash('test_token', PASSWORD_DEFAULT);
        $expiry = new \DateTimeImmutable('+15 minutes');

        $id = $this->repo->create($blindIndex, $hash, $expiry);
        $record = $this->repo->findById($id);

        $this->assertNotNull($record);
        $this->assertSame($id, $record->id);
        $this->assertSame($blindIndex, $record->emailBlindIndex);
        $this->assertSame($hash, $record->tokenHash);
        $this->assertFalse($record->used);
        $this->assertNull($record->confirmedAt);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $record = $this->repo->findById(999);
        $this->assertNull($record);
    }

    public function testMarkUsedSetsUsedAndConfirmedAt(): void
    {
        $id = $this->repo->create(
            str_repeat('c', 64),
            'hash',
            new \DateTimeImmutable('+15 minutes')
        );

        $this->repo->markUsed($id);

        $record = $this->repo->findById($id);
        $this->assertNotNull($record);
        $this->assertTrue($record->used);
        $this->assertNotNull($record->confirmedAt);
    }

    public function testCountRecentByEmail(): void
    {
        $blindIndex = str_repeat('d', 64);

        // Create 3 links
        for ($i = 0; $i < 3; $i++) {
            $this->repo->create($blindIndex, 'hash' . $i, new \DateTimeImmutable('+15 minutes'));
        }

        $count = $this->repo->countRecentByEmail($blindIndex, 3600);
        $this->assertSame(3, $count);

        // Different blind index
        $count = $this->repo->countRecentByEmail(str_repeat('e', 64), 3600);
        $this->assertSame(0, $count);
    }

    public function testDeleteExpiredRemovesOldRecords(): void
    {
        // Create an expired link (in the past)
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([str_repeat('f', 64), 'hash', '2020-01-01 00:00:00', '2020-01-01 00:00:00']);

        // Create a valid link (in the future)
        $this->repo->create(str_repeat('g', 64), 'hash2', new \DateTimeImmutable('+1 hour'));

        $deleted = $this->repo->deleteExpired();

        $this->assertSame(1, $deleted);

        // The valid link should still exist
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM magic_links');
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * Issue #1099: an expired link still inside the rate-limit hour is
     * kept — countRecentByEmail() counts it, and deleting it would hand
     * the address a fresh allowance of links.
     */
    public function testAnExpiredLinkStillCountedByTheRateLimitIsKept(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO magic_links (email_blind_index, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            str_repeat('h', 64),
            'hash',
            (new \DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable('-30 minutes'))->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(0, $this->repo->deleteExpired());
        $this->assertSame(1, $this->repo->countRecentByEmail(str_repeat('h', 64)));
    }

    public function testMatchAttemptsAreReservedUpToTheCapAndInvalidateSpendsTheLinkWithoutConfirmingIt(): void
    {
        $id = $this->repo->create(str_repeat('i', 64), 'hash', new \DateTimeImmutable('+15 minutes'), 'code-hash');

        $this->assertSame('code-hash', $this->repo->findById($id)?->matchCodeHash);
        $this->assertSame(1, $this->repo->reserveMatchAttempt($id, 3));
        $this->assertSame(2, $this->repo->reserveMatchAttempt($id, 3));
        $this->assertSame(3, $this->repo->reserveMatchAttempt($id, 3));
        // The cap is enforced by the UPDATE itself, not by the caller.
        $this->assertNull($this->repo->reserveMatchAttempt($id, 3));

        $this->repo->invalidate($id);
        $record = $this->repo->findById($id);
        $this->assertNotNull($record);
        $this->assertTrue($record->used);
        $this->assertNull($record->confirmedAt);
        $this->assertSame(3, $record->matchAttempts);
    }

    public function testASpentLinkHasNoAttemptLeftAndCannotBeClaimedAgain(): void
    {
        $id = $this->repo->create(str_repeat('j', 64), 'hash', new \DateTimeImmutable('+15 minutes'), 'code-hash');

        $this->assertTrue($this->repo->markUsed($id));
        // A second confirmation racing the first one claims nothing.
        $this->assertFalse($this->repo->markUsed($id));
        $this->assertNull($this->repo->reserveMatchAttempt($id, 3));

        // Nor does a late invalidation un-confirm a link that was confirmed.
        $this->repo->invalidate($id);
        $this->assertNotNull($this->repo->findById($id)?->confirmedAt);
    }
}
